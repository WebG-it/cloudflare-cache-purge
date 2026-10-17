<?php

/**
 * @package     WebG.Plugin
 * @subpackage  Content.cfpurge
 *
 * @copyright   (C) 2026 WebG di Marco Galassi <https://www.webg.it>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace WebG\Plugin\Content\Cfpurge\Service;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use Joomla\Event\Event;

/**
 * What to purge for each Joomla event. Shared by the content plugin (articles, categories,
 * menu items, tags, custom fields, state changes) and the system plugin (site modules and
 * the Joomla cache clean), which are the only places where those events can be heard.
 *
 * @since  2.0.0
 */
final class Events
{
    /** @var array<int, string|null>  Address of an article before it is saved. */
    private static array $before = [];

    /**
     * Event argument by name (Joomla 5/6 events) or by position (older callers).
     *
     * @param   Event   $event     The event.
     * @param   string  $name      Argument name.
     * @param   int     $position  Argument position.
     * @param   mixed   $default   Value when missing.
     *
     * @return  mixed
     *
     * @since   2.0.0
     */
    public static function arg(Event $event, string $name, int $position, mixed $default = null): mixed
    {
        $arguments = $event->getArguments();

        if (\array_key_exists($name, $arguments)) {
            return $arguments[$name];
        }

        return $arguments[$position] ?? $default;
    }

    /**
     * Remembers the current address of an article that is about to change (targeted mode),
     * so the old address is purged too when the alias or the category change.
     *
     * @param   Config  $config   The plugin settings.
     * @param   string  $context  The event context.
     * @param   object  $table    The table being saved.
     * @param   bool    $isNew    New record.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public static function beforeSave(Config $config, string $context, object $table, bool $isNew): void
    {
        if ($isNew || Contexts::kind($context) !== Contexts::ARTICLE || $config->mode() !== Config::MODE_TARGETED || !$config->flag('on_update')) {
            return;
        }

        $id = (int) ($table->id ?? 0);

        if ($id <= 0) {
            return;
        }

        try {
            $db    = Factory::getContainer()->get(DatabaseInterface::class);
            $query = Db::query($db)
                ->select($db->quoteName(['catid', 'language', 'alias']))
                ->from($db->quoteName('#__content'))
                ->where($db->quoteName('id') . ' = :id')
                ->bind(':id', $id, ParameterType::INTEGER);
            $row = $db->setQuery($query)->loadObject();

            self::$before[$id] = $row ? Targets::article($id, (int) $row->catid, (string) $row->language, (string) $row->alias) : null;
        } catch (\Throwable $e) {
            self::$before[$id] = null;
        }
    }

    /**
     * An item has been saved.
     *
     * @param   Config  $config   The plugin settings.
     * @param   string  $context  The event context.
     * @param   object  $table    The saved table.
     * @param   bool    $isNew    New record.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public static function afterSave(Config $config, string $context, object $table, bool $isNew): void
    {
        $kind = Contexts::kind($context);

        if ($kind === null || !self::isSiteItem($kind, $table)) {
            return;
        }

        $enabled = $kind === Contexts::ARTICLE
            ? $config->flag($isNew ? 'on_create' : 'on_update')
            : $config->flag(Contexts::param($kind));

        if (!$enabled) {
            return;
        }

        if ($kind === Contexts::ARTICLE && $config->mode() === Config::MODE_TARGETED) {
            $id   = (int) ($table->id ?? 0);
            $urls = self::articleUrls($config, $id, (int) ($table->catid ?? 0), (string) ($table->language ?? '*'), (string) ($table->alias ?? ''));

            if ($urls !== null && isset(self::$before[$id])) {
                $urls[] = self::$before[$id];
            }

            unset(self::$before[$id]);

            if ($urls !== null) {
                Purger::queueUrls($config, $urls, $kind);

                return;
            }
        }

        Purger::queueEverything($config, $kind);
    }

    /**
     * Published, unpublished, archived, trashed.
     *
     * @param   Config  $config   The plugin settings.
     * @param   string  $context  The event context.
     * @param   array   $pks      The ids.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public static function changeState(Config $config, string $context, array $pks): void
    {
        $kind = Contexts::kind($context);
        $pks  = array_values(array_filter(array_map('intval', $pks)));

        if ($kind === null || $pks === []) {
            return;
        }

        if (!$config->flag($kind === Contexts::ARTICLE ? 'on_state' : Contexts::param($kind))) {
            return;
        }

        if ($kind === Contexts::MODULE && !self::anySiteModule($pks)) {
            return;
        }

        if ($kind === Contexts::ARTICLE && $config->mode() === Config::MODE_TARGETED) {
            try {
                $urls = Targets::articlesById($pks);
                $urls = $urls === null ? null : array_merge($urls, self::common($config));
            } catch (\Throwable $e) {
                $urls = null;
            }

            if ($urls !== null) {
                Purger::queueUrls($config, $urls, $kind);

                return;
            }
        }

        Purger::queueEverything($config, $kind);
    }

    /**
     * An item has been deleted.
     *
     * @param   Config  $config   The plugin settings.
     * @param   string  $context  The event context.
     * @param   object  $table    The deleted table.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public static function afterDelete(Config $config, string $context, object $table): void
    {
        $kind = Contexts::kind($context);

        if ($kind === null || !self::isSiteItem($kind, $table)) {
            return;
        }

        if (!$config->flag($kind === Contexts::ARTICLE ? 'on_delete' : Contexts::param($kind))) {
            return;
        }

        if ($kind === Contexts::ARTICLE && $config->mode() === Config::MODE_TARGETED) {
            $urls = self::articleUrls($config, (int) ($table->id ?? 0), (int) ($table->catid ?? 0), (string) ($table->language ?? '*'), (string) ($table->alias ?? ''));

            if ($urls !== null) {
                Purger::queueUrls($config, $urls, $kind);

                return;
            }
        }

        Purger::queueEverything($config, $kind);
    }

    /**
     * The Joomla cache has been cleaned from System > Clear Cache.
     *
     * @param   Config  $config  The plugin settings.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public static function cacheCleared(Config $config): void
    {
        if ($config->flag('on_joomla_cache', false)) {
            Purger::queueEverything($config, 'joomla-cache');
        }
    }

    /**
     * Addresses of one article: its own, its category chain, home page and extras.
     *
     * @param   Config  $config    The plugin settings.
     * @param   int     $id        Article id.
     * @param   int     $catid     Category id.
     * @param   string  $language  Language.
     * @param   string  $alias     Article alias.
     *
     * @return  string[]|null  Null when an address cannot be worked out.
     *
     * @since   2.0.0
     */
    private static function articleUrls(Config $config, int $id, int $catid, string $language, string $alias): ?array
    {
        if ($id <= 0) {
            return null;
        }

        try {
            $url = Targets::article($id, $catid, $language, $alias);
            $cat = Targets::categories($catid, $language);

            if ($url === null || $cat === null) {
                return null;
            }

            return array_merge([$url], $cat, self::common($config));
        } catch (\Throwable $e) {
            // Whatever goes wrong while working out the addresses, the caller purges everything.
            return null;
        }
    }

