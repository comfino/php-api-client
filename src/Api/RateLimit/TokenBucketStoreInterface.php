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
 * Where a {@see TokenBucketRateLimiter} keeps its per-key bucket.
 *
 * Separate from the limiter for the same reason as the breaker's store: a plugin wants process-local or APCu state, a
 * connector wants state its whole fleet agrees on.
 *
 * **This interface cannot make a shared store correct**, and that is worth knowing before building one on it.
 * Reserving is a read-modify-write, so two workers sharing a store both read the same bucket and both write back their
 * own result - the limiter then admits up to one burst per worker instead of one in total. Implement
 * {@see AtomicTokenBucketStoreInterface} as well for anything a second process can see; the limiter picks it up on its
 * own and {@see TokenBucketRateLimiter::isExact()} reports which path is live.
 */
interface TokenBucketStoreInterface
{
    /**
     * Returns the stored bucket for the key or null when nothing is recorded.
     *
     * @param string $key Limiter key
     */
    public function get(string $key): ?TokenBucket;

    /**
     * Stores the bucket for the key.
     *
     * @param string $key Limiter key
     * @param TokenBucket $bucket Bucket to store
     */
    public function set(string $key, TokenBucket $bucket): void;
}
