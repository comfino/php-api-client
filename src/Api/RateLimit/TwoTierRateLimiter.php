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
 * Nests a per-tenant limiter inside a global one: the per-tenant bucket gives fairness (no merchant spends another merchant's share),
 * the global bucket protects the far side (the whole process stays under the account's quota regardless of how many tenants are busy).
 *
 * The tenant tier is consulted first, so a rejection is attributed to the tenant that caused it, and a busy tenant cannot
 * burn global capacity on calls it was not entitled to make anyway. When the global tier is the one that refuses, the
 * tenant's tokens are handed back - see {@see reserve()} - provided the tenant tier is a {@see RefundableRateLimiterInterface};
 * {@see isRefundable()} reports whether it is.
 */
final class TwoTierRateLimiter implements RefundableRateLimiterInterface
{
    /**
     * @param OutboundRateLimiterInterface $perTenantLimiter Fairness tier, keyed per tenant
     * @param OutboundRateLimiterInterface $globalLimiter Protection tier, shared by every tenant
     * @param string $globalKey Key used against the global tier
     */
    public function __construct(
        private readonly OutboundRateLimiterInterface $perTenantLimiter,
        private readonly OutboundRateLimiterInterface $globalLimiter,
        private readonly string $globalKey = '_global'
    ) {
    }

    /** @inheritDoc */
    public function reserve(string $key, int $tokens = 1): Reservation
    {
        $tenantReservation = $this->perTenantLimiter->reserve($key, $tokens);

        if (!$tenantReservation->accepted) {
            return $tenantReservation;
        }

        $globalReservation = $this->globalLimiter->reserve($this->globalKey, $tokens);

        if (!$globalReservation->accepted) {
            /* The tenant tier already charged for a call that is not going to happen. Left spent, sustained global
               pressure drained every tenant's bucket to zero while none of them sent anything - so a tenant well inside
               its own share ended up throttled by *other* tenants' traffic, which is the exact unfairness this class is
               here to prevent. Refunding keeps the rejection attributable to the global tier, where it belongs. */
            $this->refundTenant($key, $tokens);

            return $globalReservation;
        }

        return Reservation::accepted(min($tenantReservation->remaining, $globalReservation->remaining));
    }

    /**
     * @inheritDoc
     *
     * Both tiers are credited, since {@see reserve()} charges both.
     */
    public function refund(string $key, int $tokens = 1): void
    {
        $this->refundTenant($key, $tokens);

        if ($this->globalLimiter instanceof RefundableRateLimiterInterface) {
            $this->globalLimiter->refund($this->globalKey, $tokens);
        }
    }

    /**
     * Whether the tenant tier can actually be credited back.
     *
     * False means this limiter degrades to the old behaviour - a globally-rejected call still costs the tenant its tokens -
     * which is worth asserting in a host's wiring test, the same way {@see TokenBucketRateLimiter::isExact()} is.
     */
    public function isRefundable(): bool
    {
        return $this->perTenantLimiter instanceof RefundableRateLimiterInterface;
    }

    /**
     * Credits the tenant tier when it supports it and does nothing when it does not.
     *
     * @param string $key Limiter key the tokens were reserved against
     * @param int $tokens Token count to return
     */
    private function refundTenant(string $key, int $tokens): void
    {
        if ($this->perTenantLimiter instanceof RefundableRateLimiterInterface) {
            $this->perTenantLimiter->refund($key, $tokens);
        }
    }
}
