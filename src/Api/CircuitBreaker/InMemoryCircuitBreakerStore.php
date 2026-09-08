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
 * Process-local breaker store backed by a plain array.
 *
 * Enough for a php-fpm plugin, where the process serves one request and dies, and for tests. A connector whose workers
 * must agree on whether a host is down needs a shared store instead - the point of a breaker is that the second worker
 * benefits from what the first one learned.
 *
 * Atomic by construction: PHP does not preempt between the read and the write of an array element, and the array is
 * private to this process.
 */
final class InMemoryCircuitBreakerStore implements AtomicCircuitBreakerStoreInterface
{
    /** @var array<string, CircuitBreakerState> */
    private array $states = [];

    /** @inheritDoc */
    public function get(string $key): ?CircuitBreakerState
    {
        return $this->states[$key] ?? null;
    }

    /** @inheritDoc */
    public function set(string $key, CircuitBreakerState $state): void
    {
        $this->states[$key] = $state;
    }

    /** @inheritDoc */
    public function delete(string $key): void
    {
        unset($this->states[$key]);
    }

    /** @inheritDoc */
    public function compareAndSet(string $key, ?CircuitBreakerState $expected, CircuitBreakerState $new): bool
    {
        $current = $this->states[$key] ?? null;

        if (!self::sameState($current, $expected)) {
            return false;
        }

        $this->states[$key] = $new;

        return true;
    }

    /**
     * Value equality for two states, either of which may be absent.
     *
     * @param CircuitBreakerState|null $state1 First state
     * @param CircuitBreakerState|null $state2 Second state
     */
    private static function sameState(?CircuitBreakerState $state1, ?CircuitBreakerState $state2): bool
    {
        if ($state1 === null || $state2 === null) {
            return $state1 === $state2;
        }

        return $state1->consecutiveFailures === $state2->consecutiveFailures && $state1->openedAt === $state2->openedAt;
    }
}
