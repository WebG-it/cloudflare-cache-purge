<?php

/**
 * @package     WebG.Plugin
 * @subpackage  Content.cfpurge
 *
 * @copyright   (C) 2026 WebG di Marco Galassi <https://www.webg.it>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace WebG\Plugin\Content\Cfpurge\Extension;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use Joomla\CMS\Language\Text;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Session\Session;
use Joomla\Event\Event;
use Joomla\Event\SubscriberInterface;
use WebG\Plugin\Content\Cfpurge\Service\Access;
use WebG\Plugin\Content\Cfpurge\Service\Config;
use WebG\Plugin\Content\Cfpurge\Service\Diagnostics;
use WebG\Plugin\Content\Cfpurge\Service\Events;
use WebG\Plugin\Content\Cfpurge\Service\LastPurge;
use WebG\Plugin\Content\Cfpurge\Service\PurgeLog;
use WebG\Plugin\Content\Cfpurge\Service\Purger;
use WebG\Plugin\Content\Cfpurge\Service\Targets;

/**
 * Purges the Cloudflare cache when the public site changes (articles, categories, menu
 * items, custom fields, tags, state changes) and answers the dashboard module and the
 * "Verify connection" button through com_ajax. Best effort: a failed purge never blocks a
 * save, the outcome is written to the log and shown in the dashboard.
 *
 * @since  1.0.0
 */
final class Cfpurge extends CMSPlugin implements SubscriberInterface
{
    /**
     * @var    boolean
     * @since  1.0.0
     */
    protected $autoloadLanguage = false;

    /**
     * @return  array
     *
     * @since   1.0.0
     */
    public static function getSubscribedEvents(): array
    {
        return [
            'onContentBeforeSave'  => 'onBeforeSave',
            'onContentAfterSave'   => 'onAfterSave',
            'onContentChangeState' => 'onChangeState',
            'onContentAfterDelete' => 'onAfterDelete',
            'onAjaxCfpurge'        => 'onAjax',
        ];
    }

    /**
     * @param   Event  $event  The event.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function onBeforeSave(Event $event): void
    {
        $table = Events::arg($event, 'subject', 1);

        if (\is_object($table)) {
            $this->guard(fn () => Events::beforeSave($this->config(), (string) Events::arg($event, 'context', 0, ''), $table, (bool) Events::arg($event, 'isNew', 2, false)));
        }
    }

    /**
     * @param   Event  $event  The event.
     *
     * @return  void
     *
     * @since   1.0.0
     */
    public function onAfterSave(Event $event): void
    {
        $table = Events::arg($event, 'subject', 1);

        if (\is_object($table)) {
            $this->guard(fn () => Events::afterSave($this->config(), (string) Events::arg($event, 'context', 0, ''), $table, (bool) Events::arg($event, 'isNew', 2, false)));
        }
    }

    /**
     * @param   Event  $event  The event.
     *
     * @return  void
     *
     * @since   1.0.0
     */
    public function onChangeState(Event $event): void
    {
        $this->guard(fn () => Events::changeState($this->config(), (string) Events::arg($event, 'context', 0, ''), (array) Events::arg($event, 'subject', 1, [])));
    }

    /**
     * @param   Event  $event  The event.
     *
     * @return  void
     *
     * @since   1.0.0
     */
    public function onAfterDelete(Event $event): void
    {
        $table = Events::arg($event, 'subject', 1);

        if (\is_object($table)) {
            $this->guard(fn () => Events::afterDelete($this->config(), (string) Events::arg($event, 'context', 0, ''), $table));
        }
    }

    /**
     * com_ajax entry point: index.php?option=com_ajax&group=content&plugin=cfpurge&format=json
     * with the action in the POST body. Administrator only, POST only, form token required,
     * and only for the users allowed by the plugin options.
     *
     * @param   Event  $event  The event.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function onAjax(Event $event): void
    {
        $app    = $this->getApplication();
        $config = $this->config();

        // Not loaded on every page view: only when a button asks something.
        $this->loadLanguage();

        if (!$app->isClient('administrator') || !Access::canPurge($app->getIdentity(), $config)) {
            throw new \RuntimeException(Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }

        if (strtoupper($app->getInput()->getMethod()) !== 'POST') {
            throw new \RuntimeException(Text::_('JERROR_ALERTNOAUTHOR'), 405);
        }

        if (!Session::checkToken('post')) {
            throw new \RuntimeException(Text::_('JINVALID_TOKEN_NOTICE'), 403);
        }

        $input  = $app->getInput()->post;
        $action = $input->getCmd('action', '');

        $payload = match ($action) {
            'status'     => $this->actionStatus($config),
            'verify'     => $this->actionVerify($config, $input->getString('zone_id', ''), $input->getString('api_token', ''), $input->getInt('typed', 0) === 1),
            'purge_all'  => $this->actionPurge($config, null),
            'purge_urls' => $this->actionPurge($config, (string) $input->getRaw('urls')),
            default      => throw new \RuntimeException(Text::_('PLG_CONTENT_CFPURGE_AJAX_BAD_ACTION'), 400),
        };

        if (method_exists($event, 'addResult')) {
            $event->addResult($payload);
        } else {
            $event->setArgument('result', array_merge((array) $event->getArgument('result', []), [$payload]));
        }
    }

    /**
     * @param   Config  $config  The settings.
     *
     * @return  array
     *
     * @since   2.0.0
     */
    private function actionStatus(Config $config): array
    {
        return ['ok' => true, 'last' => PurgeLog::last('purge'), 'lastText' => LastPurge::describe(PurgeLog::last('purge')), 'verified' => $config->isVerified(), 'configured' => $config->isConfigured()];
    }

