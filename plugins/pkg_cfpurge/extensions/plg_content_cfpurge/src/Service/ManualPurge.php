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

use Joomla\CMS\Language\Text;

/**
 * A purge asked by a person or by a program: from the box on the administrator home page
 * and from the Joomla Web Services API. Same checks, same limits, same outcome for both.
 * Who may ask is decided by the caller (dashboard: plugin options; API: ACL of com_cfpurge).
 *
 * @since  2.1.0
 */
final class ManualPurge
{
    /**
     * Most addresses accepted in one request.
     */
    public const MAX_URLS = 100;

    /**
     * Runs a purge.
     *
     * @param   Config                    $config  The settings.
     * @param   string|array<int,string>|null  $input   Addresses (a text with one per line, or a list); null purges the whole cache.
     * @param   string                    $source  Where the request comes from, written to the log (dashboard, api).
     *
     * @return  array  ok, and message / error / cause / last / lastText for the caller.
     *
     * @since   2.1.0
     */
    public static function run(Config $config, string|array|null $input, string $source): array
    {
        if (!$config->isVerified()) {
            return ['ok' => false, 'error' => 'not_verified', 'message' => Text::_('PLG_CONTENT_CFPURGE_AJAX_NOT_VERIFIED')];
        }

        $urls = null;

        if ($input !== null) {
            $lines   = \is_array($input) ? $input : (preg_split('/\R/', $input) ?: []);
            $urls    = [];
            $invalid = [];

            foreach ($lines as $line) {
                if (!\is_string($line) || trim($line) === '') {
                    if (!\is_string($line)) {
                        $invalid[] = '(not text)';
                    }

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

            if (\count($urls) > self::MAX_URLS) {
                return ['ok' => false, 'error' => 'too_many', 'message' => Text::_('PLG_CONTENT_CFPURGE_AJAX_TOO_MANY')];
            }
        }

        $outcome = Purger::purgeNow($config, $urls, $source);

        $message = $outcome['ok']
            ? ($urls === null ? Text::_('PLG_CONTENT_CFPURGE_AJAX_PURGED_ALL') : Text::sprintf('PLG_CONTENT_CFPURGE_AJAX_PURGED_URLS', \count($urls)))
            : Text::sprintf('PLG_CONTENT_CFPURGE_AJAX_PURGE_FAILED', $outcome['summary']);

        $last = PurgeLog::last('purge');

        return ['ok' => $outcome['ok'], 'message' => $message, 'cause' => $outcome['cause'], 'count' => $urls === null ? null : \count($urls), 'last' => $last, 'lastText' => LastPurge::describe($last)];
    }

    /**
     * What a program may know about the setup. Never the token, never the Zone ID.
     *
     * @param   Config|null  $config  The settings, null when the plugin is not installed.
     *
     * @return  array
     *
     * @since   2.1.0
     */
    public static function status(?Config $config): array
    {
        if ($config === null) {
            return ['configured' => false, 'verified' => false, 'enabled' => false, 'mode' => null, 'last' => null, 'lastText' => ''];
        }

        $last = PurgeLog::last('purge');

        return [
            'configured' => $config->isConfigured(),
            'verified'   => $config->isVerified(),
            'enabled'    => $config->isEnabled(),
            'mode'       => $config->mode(),
            'last'       => self::publicLast($last),
            'lastText'   => LastPurge::describe($last),
        ];
    }

    /**
     * The last purge as a program may see it: only the facts, a short outcome text, nothing else.
     *
     * @param   array|null  $last  The log entry.
     *
     * @return  array|null
     *
     * @since   2.1.0
     */
    public static function publicLast(?array $last): ?array
    {
        if ($last === null) {
            return null;
        }

        return [
            'at'     => (string) ($last['at'] ?? ''),
            'ok'     => !empty($last['ok']),
            'scope'  => (string) ($last['scope'] ?? ''),
            'count'  => (int) ($last['n'] ?? 0),
            'source' => (string) ($last['src'] ?? ''),
            'cause'  => (string) ($last['cause'] ?? ''),
            'user'   => (string) ($last['user'] ?? ''),
            'detail' => mb_substr((string) ($last['msg'] ?? ''), 0, 200),
        ];
    }
}
