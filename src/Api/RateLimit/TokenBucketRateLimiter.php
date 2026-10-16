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

use Comfino\Api\Support\ClockInterface;
use Comfino\Api\Support\SystemClock;
use InvalidArgumentException;

/**
 * Token-bucket limiter: a bucket of {@see $capacity} tokens refilled at {@see $refillTokensPerSecond}.
 *
 * A bucket, rather than a fixed window, because the burst allowance is the useful part: a checkout render fires a handful
 * of calls at once and should not be throttled for it, while a runaway loop should be.
 *
 * Refunds are supported ({@see RefundableRateLimiterInterface}), which is what lets {@see TwoTierRateLimiter} avoid
 * charging a tenant for a call the global tier then refused.
 *
 * Use it two-tiered via {@see TwoTierRateLimiter}: a per-tenant bucket for fairness nested inside a per-process bucket
 * that protects the ComfinoPay account's own quota - one tier alone buys either an unfair limiter or an unprotected API.
 *
 * Exactness follows the store: reserving is a read-modify-write, so with a plain {@see TokenBucketStoreInterface} the limiter is exact only while the store
 * is not shared - two workers reading the same bucket each write back their own "one token spent" and the second erases the first. A store implementing
 * {@see AtomicTokenBucketStoreInterface} is swapped in automatically and makes the reservation exact across a fleet; {@see isExact()} reports which is live.
 */
final class TokenBucketRateLimiter implements RefundableRateLimiterInterface
{
    /**
     * Swap attempts before a contended reservation gives up and is rejected. A lost swap means another worker consumed from
     * the bucket; sustained contention deep enough to lose three in a row is itself the signal a limiter is supposed to act on.
     */
    private const MAX_SWAP_ATTEMPTS = 3;

    private readonly TokenBucketStoreInterface $store;
    private readonly ClockInterface $clock;

    /**
     * @param int $capacity Bucket size, i.e. the largest burst allowed
     * @param float $refillTokensPerSecond Sustained rate the bucket refills at
     * @param TokenBucketStoreInterface|null $store Where buckets are kept; defaults to process-local memory
     * @param ClockInterface|null $clock Clock used to compute the refill
     *
     * @throws InvalidArgumentException If the capacity is below 1 or the refill rate is not positive
     */
    public function __construct(
        private readonly int $capacity,
        private readonly float $refillTokensPerSecond,
        ?TokenBucketStoreInterface $store = null,
        ?ClockInterface $clock = null
    ) {
        if ($this->capacity < 1) {
            throw new InvalidArgumentException('Bucket capacity must be at least 1.');
        }

        if ($this->refillTokensPerSecond <= 0) {
            throw new InvalidArgumentException('Refill rate must be positive.');
        }

        $this->store = $store ?? new InMemoryTokenBucketStore();
        $this->clock = $clock ?? new SystemClock();
    }

    /**
     * Whether reservations are exact when the store is shared between workers - true only when the injected store implements
     * {@see AtomicTokenBucketStoreInterface}. False with a shared store means the limiter over-admits under concurrency (see
     * the class docblock); worth asserting in a host's wiring test.
     */
    public function isExact(): bool
    {
        return $this->store instanceof AtomicTokenBucketStoreInterface;
    }

