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
 * Nests a per-tenant limiter inside a global one.
 *
 * The two tiers answer different questions. The per-tenant bucket is about **fairness**: no merchant may spend another
 * merchant's share. The global bucket is about **protecting the far side**: the whole process stays under whatever
 * quota the Comfino account has, no matter how many tenants are busy at once.
 *
 * The tenant tier is consulted first, so a rejection is attributed to the tenant that caused it, and a busy tenant
 * cannot burn global capacity on calls it was not entitled to make anyway.
 */
final class TwoTierRateLimiter implements OutboundRateLimiterInterface
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
            return $globalReservation;
        }

        return Reservation::accepted(min($tenantReservation->remaining, $globalReservation->remaining));
    }
}
