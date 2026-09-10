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
 * Keying by tenant is what makes the limiter fair rather than merely protective: without it, the busiest merchant
 * simply wins, and the quota it consumes is taken from everyone sharing the process.
 *
 * **The endpoint part is normalized, and it has to be.** Callers hand this whatever identifies the call, which for
 * {@see \Comfino\Api\SharedClient} is the request URI - query string included. A limiter keyed on that is not loose,
 * it is inert: `GET /financial-products?loanAmount=130000` and `?loanAmount=130100` are two keys, so they are two
 * buckets, each with its own full capacity. The parameterized `GET` is the only call any integration issues at volume
 * (once per checkout render, with the cart total in the query), so the endpoint that most needs a limit is the one
 * that would never reach it, and the store would grow a row per distinct cart total rather than per tenant.
 * {@see build()} therefore drops the query and fragment before assembling the key.
 *
 * What survives normalization is scheme, host and path. The host is deliberately kept: production and sandbox are
 * different hosts, and a merchant calling one should not spend the budget of the other.
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
     * Reduces whatever the caller passed to the endpoint it addresses.
     *
     * Deliberately a truncation rather than a parse: an endpoint name that is not a URL at all ("orders", or a class
     * name) must come back unchanged, and `parse_url()` on those is a rejection or a surprise. Cutting at the first
     * `?` or `#` is exactly the transformation wanted for both shapes and cannot fail.
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
