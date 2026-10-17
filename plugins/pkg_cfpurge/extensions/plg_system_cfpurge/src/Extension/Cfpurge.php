<?php

/**
 * @package     WebG.Plugin
 * @subpackage  System.cfpurge
 *
 * @copyright   (C) 2026 WebG di Marco Galassi <https://www.webg.it>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace WebG\Plugin\System\Cfpurge\Extension;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Event\Event;
use Joomla\Event\SubscriberInterface;
use WebG\Plugin\Content\Cfpurge\Service\Config;
use WebG\Plugin\Content\Cfpurge\Service\Events;

/**
 * Listens to the two kinds of events that a content plugin cannot hear because Joomla does
 * not load content plugins there: saving or deleting a site module (extension events) and
 * System > Clear Cache. It has no options of its own: everything is read from the options of
 * the "Content - Cloudflare Cache Purge" plugin, and it does nothing while that plugin is off.
 *
 * @since  2.0.0
 */
final class Cfpurge extends CMSPlugin implements SubscriberInterface
{
    /**
     * @var    boolean
     * @since  2.0.0
     */
    protected $autoloadLanguage = false;

    /**
     * @return  array
     *
     * @since   2.0.0
     */
    public static function getSubscribedEvents(): array
    {
        return [
            'onExtensionAfterSave'   => 'onExtensionAfterSave',
            'onExtensionAfterDelete' => 'onExtensionAfterDelete',
            'onAfterPurge'           => 'onAfterPurge',
        ];
    }

    /**
     * A module has been saved.
     *
     * @param   Event  $event  The event.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function onExtensionAfterSave(Event $event): void
    {
        $table  = Events::arg($event, 'subject', 1);
        $config = $this->config();

        if ($config !== null && \is_object($table)) {
            try {
                Events::afterSave($config, (string) Events::arg($event, 'context', 0, ''), $table, (bool) Events::arg($event, 'isNew', 2, false));
            } catch (\Throwable $e) {
                // A purge problem must never break the save of a module.
            }
        }
    }

    /**
     * A module has been deleted.
     *
     * @param   Event  $event  The event.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function onExtensionAfterDelete(Event $event): void
    {
        $table  = Events::arg($event, 'subject', 1);
        $config = $this->config();

        if ($config !== null && \is_object($table)) {
            try {
                Events::afterDelete($config, (string) Events::arg($event, 'context', 0, ''), $table);
            } catch (\Throwable $e) {
                // A purge problem must never break the delete of a module.
            }
        }
    }

    /**
     * System > Clear Cache: "Delete" (selected groups) or "Delete all". The "Purge expired"
     * button raises the same event but only removes expired files: it is ignored.
     *
     * @param   Event  $event  The event.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function onAfterPurge(Event $event): void
    {
        $config = $this->config();
        $app    = $this->getApplication();

        if ($config === null || !$app->isClient('administrator') || $app->getInput()->getCmd('option') !== 'com_cache') {
            return;
        }

        $task = (string) $app->getInput()->get('task', '', 'cmd');
        $task = substr(strrchr('.' . $task, '.'), 1);

        if (\in_array($task, ['delete', 'deleteAll'], true)) {
            try {
                Events::cacheCleared($config);
            } catch (\Throwable $e) {
                // Never break the Clear Cache screen.
            }
        }
    }

    /**
     * The settings of the content plugin, null when it is off or not installed.
     *
     * @return  Config|null
     *
     * @since   2.0.0
     */
    private function config(): ?Config
    {
        return class_exists(Config::class) ? Config::load() : null;
    }
}
