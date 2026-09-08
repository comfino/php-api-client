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
 * A breaker store that can replace a state **only if nobody else changed it first**.
 *
 * The breaker survives a get-then-set store better than the limiter does: a lost failure increment delays opening by
 * one call, and the threshold is a heuristic anyway. One thing does get materially better with a compare-and-swap,
 * and it is the half-open probe. When the open window elapses, every worker that looks at the same key concludes
 * "my turn to probe" and they all probe at once - so a host that is down gets a synchronized burst from the whole
 * fleet each time the window rolls over, which is the thundering herd the breaker exists to prevent. Claiming the
 * probe with a swap means exactly one worker gets it and the rest keep failing fast.
 *
 * Implementing this is optional; {@see CircuitBreaker} detects it and uses it where it helps. See
 * {@see \Comfino\Api\RateLimit\AtomicTokenBucketStoreInterface} for why the atomicity cannot live anywhere but the
 * store.
 */
interface AtomicCircuitBreakerStoreInterface extends CircuitBreakerStoreInterface
{
    /**
     * Stores $new for $key if and only if the stored value is still $expected.
     *
     * Value equality, not object identity - an implementation compares the failure count and the opened-at stamp. A
     * null $expected asserts that nothing is stored for the key.
     *
     * Must not block. Return false and let the caller decide; for a breaker the caller's answer is usually "another
     * worker got there first, which is fine".
     *
     * @param string $key Breaker key
     * @param CircuitBreakerState|null $expected The state the caller read, or null if it read nothing
     * @param CircuitBreakerState $new The state to store
     *
     * @return bool True when the swap happened, false when the stored state had changed and nothing was written
     */
    public function compareAndSet(string $key, ?CircuitBreakerState $expected, CircuitBreakerState $new): bool;
}
