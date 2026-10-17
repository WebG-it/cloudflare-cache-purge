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
use Joomla\CMS\Language\Text;

/**
 * The "Verify connection" check: token, zone and a harmless purge test. It tells in plain
 * words what is wrong (token, permission, zone) and, when the token may read zones, which
 * Zone ID belongs to this site.
 *
 * @since  2.0.0
 */
final class Diagnostics
{
    private const OK   = 'ok';
    private const WARN = 'warn';
    private const FAIL = 'fail';

    /**
     * Runs the check with the typed values, falling back to the saved ones.
     *
     * @param   Config       $config  The saved settings.
     * @param   string|null  $zone    Zone ID typed in the form (null = use the saved one).
     * @param   string|null  $token   API token typed in the form (null or empty = use the saved one).
     *
     * @return  array  ok, checks, summary, suggested_zone_id, zone_name, state.
     *
     * @since   2.0.0
     */
    public static function run(Config $config, ?string $zone = null, ?string $token = null): array
    {
        $zone  = trim($zone ?? $config->zoneId());
        $token = trim((string) preg_replace('/[[:cntrl:]]+/', '', ($token !== null && trim($token) !== '') ? $token : $config->token()));

        $checks    = [];
        $suggested = null;
        $zoneName  = null;
        $fail      = static fn (string $id, string $key, string ...$args) => self::check($id, self::FAIL, $key, ...$args);

        // 1. What was typed.
        if ($token === '') {
            return self::finish($config, $zone, $token, [$fail('config', 'MSG_TOKEN_MISSING')], null, null);
        }

        if (!preg_match('/^[A-Za-z0-9_\-]{20,}$/', $token)) {
            return self::finish($config, $zone, $token, [$fail('config', 'MSG_TOKEN_FORMAT')], null, null);
        }

        if ($zone !== '' && !preg_match('/^[a-f0-9]{32}$/i', $zone)) {
            return self::finish($config, $zone, $token, [$fail('config', 'MSG_ZONE_FORMAT')], null, null);
        }

        $checks[] = self::check('config', self::OK, 'MSG_CONFIG_OK');

        $client = new CloudflareClient($token, 10);

        // 2. The token (user tokens; account tokens may be unknown to this endpoint).
        $verify = $client->verifyToken();

        if ($verify->cause() === CfResult::NETWORK) {
            $checks[] = $fail('token', 'MSG_NETWORK', $verify->message);

            return self::finish($config, $zone, $token, $checks, null, null);
        }

        $tokenFailed = false;

        if ($verify->ok) {
            $status = \is_array($verify->result) ? (string) ($verify->result['status'] ?? '') : '';

            if ($status !== '' && $status !== 'active') {
                $checks[]    = $fail('token', 'MSG_TOKEN_INACTIVE', $status);
                $tokenFailed = true;
            } else {
                $checks[] = self::check('token', self::OK, 'MSG_TOKEN_OK');
            }
        } else {
            $checks[] = self::check('token', self::WARN, 'MSG_TOKEN_UNKNOWN', self::cf($verify));
        }

        // 3. The zone: needs Zone > Zone > Read, which is optional.
        $host = Targets::host();

        if ($zone !== '') {
            $info = $client->zone($zone);

            if ($info->ok && \is_array($info->result)) {
                $zoneName = (string) ($info->result['name'] ?? '');

                if ($zoneName !== '' && ($host === strtolower($zoneName) || str_ends_with($host, '.' . strtolower($zoneName)))) {
                    $checks[] = self::check('zone', self::OK, 'MSG_ZONE_OK', $zoneName);
                } else {
                    $checks[] = $fail('zone', 'MSG_ZONE_MISMATCH', $zoneName, $host);
                    $found    = self::detect($client, $host);

                    if ($found !== null) {
                        $suggested = $found['id'];
                        $zoneName  = $found['name'];
                    }
                }
            } elseif ($info->cause() === CfResult::NO_PERMISSION) {
                $checks[] = self::check('zone', self::WARN, 'MSG_ZONE_NO_READ');
            } elseif ($info->cause() === CfResult::ZONE_NOT_FOUND) {
                $checks[] = $fail('zone', 'MSG_ZONE_NOT_FOUND');
            } elseif ($info->cause() === CfResult::NETWORK) {
                $checks[] = $fail('zone', 'MSG_NETWORK', $info->message);

                return self::finish($config, $zone, $token, $checks, null, null);
            } else {
                $checks[] = self::check('zone', self::WARN, 'MSG_ZONE_NO_READ');
            }
        } else {
            $found = self::detect($client, $host);

            if ($found !== null) {
                $zone      = $found['id'];
                $suggested = $found['id'];
                $zoneName  = $found['name'];
                $checks[]  = self::check('zone', self::OK, 'MSG_ZONE_DETECTED', $found['name'], $found['id']);
            } else {
                $checks[] = $fail('zone', 'MSG_ZONE_MISSING');

                return self::finish($config, $zone, $token, $checks, null, null);
            }
        }

        // A zone the user typed that is not the one of this site: no point in testing the purge.
        if ($tokenFailed || self::hasFail($checks)) {
            return self::finish($config, $zone, $token, $checks, $suggested, $zoneName);
        }

        // 4. The purge itself, on an address that does not exist: harmless, it proves the
        //    permission Zone > Cache Purge > Purge and the Zone ID.
        $probe  = Targets::root() . 'cfpurge-check-' . bin2hex(random_bytes(6));
        $result = $client->purgeFiles($zone, [$probe])[0];

        $checks[] = $result->ok
            ? self::check('purge', self::OK, 'MSG_PURGE_OK')
            : self::check('purge', self::FAIL, match ($result->cause()) {
                CfResult::NO_PERMISSION  => 'MSG_PURGE_NO_PERMISSION',
                CfResult::ZONE_NOT_FOUND => 'MSG_PURGE_ZONE_NOT_FOUND',
                CfResult::TOKEN_INVALID  => 'MSG_PURGE_TOKEN_INVALID',
                CfResult::RATE_LIMITED   => 'MSG_RATE_LIMITED',
                CfResult::SERVER         => 'MSG_SERVER',
                CfResult::NETWORK        => 'MSG_NETWORK',
                default                  => 'MSG_UNKNOWN',
            }, self::cf($result));

        return self::finish($config, $zone, $token, $checks, $suggested, $zoneName);
    }