    /** @inheritDoc */
    public function reserve(string $key, int $tokens = 1): Reservation
    {
        if ($tokens < 1) {
            throw new InvalidArgumentException('Reservation must cost at least 1 token.');
        }

        if ($tokens > $this->capacity) {
            /* A cost the bucket can never hold would otherwise wait forever. Reject it outright with the time the bucket
               needs to refill completely, which is the most informative answer available. */
            return Reservation::rejected((int) ceil(1000 * $this->capacity / $this->refillTokensPerSecond));
        }

        if (!$this->store instanceof AtomicTokenBucketStoreInterface) {
            return $this->reserveWithoutSwap($key, $tokens);
        }

        for ($attempt = 0; $attempt < self::MAX_SWAP_ATTEMPTS; $attempt++) {
            $now = $this->clock->now();
            $bucket = $this->store->get($key);
            $available = $this->availableAt($bucket, $now);

            if ($available < $tokens) {
                return $this->reject($tokens, $available);
            }

            if ($this->store->compareAndSet($key, $bucket, new TokenBucket($available - $tokens, $now))) {
                return Reservation::accepted((int) floor($available - $tokens));
            }
        }

        /* Every swap lost, so another worker is consuming this bucket as fast as we are reading it. Rejecting is the safe direction: admitting after a failed
           swap is precisely the over-admission the swap exists to prevent, and one token's worth of refill is an honest thing to ask the caller to wait for. */
        return Reservation::rejected((int) ceil(1000 / $this->refillTokensPerSecond));
    }

    /** @inheritDoc */
    public function refund(string $key, int $tokens = 1): void
    {
        if ($tokens < 1) {
            return;
        }

        if (!$this->store instanceof AtomicTokenBucketStoreInterface) {
            $now = $this->clock->now();

            $this->store->set($key, new TokenBucket($this->refundedTokens($this->store->get($key), $now, $tokens), $now));

            return;
        }

        for ($attempt = 0; $attempt < self::MAX_SWAP_ATTEMPTS; $attempt++) {
            $now = $this->clock->now();
            $bucket = $this->store->get($key);
            $refunded = new TokenBucket($this->refundedTokens($bucket, $now, $tokens), $now);

            if ($this->store->compareAndSet($key, $bucket, $refunded)) {
                return;
            }
        }

        /* Every swap lost. Unlike a reservation, a dropped refund is safe in the direction that matters: the caller is
           charged for a call it did not make, which throttles it slightly rather than admitting traffic it was not
           entitled to. Writing unconditionally here would instead clobber whatever the winning worker just reserved. */
    }

    /**
     * Tokens the bucket should hold after returning $tokens to it, never above the capacity.
     *
     * @param TokenBucket|null $bucket Stored bucket, or null when the key has expired out of the store
     * @param float $now Current Unix timestamp
     * @param int $tokens Token count being returned
     */
    private function refundedTokens(?TokenBucket $bucket, float $now, int $tokens): float
    {
        return min($this->capacity, $this->availableAt($bucket, $now) + $tokens);
    }

    /**
     * The get-then-set path, for a store that cannot swap. Kept as its own method rather than folded into a one-iteration
     * loop, so it reads as a deliberate branch, not a degenerate case of the swap loop.
     */
    private function reserveWithoutSwap(string $key, int $tokens): Reservation
    {
        $now = $this->clock->now();
        $available = $this->availableAt($this->store->get($key), $now);

        if ($available < $tokens) {
            return $this->reject($tokens, $available);
        }

        $this->store->set($key, new TokenBucket($available - $tokens, $now));

        return Reservation::accepted((int) floor($available - $tokens));
    }

    /**
     * Tokens the bucket holds at $now, refilled and capped at the capacity.
     *
     * @param TokenBucket|null $bucket The stored bucket, or null when the key has never been used
     * @param float $now Current Unix timestamp
     */
    private function availableAt(?TokenBucket $bucket, float $now): float
    {
        if ($bucket === null) {
            return (float) $this->capacity;
        }

        return min($this->capacity, $bucket->tokens + ($now - $bucket->updatedAt) * $this->refillTokensPerSecond);
    }

    /**
     * The rejection, carrying the wait the missing tokens actually need.
     *
     * The refilled bucket is deliberately not written back: the read is idempotent, and skipping the write keeps a
     * rejected burst from costing a store round trip per call.
     *
     * @param int $tokens Cost that could not be met
     * @param float $available Tokens the bucket held
     */
    private function reject(int $tokens, float $available): Reservation
    {
        return Reservation::rejected((int) ceil(1000 * ($tokens - $available) / $this->refillTokensPerSecond));
    }
}
