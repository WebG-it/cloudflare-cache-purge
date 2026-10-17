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

/**
 * The answer of one Cloudflare API call, reduced to what the plugin needs.
 *
 * @since  2.0.0
 */
final class CfResult
{
    public const OK             = 'ok';
    public const TOKEN_INVALID  = 'token_invalid';
    public const NO_PERMISSION  = 'no_permission';
    public const ZONE_NOT_FOUND = 'zone_not_found';
    public const RATE_LIMITED   = 'rate_limited';
    public const NETWORK        = 'network';
    public const SERVER         = 'server';
    public const UNKNOWN        = 'unknown';

    /**
     * @param   bool    $ok       Cloudflare answered with success=true.
     * @param   int     $http     HTTP status (0 when the call did not complete).
     * @param   int     $code     First Cloudflare error code (0 when none).
     * @param   string  $message  First Cloudflare error message, or the transport error.
     * @param   mixed   $result   The "result" member of the answer.
     */
    public function __construct(
        public readonly bool $ok,
        public readonly int $http,
        public readonly int $code,
        public readonly string $message,
        public readonly mixed $result = null,
        public readonly bool $transportFailure = false
    ) {
    }

    /**
     * Classifies the answer in a small set of causes the interface can explain.
     *
     * @return  string  One of the class constants.
     *
     * @since   2.0.0
     */
    public function cause(): string
    {
        if ($this->ok) {
            return self::OK;
        }

        if ($this->transportFailure || $this->http === 0) {
            return self::NETWORK;
        }

        if ($this->http === 429) {
            return self::RATE_LIMITED;
        }

        if ($this->http >= 500) {
            return self::SERVER;
        }

        if ($this->http === 401 || $this->http === 400 || \in_array($this->code, [1000, 6003, 6111, 9106, 9109], true) && $this->http !== 403) {
            return self::TOKEN_INVALID;
        }

        if ($this->http === 403) {
            return self::NO_PERMISSION;
        }

        if ($this->http === 404 || \in_array($this->code, [7000, 7003, 1001], true)) {
            return self::ZONE_NOT_FOUND;
        }

        return self::UNKNOWN;
    }

    /**
     * Short description for the log: no token, no address of the visitors.
     *
     * @return  string
     *
     * @since   2.0.0
     */
    public function summary(): string
    {
        if ($this->ok) {
            return 'HTTP ' . $this->http;
        }

        return 'HTTP ' . $this->http . ($this->code ? ' code ' . $this->code : '') . ($this->message !== '' ? ': ' . mb_substr($this->message, 0, 160) : '');
    }
}
