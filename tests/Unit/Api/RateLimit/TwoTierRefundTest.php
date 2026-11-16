<?php

/**
 * ComfinoPay PHP API client
 *
 * Backend routines for communication with the ComfinoPay payment gateway REST API.
 *
 * @package Comfino\Tests\Unit\Api\RateLimit
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-api-client
 */

declare(strict_types=1);

namespace Comfino\Tests\Unit\Api\RateLimit;

use Comfino\Api\RateLimit\NullRateLimiter;
use Comfino\Api\RateLimit\TokenBucketRateLimiter;
use Comfino\Api\RateLimit\TwoTierRateLimiter;
use Comfino\Api\Support\FrozenClock;
use PHPUnit\Framework\TestCase;

/**
 * What a two-tier limiter charges the tenant when the *global* tier is the one that says no.
 *
 * This is the case that made the arrangement unfair in the direction it exists to prevent. The tenant tier is charged
 * first, so a global rejection left those tokens spent on a call that never went out - and under sustained global
 * pressure every tenant's bucket drained to zero while none of them sent anything. A tenant well inside its own share
 * ended up throttled by other tenants' traffic.
 */
final class TwoTierRefundTest extends TestCase
{
    private FrozenClock $clock;

    protected function setUp(): void
    {
        $this->clock = new FrozenClock(1000.0);
    }

    public function testAGloballyRejectedCallDoesNotCostTheTenantItsTokens(): void
    {
        $tenant = $this->limiter(capacity: 10);
        $limiter = new TwoTierRateLimiter($tenant, $this->limiter(capacity: 1));

        // The global tier has exactly one token, so the first call passes and the second is refused globally.
        $this->assertTrue($limiter->reserve('shop-a|/orders')->accepted);
        $this->assertFalse($limiter->reserve('shop-a|/orders')->accepted);

        /* Ten capacities, one call actually made, so nine of the tenant's own share must remain. Without the refund, the
           globally-rejected attempt was charged too, leaving eight - and creeping down with every further attempt. */
        $this->assertSame(9, $this->remainingTenantTokens($tenant, 'shop-a|/orders'));
    }

    /**
     * The failure mode in full: global pressure must not be able to empty a quiet tenant's bucket.
     */
    public function testSustainedGlobalPressureLeavesAQuietTenantItsShare(): void
    {
        $tenant = $this->limiter(capacity: 5);
        $limiter = new TwoTierRateLimiter($tenant, $this->limiter(capacity: 1));

        // Consumes the single global token.
        $limiter->reserve('shop-a|/orders');

        for ($attempt = 0; $attempt < 20; $attempt++) {
            $this->assertFalse($limiter->reserve('shop-a|/orders')->accepted);
        }

        /* Four of five left after the one call that went out; the twenty refusals cost nothing. Unrefunded, five rejections
           alone would have emptied the bucket, and this tenant would be throttled by other tenants' traffic. */
        $this->assertSame(4, $this->remainingTenantTokens($tenant, 'shop-a|/orders'));
    }

    public function testATenantRejectionNeverReachesTheGlobalTier(): void
    {
        $global = $this->limiter(capacity: 10);
        $limiter = new TwoTierRateLimiter($this->limiter(capacity: 1), $global);

        $limiter->reserve('shop-a|/orders');
        $this->assertFalse($limiter->reserve('shop-a|/orders')->accepted, 'Tenant tier is out of tokens.');

        // One global token spent, for the one call that passed both tiers.
        $this->assertSame(8, $global->reserve('_global')->remaining);
    }

    public function testRefundNeverLiftsABucketAboveItsCapacity(): void
    {
        $tenant = $this->limiter(capacity: 3);

        $tenant->refund('shop-a|/orders', 10);

        // Still 3, not 13: refunding what was never reserved must not mint capacity.
        $this->assertTrue($tenant->reserve('shop-a|/orders', 3)->accepted);
        $this->assertFalse($tenant->reserve('shop-a|/orders', 1)->accepted);
    }

    public function testRefundingBothTiersCreditsBoth(): void
    {
        $tenant = $this->limiter(capacity: 5);
        $global = $this->limiter(capacity: 5);
        $limiter = new TwoTierRateLimiter($tenant, $global);

        $limiter->reserve('shop-a|/orders', 2);
        $limiter->refund('shop-a|/orders', 2);

        $this->assertSame(3, $tenant->reserve('shop-a|/orders', 2)->remaining);
        $this->assertSame(3, $global->reserve('_global', 2)->remaining);
    }

    /**
     * A host tier that predates the refund interface must keep working, just without the refund.
     */
    public function testANonRefundableTenantTierDegradesInsteadOfFailing(): void
    {
        $limiter = new TwoTierRateLimiter(new NullRateLimiter(), $this->limiter(capacity: 1));

        $this->assertFalse($limiter->isRefundable());

        $limiter->reserve('shop-a|/orders');

        $this->assertFalse($limiter->reserve('shop-a|/orders')->accepted);
    }

    public function testARefundableTenantTierReportsItself(): void
    {
        $this->assertTrue(
            (new TwoTierRateLimiter($this->limiter(capacity: 5), $this->limiter(capacity: 5)))->isRefundable()
        );
    }

    /**
     * Tokens the tenant still holds, measured the only way the interface allows - by spending them.
     *
     * Reading `Reservation::$remaining` would need a reservation of its own and then report the count *after* it, which
     * is one off from the question every assertion here is asking.
     *
     * @param TokenBucketRateLimiter $tenant Tenant tier to drain
     * @param string $key Limiter key to drain it on
     */
    private function remainingTenantTokens(TokenBucketRateLimiter $tenant, string $key): int
    {
        $spent = 0;

        while ($tenant->reserve($key)->accepted) {
            $spent++;
        }

        return $spent;
    }

    private function limiter(int $capacity): TokenBucketRateLimiter
    {
        // A refill rate low enough that nothing refills during a test on a frozen clock.
        return new TokenBucketRateLimiter($capacity, 0.001, null, $this->clock);
    }
}
