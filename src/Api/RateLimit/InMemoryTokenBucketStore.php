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

use InvalidArgumentException;

/**
 * Process-local token-bucket store backed by a plain array.
 *
 * Atomic by construction rather than by effort: PHP does not preempt between the read and the write of an array element, and the array is not visible outside
 * this process. That makes declaring {@see AtomicTokenBucketStoreInterface} honest, and lets the limiter's swap path be exercised without a Redis.
 *
 * The map is capped. Keys are `tenantKey|endpoint`, so their number is tenants times endpoints - bounded per request in
 * php-fpm, where the process dies with the request, but in a RoadRunner or Swoole worker serving many merchants it grows
 * for the life of the worker and never shrinks. Same shape of leak the `Unknown*` enum flyweights are capped against.
 */
final class InMemoryTokenBucketStore implements AtomicTokenBucketStoreInterface
{
    /** Default ceiling on tracked buckets. See the constructor for how the number was chosen. */
    public const DEFAULT_MAX_BUCKETS = 4096;

    /**
     * Fraction of the map dropped per eviction, as a divisor. Evicting one entry per insert past the cap would make
     * every insert an O(n) scan once the key set sits above the cap; dropping a batch amortises that scan over the
     * batch, so the steady-state cost per insert stays small.
     */
    private const EVICTION_DIVISOR = 8;

    /** @var array<string, TokenBucket> */
    private array $buckets = [];

    /**
     * @param int $maxBuckets Ceiling on tracked buckets before the least recently updated are evicted
     *
     * @throws InvalidArgumentException If the ceiling is below 1
     */
    public function __construct(private readonly int $maxBuckets = self::DEFAULT_MAX_BUCKETS)
    {
        if ($this->maxBuckets < 1) {
            throw new InvalidArgumentException('Bucket ceiling must be at least 1.');
        }
    }

    /** @inheritDoc */
    public function get(string $key): ?TokenBucket
    {
        return $this->buckets[$key] ?? null;
    }

    /** @inheritDoc */
    public function set(string $key, TokenBucket $bucket): void
    {
        if (!isset($this->buckets[$key]) && count($this->buckets) >= $this->maxBuckets) {
            $this->evictLeastRecentlyUpdated();
        }

        $this->buckets[$key] = $bucket;
    }

    /** Buckets currently tracked. Exposed so a host can assert the map is not growing without a bound. */
    public function bucketCount(): int
    {
        return count($this->buckets);
    }

    /**
     * Drops the buckets whose token counts are furthest out of date.
     *
     * Eviction resets a key to a full bucket, so it always loses limiter accuracy - the question is only which key can
     * afford it. `updatedAt` answers that with what the store actually has: a bucket untouched the longest has had the
     * longest to refill, so it is the closest to full already and the one whose reset changes the least. Nothing else
     * here is safe to prefer - dropping a *recently* used key would hand a burst back to the caller hammering it
     * hardest, which is precisely the caller the limiter exists to hold.
     */
    private function evictLeastRecentlyUpdated(): void
    {
        $updatedAt = array_map(static fn (TokenBucket $bucket): float => $bucket->updatedAt, $this->buckets);

        asort($updatedAt);

        foreach (array_slice(array_keys($updatedAt), 0, (int) ceil($this->maxBuckets / self::EVICTION_DIVISOR)) as $key) {
            unset($this->buckets[$key]);
        }
    }

    /** @inheritDoc */
    public function compareAndSet(string $key, ?TokenBucket $expected, TokenBucket $new): bool
    {
        $current = $this->buckets[$key] ?? null;

        if (!self::sameBucket($current, $expected)) {
            return false;
        }

        $this->buckets[$key] = $new;

        return true;
    }

    /**
     * Value equality for two buckets, either of which may be absent.
     *
     * @param TokenBucket|null $bucket1 First bucket
     * @param TokenBucket|null $bucket2 Second bucket
     */
    private static function sameBucket(?TokenBucket $bucket1, ?TokenBucket $bucket2): bool
    {
        if ($bucket1 === null || $bucket2 === null) {
            return $bucket1 === $bucket2;
        }

        return $bucket1->tokens === $bucket2->tokens && $bucket1->updatedAt === $bucket2->updatedAt;
    }
}