    /**
     * Home page and the fixed extra addresses.
     *
     * @param   Config  $config  The plugin settings.
     *
     * @return  string[]
     *
     * @since   2.0.0
     */
    private static function common(Config $config): array
    {
        return array_merge([Targets::root()], Targets::extras($config));
    }

    /**
     * Administrator modules and menus do not change the public site.
     *
     * @param   string  $kind   The kind of item.
     * @param   object  $table  The table.
     *
     * @return  bool
     *
     * @since   2.0.0
     */
    private static function isSiteItem(string $kind, object $table): bool
    {
        return !\in_array($kind, [Contexts::MODULE, Contexts::MENU], true) || (int) ($table->client_id ?? 0) === 0;
    }

    /**
     * @param   int[]  $ids  Module ids.
     *
     * @return  bool  True when at least one is a site module.
     *
     * @since   2.0.0
     */
    private static function anySiteModule(array $ids): bool
    {
        try {
            $db    = Factory::getContainer()->get(DatabaseInterface::class);
            $query = Db::query($db)
                ->select('COUNT(*)')
                ->from($db->quoteName('#__modules'))
                ->where($db->quoteName('client_id') . ' = 0')
                ->whereIn($db->quoteName('id'), $ids, ParameterType::INTEGER);

            return (int) $db->setQuery($query)->loadResult() > 0;
        } catch (\Throwable $e) {
            return true;
        }
    }
}
