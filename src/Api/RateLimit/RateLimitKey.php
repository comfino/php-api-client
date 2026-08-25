<?php

/**
 * Comfino PHP API client
 *
 * Backend routines for communication with the Comfino payment gateway REST API.
 *
 * @package Comfino\Api\RateLimit
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-api-client
 */

declare(strict_types=1);

namespace Comfino\Api\RateLimit;

/**
 * Builds the conventional limiter key, "tenantKey|endpoint".
 *
 * Keying by tenant is what makes the limiter fair rather than merely protective: without it, the busiest merchant
 * simply wins, and the quota it consumes is taken from everyone sharing the process.
 */
final class RateLimitKey
{
    /**
     * @param string|null $tenantKey Stable per-tenant key, or null for a single-tenant host
     * @param string $endpoint Endpoint name or path the call is addressed to
     */
    public static function build(?string $tenantKey, string $endpoint): string
    {
        return ($tenantKey ?? '_') . '|' . $endpoint;
    }
}
