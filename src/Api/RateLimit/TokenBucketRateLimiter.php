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
 * A bucket, rather than a fixed window, because the burst allowance is the useful part: a checkout render fires a
 * handful of calls at once and should not be throttled for it, while a runaway loop should be.
 *
 * Use it two-tiered via {@see TwoTierRateLimiter}: a per-tenant bucket for fairness nested inside a per-process bucket
 * that protects the ComfinoPay account's own quota. One tier alone gets you either an unfair limiter or an unprotected
 * API.
 *
 * **Exactness follows the store.** Reserving is a read-modify-write, so with a plain {@see TokenBucketStoreInterface}
 * the limiter is exact only while the store is not shared - two workers reading the same bucket each write back their
 * own "one token spent" and the second erases the first. A store implementing {@see AtomicTokenBucketStoreInterface}
 * is swapped in automatically and makes the reservation exact across a fleet; {@see isExact()} reports which of the
 * two is in effect, so a host can assert it in a test rather than hope.
 */
final class TokenBucketRateLimiter implements OutboundRateLimiterInterface
{
    /**
     * Swap attempts before a contended reservation gives up and is rejected.
     *
     * Three, because a lost swap means another worker consumed from this bucket, and re-reading it either finds tokens
     * left (the loop succeeds) or finds it empty (the loop rejects, correctly). Sustained contention deep enough to
     * lose three swaps in a row is itself the signal a limiter is supposed to act on.
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
     * Whether reservations are exact when the store is shared between workers.
     *
     * True exactly when the injected store implements {@see AtomicTokenBucketStoreInterface}. A false here with a
     * shared store means the limiter over-admits under concurrency - see the class docblock - and is worth asserting
     * in a host's wiring test, because nothing else about the configuration looks different.
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
            /* A cost the bucket can never hold would otherwise wait forever. Reject it outright with the time the
               bucket needs to refill completely, which is the most informative answer available. */
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

        /* Every swap lost, so another worker is consuming this bucket as fast as we are reading it. Rejecting is the
           safe direction: admitting after a failed swap is precisely the over-admission the swap exists to prevent,
           and one token's worth of refill is an honest thing to ask the caller to wait for. */
        return Reservation::rejected((int) ceil(1000 / $this->refillTokensPerSecond));
    }

    /**
     * The get-then-set path, for a store that cannot swap.
     *
     * Kept as its own method rather than folded into a one-iteration loop: this is the behavior every 3.0 host has
     * today, it is exact whenever the store is process-local, and it should read as a deliberate branch rather than as
     * a degenerate case of the other one.
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
