<?php

/**
 * ComfinoPay PHP API client
 *
 * Backend routines for communication with the ComfinoPay payment gateway REST API.
 *
 * @package Comfino\Tests\Unit\Api\Stub
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-api-client
 */

declare(strict_types=1);

namespace Comfino\Tests\Unit\Api\Stub;

use Comfino\Api\CircuitBreaker\AtomicCircuitBreakerStoreInterface;
use Comfino\Api\CircuitBreaker\CircuitBreakerState;
use Comfino\Api\CircuitBreaker\InMemoryCircuitBreakerStore;

/**
 * An atomic breaker store that runs a callback right after a read, so a test can land another worker's write in the gap
 * between one caller's `get()` and its `compareAndSet()`.
 *
 * A genuine race needs two processes, and the in-memory store cannot lose one - the write always lands before the next
 * read. That is why the probe claim can look correct while its swap is doing nothing: the sequential pre-check on
 * `probeStartedAt` blocks the second caller regardless of whether the compare-and-set has any teeth. Scripting the
 * interleaving is what makes the swap itself observable, and therefore what a shared-store implementation is really
 * being held to.
 */
final class RacingCircuitBreakerStore implements AtomicCircuitBreakerStoreInterface
{
    private readonly InMemoryCircuitBreakerStore $inner;

    /** @var (callable(string): void)|null Runs once, immediately after the next read. */
    private $onNextGet = null;

    public function __construct()
    {
        $this->inner = new InMemoryCircuitBreakerStore();
    }

    /**
     * Arranges for $writer to run immediately after the next `get()` returns, standing in for a competing worker that
     * got there first.
     *
     * @param callable(string): void $writer Receives the key that was read
     */
    public function onNextGet(callable $writer): void
    {
        $this->onNextGet = $writer;
    }

    public function get(string $key): ?CircuitBreakerState
    {
        $state = $this->inner->get($key);

        if ($this->onNextGet !== null) {
            $writer = $this->onNextGet;
            $this->onNextGet = null;

            $writer($key);
        }

        return $state;
    }

    public function set(string $key, CircuitBreakerState $state): void
    {
        $this->inner->set($key, $state);
    }

    public function delete(string $key): void
    {
        $this->inner->delete($key);
    }

    public function compareAndSet(string $key, ?CircuitBreakerState $expected, CircuitBreakerState $new): bool
    {
        return $this->inner->compareAndSet($key, $expected, $new);
    }
}
