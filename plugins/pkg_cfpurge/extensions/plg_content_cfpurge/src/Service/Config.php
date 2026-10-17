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

use Joomla\CMS\Cache\CacheControllerFactoryInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\Database\DatabaseInterface;
use Joomla\Registry\Registry;

/**
 * Settings of the Cloudflare Cache Purge content plugin, readable from the content plugin
 * itself, from the system plugin and from the administrator module.
 *
 * @since  2.0.0
 */
final class Config
{
    public const MODE_ALL      = 'all';
    public const MODE_TARGETED = 'targeted';

    /**
     * Groups allowed to purge by hand when the option has never been saved:
     * Administrator (7) and Super Users (8).
     */
    private const DEFAULT_GROUPS = [7, 8];

    /**
     * @param   Registry  $params   The plugin parameters.
     * @param   bool      $enabled  Whether the content plugin is enabled.
     * @param   int       $id       The extension id of the content plugin.
     */
    public function __construct(private Registry $params, private bool $enabled = true, private int $id = 0)
    {
    }

    /**
     * The settings of the content plugin when it is enabled, null otherwise. Used by the
     * code that must stay silent when the plugin is switched off.
     *
     * @return  self|null
     *
     * @since   2.0.0
     */
    public static function load(): ?self
    {
        $plugin = PluginHelper::getPlugin('content', 'cfpurge');

        if (!\is_object($plugin)) {
            return null;
        }

        return new self(new Registry($plugin->params ?? ''), true, (int) ($plugin->id ?? 0));
    }

    /**
     * The settings of the content plugin read from the database, also when it is disabled.
     * Null when the plugin is not installed.
     *
     * @return  self|null
     *
     * @since   2.0.0
     */
    public static function loadFromDatabase(): ?self
    {
        $db    = Factory::getContainer()->get(DatabaseInterface::class);
        $query = Db::query($db)
            ->select($db->quoteName(['extension_id', 'enabled', 'params']))
            ->from($db->quoteName('#__extensions'))
            ->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
            ->where($db->quoteName('folder') . ' = ' . $db->quote('content'))
            ->where($db->quoteName('element') . ' = ' . $db->quote('cfpurge'));

        $row = $db->setQuery($query)->loadObject();

        if (!$row) {
            return null;
        }

        return new self(new Registry($row->params ?? ''), (int) $row->enabled === 1, (int) $row->extension_id);
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function getExtensionId(): int
    {
        return $this->id;
    }

    public function zoneId(): string
    {
        return trim((string) $this->params->get('zone_id', ''));
    }

    public function token(): string
    {
        // The token goes into a header: strip control characters (header injection).
        return (string) preg_replace('/[[:cntrl:]]+/', '', trim((string) $this->params->get('api_token', '')));
    }

    public function isConfigured(): bool
    {
        return $this->zoneId() !== '' && $this->token() !== '';
    }

    public function mode(): string
    {
        return $this->params->get('purge_mode', self::MODE_ALL) === self::MODE_TARGETED ? self::MODE_TARGETED : self::MODE_ALL;
    }

    /**
     * An on/off switch of the events tab (on unless told otherwise).
     *
     * @param   string  $name     The parameter name.
     * @param   bool    $default  Value when the parameter was never saved.
     *
     * @return  bool
     *
     * @since   2.0.0
     */
    public function flag(string $name, bool $default = true): bool
    {
        return (int) $this->params->get($name, $default ? 1 : 0) === 1;
    }

    /**
     * The fixed extra addresses of the targeted mode, one per line, as typed.
     *
     * @return  string[]
     *
     * @since   2.0.0
     */
    public function extraUrls(): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\R/', (string) $this->params->get('extra_urls', '')) ?: [])));
    }

    /**
     * Groups allowed to use the dashboard buttons (Super Users always are).
     *
     * @return  int[]
     *
     * @since   2.0.0
     */
    public function allowedGroups(): array
    {
        $groups = array_values(array_filter(array_map('intval', (array) $this->params->get('allowed_groups', []))));

        return $groups !== [] ? $groups : self::DEFAULT_GROUPS;
    }

    /**
     * Short fingerprint of zone and token: the saved verification only counts while it
     * matches. It is not reversible and is not logged.
     *
     * @return  string
     *
     * @since   2.0.0
     */
    public function fingerprint(?string $zone = null, ?string $token = null): string
    {
        return substr(hash('sha256', ($zone ?? $this->zoneId()) . "\0" . ($token ?? $this->token())), 0, 16);
    }

    /**
     * The saved result of the last connection check.
     *
     * @return  array
     *
     * @since   2.0.0
     */
    public function verifyState(): array
    {
        $state = json_decode((string) $this->params->get('verify_state', ''), true);

        return \is_array($state) ? $state : [];
    }

    /**
     * True when the saved check succeeded for the zone and token that are configured now.
     *
     * @return  bool
     *
     * @since   2.0.0
     */
    public function isVerified(): bool
    {
        $state = $this->verifyState();

        return $this->isConfigured()
            && !empty($state['ok'])
            && isset($state['hash'])
            && hash_equals((string) $state['hash'], $this->fingerprint());
    }

    /**
     * Saves the result of a connection check in the plugin parameters (no extra table).
     *
     * @param   array  $state  The state to store.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function saveVerifyState(array $state): void
    {
        if ($this->id <= 0) {
            return;
        }

        $this->params->set('verify_state', json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $db    = Factory::getContainer()->get(DatabaseInterface::class);
        $json  = $this->params->toString();
        $id    = $this->id;
        $query = Db::query($db)
            ->update($db->quoteName('#__extensions'))
            ->set($db->quoteName('params') . ' = :params')
            ->where($db->quoteName('extension_id') . ' = :id')
            ->bind(':params', $json)
            ->bind(':id', $id, \Joomla\Database\ParameterType::INTEGER);

        $db->setQuery($query)->execute();

        // The plugin list is cached when caching is on: drop it so the new state is read.
        try {
            $factory = Factory::getContainer()->get(CacheControllerFactoryInterface::class);

            foreach ([JPATH_ADMINISTRATOR . '/cache', JPATH_SITE . '/cache'] as $base) {
                $factory->createCacheController('callback', ['defaultgroup' => 'com_plugins', 'cachebase' => $base])->clean();
            }
        } catch (\Throwable $e) {
            // Nothing to clean: ignore.
        }
    }
}