    /**
     * Runs the connection check. Values typed in the form are used when sent; the result is
     * stored in the plugin parameters so that the dashboard shows the same traffic light.
     *
     * @param   Config  $config  The settings.
     * @param   string  $zone    Zone ID typed in the form.
     * @param   string  $token   Token typed in the form.
     * @param   bool    $typed   True when the form values must be used (even if empty).
     *
     * @return  array
     *
     * @since   2.0.0
     */
    private function actionVerify(Config $config, string $zone, string $token, bool $typed): array
    {
        $result = Diagnostics::run($config, $typed ? $zone : null, $typed ? $token : null);

        // The verification is stored only for the values that are saved: the fingerprint of
        // values typed but not yet saved simply does not match until the form is saved.
        $config->saveVerifyState($result['state']);

        return $result;
    }

    /**
     * Purge from the dashboard: the whole cache or the typed addresses.
     *
     * @param   Config       $config  The settings.
     * @param   string|null  $text    Addresses, one per line; null purges everything.
     *
     * @return  array
     *
     * @since   2.0.0
     */
    private function actionPurge(Config $config, ?string $text): array
    {
        if (!$config->isVerified()) {
            return ['ok' => false, 'error' => 'not_verified', 'message' => Text::_('PLG_CONTENT_CFPURGE_AJAX_NOT_VERIFIED')];
        }

        $urls = null;

        if ($text !== null) {
            $urls    = [];
            $invalid = [];

            foreach (preg_split('/\R/', $text) ?: [] as $line) {
                if (trim($line) === '') {
                    continue;
                }

                $url = Targets::normalise($line);

                if ($url === null) {
                    $invalid[] = mb_substr(trim($line), 0, 120);
                } else {
                    $urls[] = $url;
                }
            }

            $urls = array_values(array_unique($urls));

            if ($urls === [] && $invalid === []) {
                return ['ok' => false, 'error' => 'empty', 'message' => Text::_('PLG_CONTENT_CFPURGE_AJAX_NO_URLS')];
            }

            if ($invalid !== []) {
                return ['ok' => false, 'error' => 'invalid', 'invalid' => $invalid, 'message' => Text::sprintf('PLG_CONTENT_CFPURGE_AJAX_INVALID_URLS', Targets::host())];
            }

            if (\count($urls) > 100) {
                return ['ok' => false, 'error' => 'too_many', 'message' => Text::_('PLG_CONTENT_CFPURGE_AJAX_TOO_MANY')];
            }
        }

        $outcome = Purger::purgeNow($config, $urls, 'dashboard');

        $message = $outcome['ok']
            ? ($urls === null ? Text::_('PLG_CONTENT_CFPURGE_AJAX_PURGED_ALL') : Text::sprintf('PLG_CONTENT_CFPURGE_AJAX_PURGED_URLS', \count($urls)))
            : Text::sprintf('PLG_CONTENT_CFPURGE_AJAX_PURGE_FAILED', $outcome['summary']);

        $last = PurgeLog::last('purge');

        return ['ok' => $outcome['ok'], 'message' => $message, 'cause' => $outcome['cause'], 'last' => $last, 'lastText' => LastPurge::describe($last)];
    }

    /**
     * Runs an event handler so that nothing it does can break the save that raised the event.
     *
     * @param   callable  $handler  The handler.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function guard(callable $handler): void
    {
        try {
            $handler();
        } catch (\Throwable $e) {
            PurgeLog::write(['t' => 'purge', 'ok' => false, 'src' => 'event', 'msg' => 'Error while preparing the purge: ' . mb_substr($e->getMessage(), 0, 160)]);
        }
    }

    /**
     * The current settings object.
     *
     * @return  Config
     *
     * @since   2.0.0
     */
    private function config(): Config
    {
        return Config::load() ?? new Config($this->params, true, 0);
    }
}
