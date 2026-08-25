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
 * A token-bucket store that can replace a bucket **only if nobody else changed it first**.
 *
 * {@see TokenBucketStoreInterface} is get-then-set, which is correct in one process and wrong across several. Two
 * workers read the same bucket, each subtracts its own cost, and each writes back "one token spent" - so the second
 * write erases the first and the limiter admits twice its configured rate. That is not a rounding error: a limiter
 * over-admits exactly when it is under load, which is the only time it was doing anything.
 *
 * The compare-and-swap has to live where the storage is, because only the backend can make the read and the write one
 * operation - `WATCH`/`MULTI` or a Lua script on Redis, `UPDATE ... WHERE tokens = ? AND updated_at = ?` on SQL, an
 * `apcu_cas()` on APCu. That is why this is an interface rather than something {@see TokenBucketRateLimiter} could
 * arrange on its own.
 *
 * Implementing it is optional. The limiter detects it and switches to a retry loop; a store that implements only the
 * plain interface keeps working exactly as before, and is exact only while the store is not actually shared.
 *
 * | Store                                 | Shared across workers | Exact |
 * |---------------------------------------|-----------------------|-------|
 * | {@see InMemoryTokenBucketStore}       | no                    | yes   |
 * | plain interface over Redis/SQL/PSR-6  | yes                   | **no**|
 * | this interface over Redis/SQL/APCu    | yes                   | yes   |
 */
interface AtomicTokenBucketStoreInterface extends TokenBucketStoreInterface
{
    /**
     * Stores $new for $key if and only if the stored value is still $expected.
     *
     * "Still $expected" means value equality, not object identity: an implementation compares the token count and the
     * timestamp, because the object it read back from its backend is a fresh instance. A null $expected asserts that
     * no bucket is stored for the key, which is how the first reservation for a key is written without racing another
     * worker's first reservation.
     *
     * Must not block: a limiter that waits for a lock has converted a rate limit into a latency problem for the caller
     * it was protecting. Return false and let the caller retry.
     *
     * @param string $key Limiter key
     * @param TokenBucket|null $expected The value the caller read, or null if it read nothing
     * @param TokenBucket $new The value to store
     *
     * @return bool True when the swap happened, false when the stored value had changed and nothing was written
     */
    public function compareAndSet(string $key, ?TokenBucket $expected, TokenBucket $new): bool;
}
