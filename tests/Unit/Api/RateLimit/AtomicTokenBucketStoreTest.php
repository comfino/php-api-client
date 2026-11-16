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

use Comfino\Api\RateLimit\InMemoryTokenBucketStore;
use Comfino\Api\RateLimit\TokenBucket;
use Comfino\Api\RateLimit\TokenBucketRateLimiter;
use Comfino\Api\RateLimit\TokenBucketStoreInterface;
use Comfino\Api\Support\FrozenClock;
use Comfino\Tests\Unit\Api\Stub\PlainTokenBucketStore;
use Comfino\Tests\Unit\Api\Stub\SwapRefusingTokenBucketStore;
use PHPUnit\Framework\TestCase;

/**
 * Reserving against a store that can compare-and-swap.
 *
 * The property being protected is that a shared store stops over-admitting. A get-then-set store cannot deliver it —
 * two workers read the same bucket, both subtract their own cost, and the second write erases the first, so a limiter
 * configured for one burst allows one burst *per worker*. That is the failure mode the swap exists for, and it appears
 * only under load, which is the only time the limiter mattered.
 */
final class AtomicTokenBucketStoreTest extends TestCase
{
    private FrozenClock $clock;

    protected function setUp(): void
    {
        $this->clock = new FrozenClock(1000.0);
    }

    public function testTheLimiterReportsWhetherItCanSwap(): void
    {
        $this->assertTrue($this->limiter(new InMemoryTokenBucketStore())->isExact());
        $this->assertFalse(
            $this->limiter(new PlainTokenBucketStore())->isExact(),
            'A store that cannot swap is not exact — a host asserting this is how the wiring mistake gets caught.'
        );
    }

    public function testAStoreThatCannotSwapStillWorks(): void
    {
        $limiter = $this->limiter(new PlainTokenBucketStore());

        $this->assertTrue($limiter->reserve('key')->accepted);
        $this->assertTrue($limiter->reserve('key')->accepted);
        $this->assertFalse($limiter->reserve('key')->accepted, 'The capacity is two; the third reservation is rejected.');
    }

    public function testALostSwapIsRetriedRatherThanRejected(): void
    {
        $store = new SwapRefusingTokenBucketStore(refusals: 2);
        $limiter = $this->limiter($store);

        $reservation = $limiter->reserve('key');

        $this->assertTrue($reservation->accepted, 'Two workers beating us to the swap is not a reason to reject.');
        $this->assertSame(3, $store->swapAttempts);
    }

    /**
     * The safe direction on exhaustion.
     *
     * Admitting after three lost swaps would be exactly the over-admission the swap exists to prevent — the caller
     * has no idea what the bucket holds by then. The rejection asks for one token's worth of refill, which is the one
     * honest number available.
     */
    public function testAReservationThatLosesEverySwapIsRejected(): void
    {
        $store = new SwapRefusingTokenBucketStore(refusals: 99);

        $reservation = $this->limiter($store)->reserve('key');

        $this->assertFalse($reservation->accepted);
        $this->assertSame(1000, $reservation->retryAfterMs, 'One token at one token per second.');
        $this->assertSame(3, $store->swapAttempts, 'Three attempts, then the rejection — not an unbounded loop.');
    }

    public function testASwapAgainstAStaleValueWritesNothing(): void
    {
        $store = new InMemoryTokenBucketStore();
        $store->set('key', new TokenBucket(5.0, 1000.0));

        $this->assertFalse(
            $store->compareAndSet('key', new TokenBucket(4.0, 1000.0), new TokenBucket(0.0, 1000.0)),
            'The caller read 4 tokens where 5 are stored: another worker wrote in between.'
        );
        $this->assertSame(5.0, $store->get('key')?->tokens);
    }

    public function testASwapAgainstAnAbsentValueSucceedsOnlyWhileItIsAbsent(): void
    {
        $store = new InMemoryTokenBucketStore();

        $this->assertTrue($store->compareAndSet('key', null, new TokenBucket(1.0, 1000.0)));
        $this->assertFalse(
            $store->compareAndSet('key', null, new TokenBucket(2.0, 1000.0)),
            'Asserting absence must fail once the key exists: two first reservations cannot both win.'
        );
    }

    private function limiter(object $store): TokenBucketRateLimiter
    {
        /** @var TokenBucketStoreInterface $store */
        return new TokenBucketRateLimiter(2, 1.0, $store, $this->clock);
    }
}
