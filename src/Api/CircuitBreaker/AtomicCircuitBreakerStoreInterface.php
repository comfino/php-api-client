<?php

/**
 * ComfinoPay PHP API client
 *
 * Backend routines for communication with the ComfinoPay payment gateway REST API.
 *
 * @package Comfino\Api\CircuitBreaker
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-api-client
 */

declare(strict_types=1);

namespace Comfino\Api\CircuitBreaker;

/**
 * A breaker store that can replace a state only if nobody else changed it first.
 *
 * The breaker survives a get-then-set store better than the limiter does - a lost failure increment just delays opening by one call - except for the half-open
 * probe: when the open window elapses, every worker that reads the same key concludes "my turn to probe" and they all probe at once, the thundering herd a
 * breaker exists to prevent. Claiming the probe with a swap means exactly one worker gets it and the rest keep failing fast.
 *
 * Implementing this is optional; {@see CircuitBreaker} detects it and uses it where it helps. See {@see \Comfino\Api\RateLimit\AtomicTokenBucketStoreInterface}
 * for why the atomicity cannot live anywhere but the store.
 */
interface AtomicCircuitBreakerStoreInterface extends CircuitBreakerStoreInterface
{
    /**
     * Stores $new for $key only if the stored value still equals $expected (value equality: failure count and opened-at
     * stamp, not object identity). A null $expected asserts nothing is stored for the key. Must not block - return false
     * and let the caller decide, usually "another worker got there first, which is fine".
     *
     * @param string $key Breaker key
     * @param CircuitBreakerState|null $expected The state the caller read, or null if it read nothing
     * @param CircuitBreakerState $new The state to store
     *
     * @return bool True when the swap happened, false when the stored state had changed and nothing was written
     */
    public function compareAndSet(string $key, ?CircuitBreakerState $expected, CircuitBreakerState $new): bool;
}
