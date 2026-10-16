<?php

/**
 * ComfinoPay PHP API client
 *
 * Backend routines for communication with the ComfinoPay payment gateway REST API.
 *
 * @package Comfino\Api\RateLimit
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-api-client
 */

declare(strict_types=1);

namespace Comfino\Api\RateLimit;

/**
 * Builds the conventional limiter key, "tenantKey|endpoint".
 *
 * Keying by tenant is what makes the limiter fair rather than merely protective: without it, the busiest merchant simply wins, and the quota it consumes is
 * taken from everyone sharing the process.
 *
 * The endpoint part must be normalized: callers pass whatever identifies the call, which for {@see \Comfino\Api\SharedClient} is the full request URI, query
 * string included. Keyed on that raw URI, a parameterized `GET` (the only call any integration issues at volume, once per checkout render with the cart total
 * in the query) gets a fresh full bucket per parameter value, so the limiter never actually limits it and the store grows a row per cart total instead of per
 * tenant. {@see build()} drops the query and fragment before keying; scheme, host and path survive, so sandbox and production keep separate budgets.
 */
final class RateLimitKey
{
    /**
     * @param string|null $tenantKey Stable per-tenant key, or null for a single-tenant host
     * @param string $endpoint Endpoint name, path or full request URI the call is addressed to; a query string and
     *                         fragment are stripped, so two calls to one endpoint share one bucket
     */
    public static function build(?string $tenantKey, string $endpoint): string
    {
        return ($tenantKey ?? '_') . '|' . self::normalizeEndpoint($endpoint);
    }

    /**
     * A truncation rather than a parse: an endpoint that isn't a URL at all ("orders", a class name) must come back unchanged, which `parse_url()` cannot
     * guarantee. Cutting at the first `?` or `#` handles both shapes and never fails.
     *
     * @param string $endpoint Endpoint name, path or full request URI
     *
     * @return string The same string up to its query or fragment, whichever comes first
     */
    private static function normalizeEndpoint(string $endpoint): string
    {
        return ($cutAt = strcspn($endpoint, '?#')) === strlen($endpoint) ? $endpoint : substr($endpoint, 0, $cutAt);
    }
}
