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

use Joomla\CMS\Http\HttpFactory;
use Joomla\Registry\Registry;

/**
 * Minimal client of the Cloudflare API v4: only the calls the plugin needs. The address
 * of the API is fixed (the token is only ever sent to Cloudflare).
 *
 * @since  2.0.0
 */
final class CloudflareClient
{
    private const BASE = 'https://api.cloudflare.com/client/v4';

    /** Cloudflare accepts at most 30 addresses per purge call (all plans). */
    public const MAX_FILES = 30;

    private string $token;

    /**
     * @param   string  $token    The API token.
     * @param   int     $timeout  Seconds before a call is given up.
     */
    public function __construct(string $token, private int $timeout = 10)
    {
        $this->token = (string) preg_replace('/[[:cntrl:]]+/', '', trim($token));
    }

    /**
     * Checks that the token is valid and active (user tokens; account tokens may not be
     * recognised by this endpoint, so the answer alone does not decide).
     *
     * @return  CfResult
     *
     * @since   2.0.0
     */
    public function verifyToken(): CfResult
    {
        return $this->request('GET', '/user/tokens/verify');
    }

    /**
     * Reads a zone (needs the optional Zone Read permission).
     *
     * @param   string  $zoneId  The zone id.
     *
     * @return  CfResult
     *
     * @since   2.0.0
     */
    public function zone(string $zoneId): CfResult
    {
        return $this->request('GET', '/zones/' . rawurlencode($zoneId));
    }

    /**
     * Looks for a zone by its domain name (needs the optional Zone Read permission).
     *
     * @param   string  $name  The domain name.
     *
     * @return  CfResult
     *
     * @since   2.0.0
     */
    public function zonesByName(string $name): CfResult
    {
        return $this->request('GET', '/zones?name=' . rawurlencode($name) . '&per_page=5');
    }

    /**
     * Purges the whole cache of the zone.
     *
     * @param   string  $zoneId  The zone id.
     *
     * @return  CfResult
     *
     * @since   2.0.0
     */
    public function purgeEverything(string $zoneId): CfResult
    {
        return $this->request('POST', '/zones/' . rawurlencode($zoneId) . '/purge_cache', ['purge_everything' => true]);
    }

    /**
     * Purges some addresses, in blocks of 30.
     *
     * @param   string    $zoneId  The zone id.
     * @param   string[]  $urls    Absolute addresses.
     *
     * @return  CfResult[]  One result per block.
     *
     * @since   2.0.0
     */
    public function purgeFiles(string $zoneId, array $urls): array
    {
        $results = [];

        foreach (array_chunk(array_values($urls), self::MAX_FILES) as $block) {
            $results[] = $this->request('POST', '/zones/' . rawurlencode($zoneId) . '/purge_cache', ['files' => $block]);
        }

        return $results;
    }

    private function request(string $method, string $path, ?array $body = null): CfResult
    {
        $headers = [
            'Authorization' => 'Bearer ' . $this->token,
            'Content-Type'  => 'application/json',
            'Accept'        => 'application/json',
        ];

        try {
            $http = (new HttpFactory())->getHttp(new Registry(['userAgent' => 'WebG-Cfpurge/2.0']));

            $response = $method === 'POST'
                ? $http->post(self::BASE . $path, json_encode($body), $headers, $this->timeout)
                : $http->get(self::BASE . $path, $headers, $this->timeout);
        } catch (\Throwable $e) {
            return new CfResult(false, 0, 0, $e->getMessage(), null, true);
        }

        $status  = (int) $response->getStatusCode();
        $payload = json_decode((string) $response->getBody(), true);

        if (!\is_array($payload)) {
            return new CfResult(false, $status, 0, 'Unexpected answer from Cloudflare', null);
        }

        $error = $payload['errors'][0] ?? [];

        return new CfResult(
            !empty($payload['success']) && $status >= 200 && $status < 300,
            $status,
            (int) ($error['code'] ?? 0),
            (string) ($error['message'] ?? ''),
            $payload['result'] ?? null
        );
    }
}
