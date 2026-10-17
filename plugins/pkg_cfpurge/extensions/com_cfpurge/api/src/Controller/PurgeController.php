<?php

/**
 * @package     WebG.Component
 * @subpackage  com_cfpurge
 *
 * @copyright   (C) 2026 WebG di Marco Galassi <https://www.webg.it>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace WebG\Component\Cfpurge\Api\Controller;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use Joomla\CMS\Access\Exception\NotAllowed;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use Tobscure\JsonApi\Resource;
use WebG\Component\Cfpurge\Api\Serializer\ResultSerializer;
use WebG\Plugin\Content\Cfpurge\Service\Config;
use WebG\Plugin\Content\Cfpurge\Service\ManualPurge;

/**
 * Cloudflare Cache Purge through the Joomla Web Services API.
 *
 *   GET  /api/index.php/v1/cfpurge/status      state of the setup and the last purge
 *   POST /api/index.php/v1/cfpurge/all         purge the whole Cloudflare cache
 *   POST /api/index.php/v1/cfpurge/urls        purge some addresses: {"urls": ["https://...", "/path"]}
 *
 * The caller is the user of the Joomla API token; the permissions are those of com_cfpurge
 * (cfpurge.status, cfpurge.purge; Super Users always). The Cloudflare token and the Zone ID are
 * never part of an answer. The work is done by the same service as the box on the
 * administrator home page, so limits and checks are the same.
 *
 * @since  2.1.0
 */
class PurgeController extends BaseController
{
    /**
     * GET v1/cfpurge/status
     *
     * @return  static
     *
     * @since   2.1.0
     */
    public function status(): static
    {
        $this->authorise('cfpurge.status');

        return $this->answer('status', ManualPurge::status($this->config()));
    }

    /**
     * POST v1/cfpurge/all
     *
     * @return  static
     *
     * @since   2.1.0
     */
    public function all(): static
    {
        $this->authorise('cfpurge.purge');

        return $this->purge(null);
    }

    /**
     * POST v1/cfpurge/urls
     *
     * @return  static
     *
     * @since   2.1.0
     */
    public function urls(): static
    {
        $this->authorise('cfpurge.purge');

        $body = $this->input->json->getArray();
        $urls = $body['urls'] ?? null;

        if (!\is_array($urls)) {
            return $this->answer('purge', ['ok' => false, 'cause' => 'urls_required', 'message' => Text::_('COM_CFPURGE_API_URLS_REQUIRED')], 400);
        }

        return $this->purge(array_values($urls));
    }

    /**
     * @param   array<int,mixed>|null  $urls  Addresses; null for the whole cache.
     *
     * @return  static
     *
     * @since   2.1.0
     */
    private function purge(?array $urls): static
    {
        $config = $this->config();

        if ($config === null || !$config->isEnabled()) {
            return $this->answer('purge', ['ok' => false, 'cause' => 'not_available', 'message' => Text::_('COM_CFPURGE_API_NOT_AVAILABLE')], 409);
        }

        $result = ManualPurge::run($config, $urls, 'api');

        if (!$result['ok']) {
            // not_verified, empty, invalid, too_many: the caller's fault or the setup; Cloudflare failure: bad gateway.
            $error = (string) ($result['error'] ?? 'cloudflare');
            $code  = match ($error) {
                'not_verified' => 409,
                'cloudflare'   => 502,
                default        => 422,
            };

            $this->answer('purge', $this->publicResult($result), $code);

            return $this;
        }

        return $this->answer('purge', $this->publicResult($result));
    }

    /**
     * The result of a purge as the API shows it: no raw log entry, no Zone ID, no token.
     *
     * @param   array  $result  The result of ManualPurge::run().
     *
     * @return  array
     *
     * @since   2.1.0
     */
    private function publicResult(array $result): array
    {
        $out = [
            'ok'      => (bool) $result['ok'],
            'message' => (string) ($result['message'] ?? ''),
            'cause'   => (string) ($result['cause'] ?? ($result['error'] ?? '')),
            'count'   => $result['count'] ?? null,
            'last'    => ManualPurge::publicLast($result['last'] ?? null),
        ];

        if (!empty($result['invalid'])) {
            $out['invalid'] = array_map('strval', (array) $result['invalid']);
        }

        return $out;
    }

    /**
     * Stops unless the API user may do the action on this component.
     *
     * @param   string  $action  cfpurge.status or cfpurge.purge.
     *
     * @return  void
     *
     * @since   2.1.0
     */
    private function authorise(string $action): void
    {
        $this->loadPluginLanguage();

        $user = $this->app->getIdentity();

        if ($user === null || $user->guest) {
            throw new NotAllowed(Text::_('JERROR_ALERTNOAUTHOR'), 401);
        }

        if (!$user->authorise($action, 'com_cfpurge')) {
            throw new NotAllowed(Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }
    }

    /**
     * @return  Config|null
     *
     * @since   2.1.0
     */
    private function config(): ?Config
    {
        return class_exists(Config::class) ? Config::load() : null;
    }

    /**
     * The messages come from the language files of the content plugin.
     *
     * @return  void
     *
     * @since   2.1.0
     */
    private function loadPluginLanguage(): void
    {
        $language = $this->app->getLanguage();
        $language->load('plg_content_cfpurge', JPATH_ADMINISTRATOR) || $language->load('plg_content_cfpurge', JPATH_PLUGINS . '/content/cfpurge');
        $language->load('com_cfpurge', JPATH_ADMINISTRATOR);
    }

    /**
     * Writes the answer in the JSON:API shape the other endpoints of Joomla use.
     *
     * @param   string  $id          What the answer is about.
     * @param   array   $attributes  The data.
     * @param   int     $code        HTTP status.
     *
     * @return  static
     *
     * @since   2.1.0
     */
    private function answer(string $id, array $attributes, int $code = 200): static
    {
        $this->app->setHeader('status', (string) $code);
        $this->app->setHeader('Cache-Control', 'no-store');
        $this->app->getDocument()->setData(new Resource(['id' => $id, 'attributes' => $attributes], new ResultSerializer()));

        return $this;
    }
}
