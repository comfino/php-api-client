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
 * Outbound request limiter, applied before the transport call.
 *
 * Both this library and the SDK used to limit inbound webhooks and nothing outbound. For a single-shop plugin that is
 * defensible. For a connector fronting many merchants against one ComfinoPay API, it is a shared-fate problem: one
 * merchant's traffic spike consumes the whole account's quota, and every other merchant's checkout starts collecting
 * 429s from the far side.
 *
 * The reservation is **non-blocking** by contract. Parking a request thread on a limiter converts a throughput problem
 * into a latency problem and, on a shopper-facing path, into an abandoned cart. What to do with a rejection is a
 * call-site decision - see {@see \Comfino\Api\OnLimit} - not something this interface may decide.
 */
interface OutboundRateLimiterInterface
{
    /**
     * Attempts to reserve capacity for a call. Returns immediately either way.
     *
     * @param string $key Limiter key, conventionally "tenantKey|endpoint" - see {@see RateLimitKey}
     * @param int $tokens Cost of the call in tokens
     *
     * @return Reservation Whether the call may proceed, and how long to wait if not
     */
    public function reserve(string $key, int $tokens = 1): Reservation;
}
