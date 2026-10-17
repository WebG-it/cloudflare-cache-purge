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
use Joomla\CMS\Log\Log;

/**
 * Log of the plugin: one Joomla log file, one JSON object per line, rotated above 1 MB.
 * The administrator module reads the last purge from here, so no table is needed.
 * Tokens, zone ids and visitor data are never written.
 *
 * @since  2.0.0
 */
final class PurgeLog
{
    public const FILE     = 'plg_content_cfpurge.php';
    public const CATEGORY = 'plg_content_cfpurge';

    private const MAX_BYTES  = 1048576;
    private const TAIL_BYTES = 65536;

    private static bool $ready = false;

    /**
     * Writes one entry.
     *
     * @param   array  $entry  Keys: t (purge or verify), ok, src, user, scope, n, msg.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public static function write(array $entry): void
    {
        try {
            self::init();

            $entry['ok'] = !empty($entry['ok']);

            Log::add(
                (string) json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                $entry['ok'] ? Log::INFO : Log::WARNING,
                self::CATEGORY
            );
        } catch (\Throwable $e) {
            // Logging must never break a save.
        }
    }

    /**
     * The most recent entry of a type (purge by default), or null.
     *
     * @param   string  $type  The entry type.
     *
     * @return  array|null  The entry with the date added as "at".
     *
     * @since   2.0.0
     */
    public static function last(string $type = 'purge'): ?array
    {
        $file = self::path();

        if (!is_file($file) || !is_readable($file)) {
            return null;
        }

        $size   = (int) filesize($file);
        $handle = @fopen($file, 'rb');

        if (!$handle) {
            return null;
        }

        fseek($handle, max(0, $size - self::TAIL_BYTES));
        $tail = (string) stream_get_contents($handle);
        fclose($handle);

        $lines = preg_split('/\R/', $tail) ?: [];

        for ($i = \count($lines) - 1; $i >= 0; $i--) {
            $line  = $lines[$i];
            $start = strpos($line, '{');

            if ($start === false || $line[0] === '#') {
                continue;
            }

            $data = json_decode(substr($line, $start), true);

            if (\is_array($data) && ($data['t'] ?? '') === $type) {
                $data['at'] = trim(substr($line, 0, $start));
                // "2026-10-17T10:00:00+00:00 INFO plg_content_cfpurge" -> keep the date only.
                $data['at'] = (string) strtok($data['at'], "\t ");

                return $data;
            }
        }

        return null;
    }

    private static function path(): string
    {
        $dir = JPATH_ADMINISTRATOR . '/logs';

        try {
            $dir = (string) Factory::getApplication()->get('log_path', $dir);
        } catch (\Throwable $e) {
            // Keep the default folder.
        }

        return rtrim($dir, '/\\') . '/' . self::FILE;
    }

    private static function init(): void
    {
        if (self::$ready) {
            return;
        }

        self::$ready = true;

        try {
            $file = self::path();

            clearstatcache(true, $file);

            if (is_file($file) && filesize($file) > self::MAX_BYTES) {
                // Keep the .php extension: the file starts with Joomla's die() guard.
                @rename($file, substr($file, 0, -4) . '.1.php');
            }
        } catch (\Throwable $e) {
            // Ignore: the log keeps growing, nothing breaks.
        }

        Log::addLogger(
            ['text_file' => self::FILE, 'text_entry_format' => "{DATETIME}\t{PRIORITY}\t{MESSAGE}"],
            Log::ALL,
            [self::CATEGORY]
        );
    }
}
