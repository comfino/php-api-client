<?php

/**
 * ComfinoPay PHP API client
 *
 * Backend routines for communication with the ComfinoPay payment gateway REST API.
 *
 * @package Comfino\Api\CircuitBreaker
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-api-client
 */

declare(strict_types=1);

namespace Comfino\Api\CircuitBreaker;

/**
 * Builds the conventional breaker key, "tenantKey|host".
 *
 * A helper rather than a value object because the key crosses into a host-supplied store, where a plain string is what
 * every backend - APCu, Redis, a database row - actually wants.
 */
final class CircuitBreakerKey
{
    /**
     * @param string|null $tenantKey Stable per-tenant key, or null for a single-tenant host
     * @param string $host API host the call is addressed to
     */
    public static function build(?string $tenantKey, string $host): string
    {
        return ($tenantKey ?? '_') . '|' . $host;
    }
}
