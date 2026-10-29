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
 * A limiter that can give tokens back when a reservation it granted turns out not to be used.
 *
 * Exists because composing limiters otherwise charges for calls that never happen. {@see TwoTierRateLimiter} reserves
 * from the tenant tier and then from the global one, and a rejection by the *second* left the first tier's tokens
 * spent - so under sustained global pressure every tenant's bucket drained to zero while none of them sent anything,
 * inverting the fairness the two-tier arrangement exists to provide.
 *
 * Kept separate from {@see OutboundRateLimiterInterface} rather than added to it, so an existing host implementation
 * keeps working untouched; a composing limiter checks for this interface and degrades to the old behaviour without it,
 * the same way {@see TokenBucketRateLimiter} picks up {@see AtomicTokenBucketStoreInterface} when a store offers it.
 *
 * A refund is *not* a general-purpose credit: only tokens a matching {@see OutboundRateLimiterInterface::reserve()}
 * granted may be returned, and an implementation must never let a refund lift a bucket above its capacity.
 */
interface RefundableRateLimiterInterface extends OutboundRateLimiterInterface
{
    /**
     * Returns tokens from an accepted reservation that will not be used.
     *
     * @param string $key Limiter key the tokens were reserved against
     * @param int $tokens Token count to return; must not exceed what was reserved
     */
    public function refund(string $key, int $tokens = 1): void;
}
