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
 * that protects the Comfino account's own quota. One tier alone gets you either an unfair limiter or an unprotected
 * API.
 */
final class TokenBucketRateLimiter implements OutboundRateLimiterInterface
{
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

        $now = $this->clock->now();
        $bucket = $this->store->get($key);
        $available = $bucket === null
            ? (float) $this->capacity
            : min($this->capacity, $bucket->tokens + ($now - $bucket->updatedAt) * $this->refillTokensPerSecond);

        if ($available < $tokens) {
            /* Do not write the refilled bucket back on rejection: the read is idempotent and skipping the writing keeps
               a rejected burst from costing a store round trip per call. */
            return Reservation::rejected(
                (int) ceil(1000 * ($tokens - $available) / $this->refillTokensPerSecond)
            );
        }

        $this->store->set($key, new TokenBucket($available - $tokens, $now));

        return Reservation::accepted((int) floor($available - $tokens));
    }
}
