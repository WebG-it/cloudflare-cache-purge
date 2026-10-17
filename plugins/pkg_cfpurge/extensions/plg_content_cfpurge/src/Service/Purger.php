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

/**
 * Runs the purges. Purges triggered by events are queued during the request and sent once
 * at the end of it (one call for any number of saved items, addresses in blocks of 30);
 * purges asked by hand from the dashboard run at once and return the answer.
 *
 * @since  2.0.0
 */
final class Purger
{
    /** Above this number of addresses the whole cache is purged instead (three blocks). */
    private const MAX_TARGETED = 90;

    private static bool $everything = false;

    /** @var string[] */
    private static array $urls = [];

    /** @var string[] */
    private static array $sources = [];

    private static ?Config $config = null;

    private static string $user = '';

    private static bool $hooked = false;

    /**
     * Queues a purge of the whole cache.
     *
     * @param   Config  $config  The plugin settings.
     * @param   string  $source  What asked for it (for example article, category).
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public static function queueEverything(Config $config, string $source): void
    {
        if (!$config->isConfigured()) {
            return;
        }

        self::$everything = true;
        self::remember($config, $source);
    }

    /**
     * Queues the purge of some addresses; with the whole cache already queued it adds nothing.
     *
     * @param   Config    $config  The plugin settings.
     * @param   string[]  $urls    Absolute addresses.
     * @param   string    $source  What asked for it.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public static function queueUrls(Config $config, array $urls, string $source): void
    {
        if (!$config->isConfigured()) {
            return;
        }

        self::$urls = array_values(array_unique(array_merge(self::$urls, $urls)));
        self::remember($config, $source);
    }

    /**
     * Sends the queued purge. Runs at the end of the request; calling it again does nothing.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public static function flush(): void
    {
        $config = self::$config;

        if ($config === null || (!self::$everything && self::$urls === [])) {
            return;
        }

        $everything = self::$everything || \count(self::$urls) > self::MAX_TARGETED;
        $urls       = self::$urls;
        $source     = implode(',', array_unique(self::$sources));
        $user       = self::$user;

        self::$everything = false;
        self::$urls       = [];
        self::$sources    = [];
        self::$config     = null;

        try {
            $outcome = self::send($config, $everything ? null : $urls);
        } catch (\Throwable $e) {
            PurgeLog::write(['t' => 'purge', 'ok' => false, 'src' => $source, 'user' => $user, 'scope' => $everything ? 'all' : 'urls', 'n' => \count($urls), 'msg' => 'Unexpected error: ' . $e->getMessage()]);

            return;
        }

        PurgeLog::write([
            't'     => 'purge',
            'ok'    => $outcome['ok'],
            'src'   => $source,
            'user'  => $user,
            'scope' => $everything ? 'all' : 'urls',
            'n'     => $everything ? 0 : \count($urls),
            'cause' => $outcome['cause'],
            'msg'   => $outcome['summary'],
        ]);
    }

    /**
     * Purges at once (dashboard buttons) and returns the outcome.
     *
     * @param   Config         $config  The plugin settings.
     * @param   string[]|null  $urls    Addresses, or null for the whole cache.
     * @param   string         $source  What asked for it.
     *
     * @return  array  ok, cause, summary, calls.
     *
     * @since   2.0.0
     */
    public static function purgeNow(Config $config, ?array $urls, string $source): array
    {
        $outcome = self::send($config, $urls);
        $user    = '';

        try {
            $user = (string) Factory::getApplication()->getIdentity()->username;
        } catch (\Throwable $e) {
            // Anonymous: leave empty.
        }

        PurgeLog::write([
            't'     => 'purge',
            'ok'    => $outcome['ok'],
            'src'   => $source,
            'user'  => $user,
            'scope' => $urls === null ? 'all' : 'urls',
            'n'     => $urls === null ? 0 : \count($urls),
            'cause' => $outcome['cause'],
            'msg'   => $outcome['summary'],
        ]);

        return $outcome;
    }

    /**
     * Calls Cloudflare.
     *
     * @param   Config         $config  The plugin settings.
     * @param   string[]|null  $urls    Addresses, or null for the whole cache.
     *
     * @return  array
     *
     * @since   2.0.0
     */
    private static function send(Config $config, ?array $urls): array
    {
        // Short timeout: a slow Cloudflare must not hold the save of an article for long.
        $client = new CloudflareClient($config->token(), 8);

        if ($urls === null) {
            $results = [$client->purgeEverything($config->zoneId())];
        } else {
            $results = $client->purgeFiles($config->zoneId(), $urls);
        }

        $ok      = true;
        $cause   = CfResult::OK;
        $summary = [];

        foreach ($results as $result) {
            $summary[] = $result->summary();

            if (!$result->ok) {
                $ok    = false;
                $cause = $result->cause();
            }
        }

        return ['ok' => $ok, 'cause' => $cause, 'summary' => implode(' | ', array_unique($summary)), 'calls' => \count($results)];
    }

    private static function remember(Config $config, string $source): void
    {
        self::$config    = $config;
        self::$sources[] = $source;

        if (self::$user === '') {
            try {
                self::$user = (string) Factory::getApplication()->getIdentity()->username;
            } catch (\Throwable $e) {
                self::$user = '';
            }
        }

        if (!self::$hooked) {
            self::$hooked = true;
            // At the end of the request, also after a redirect that ends with exit().
            register_shutdown_function([self::class, 'flush']);
        }
    }
}