    /**
     * Looks for the zone of this site by name: the host and then its parent domains.
     *
     * @param   CloudflareClient  $client  The client.
     * @param   string            $host    The host name of the site.
     *
     * @return  array|null  id and name, or null when not found or not readable.
     *
     * @since   2.0.0
     */
    private static function detect(CloudflareClient $client, string $host): ?array
    {
        $labels = explode('.', $host);

        while (\count($labels) >= 2) {
            $name   = implode('.', $labels);
            $result = $client->zonesByName($name);

            if ($result->ok && \is_array($result->result) && !empty($result->result[0]['id'])) {
                return ['id' => (string) $result->result[0]['id'], 'name' => (string) ($result->result[0]['name'] ?? $name)];
            }

            if (!$result->ok && $result->cause() !== CfResult::ZONE_NOT_FOUND && $result->http !== 200) {
                // No permission to read zones, or Cloudflare unreachable: stop asking.
                return null;
            }

            array_shift($labels);
        }

        return null;
    }

    private static function hasFail(array $checks): bool
    {
        foreach ($checks as $check) {
            if ($check['status'] === self::FAIL) {
                return true;
            }
        }

        return false;
    }

    /**
     * Cloudflare's own words, for the details.
     */
    private static function cf(CfResult $result): string
    {
        return trim('HTTP ' . $result->http . ($result->code ? ', code ' . $result->code : '') . ($result->message !== '' ? ', ' . mb_substr($result->message, 0, 160) : ''));
    }

    private static function check(string $id, string $status, string $key, string ...$args): array
    {
        $title = Text::_('PLG_CONTENT_CFPURGE_CHK_' . strtoupper($id));
        $text  = $args === [] ? Text::_('PLG_CONTENT_CFPURGE_' . $key) : Text::sprintf('PLG_CONTENT_CFPURGE_' . $key, ...$args);

        return ['id' => $id, 'status' => $status, 'title' => $title, 'message' => $text, 'key' => $key];
    }

    private static function finish(Config $config, string $zone, string $token, array $checks, ?string $suggested, ?string $zoneName): array
    {
        $ok   = !self::hasFail($checks);
        $user = '';

        try {
            $user = (string) Factory::getApplication()->getIdentity()->username;
        } catch (\Throwable $e) {
            // Leave empty.
        }

        $summary = Text::_($ok ? 'PLG_CONTENT_CFPURGE_SUMMARY_OK' : 'PLG_CONTENT_CFPURGE_SUMMARY_FAIL');

        foreach ($checks as $check) {
            if ($check['status'] === self::FAIL) {
                $summary = $check['message'];
                break;
            }
        }

        $state = [
            'ok'   => $ok,
            'hash' => $config->fingerprint($zone, $token),
            'at'   => gmdate('Y-m-d H:i:s'),
            'user' => $user,
            'zone' => (string) $zoneName,
            'key'  => $ok ? 'OK' : (string) (array_values(array_filter($checks, static fn ($c) => $c['status'] === self::FAIL))[0]['key'] ?? 'FAIL'),
        ];

        PurgeLog::write(['t' => 'verify', 'ok' => $ok, 'user' => $user, 'msg' => $state['key']]);

        return [
            'ok'                => $ok,
            'checks'            => $checks,
            'summary'           => $summary,
            'suggested_zone_id' => $suggested,
            'zone_name'         => $zoneName,
            'state'             => $state,
        ];
    }
}
