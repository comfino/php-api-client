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
 * A token-bucket store that can replace a bucket only if nobody else changed it first.
 *
 * {@see TokenBucketStoreInterface} is get-then-set: two workers reading the same bucket each subtract their own cost and write back "one token spent", so the
 * second write erases the first and the limiter admits twice its configured rate. The compare-and-swap must live in the backend, since only it can make the
 * read and the write one operation (`WATCH`/`MULTI` or a Lua script on Redis, a conditional `UPDATE` on SQL, `apcu_cas()` on APCu) - that is why this is an
 * interface rather than something {@see TokenBucketRateLimiter} could arrange on its own.
 *
 * Implementing it is optional: the limiter detects it and switches to a retry loop, while a store implementing only the plain interface keeps working as before
 * and is exact only while it is not actually shared.
 */
interface AtomicTokenBucketStoreInterface extends TokenBucketStoreInterface
{
    /**
     * Stores $new for $key only if the stored value still equals $expected (by value, not identity - the object read back from the backend is a fresh
     * instance). A null $expected asserts no bucket exists yet, so the first reservation for a key doesn't race another worker's first reservation. Must not
     * block: waiting on a lock turns a rate limit into a latency problem for the caller it was meant to protect - return false and let it retry.
     *
     * @param string $key Limiter key
     * @param TokenBucket|null $expected The value the caller read, or null if it read nothing
     * @param TokenBucket $new The value to store
     *
     * @return bool True when the swap happened, false when the stored value had changed and nothing was written
     */
    public function compareAndSet(string $key, ?TokenBucket $expected, TokenBucket $new): bool;
}
