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
 * Process-local token-bucket store backed by a plain array.
 *
 * Atomic by construction rather than by effort: PHP does not preempt between the read and the write of an array
 * element, and the array is not visible outside this process. Declaring
 * {@see AtomicTokenBucketStoreInterface} is therefore honest, and it is what lets the limiter's swap path be exercised
 * without a Redis.
 */
final class InMemoryTokenBucketStore implements AtomicTokenBucketStoreInterface
{
    /** @var array<string, TokenBucket> */
    private array $buckets = [];

    /** @inheritDoc */
    public function get(string $key): ?TokenBucket
    {
        return $this->buckets[$key] ?? null;
    }

    /** @inheritDoc */
    public function set(string $key, TokenBucket $bucket): void
    {
        $this->buckets[$key] = $bucket;
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
