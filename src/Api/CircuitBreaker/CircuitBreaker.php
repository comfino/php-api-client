<?php

/**
 * Comfino PHP API client
 *
 * Backend routines for communication with the Comfino payment gateway REST API.
 *
 * @package Comfino\Api\CircuitBreaker
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-api-client
 */

declare(strict_types=1);

namespace Comfino\Api\CircuitBreaker;

use Comfino\Api\Support\ClockInterface;
use Comfino\Api\Support\SystemClock;
use InvalidArgumentException;

/**
 * Consecutive-failure circuit breaker with a half-open probe.
 *
 * Closed until {@see $failureThreshold} consecutive failures are recorded, then open for {@see $openDurationSeconds}.
 * After that one probe is let through (half-open): a success closes the breaker, a failure re-opens it for another
 * window. State lives in an injected store, so the policy here is identical whether the state is process-local or
 * shared across a fleet.
 */
final class CircuitBreaker implements CircuitBreakerInterface
{
    /** Consecutive transport-level or 5xx failures that open the breaker. */
    public const DEFAULT_FAILURE_THRESHOLD = 5;

    /** Seconds the breaker stays open before it lets a probe through. */
    public const DEFAULT_OPEN_DURATION_SECONDS = 30;

    private readonly CircuitBreakerStoreInterface $store;
    private readonly ClockInterface $clock;

    /**
     * @param CircuitBreakerStoreInterface|null $store Where per-key state is kept; defaults to process-local memory
     * @param int $failureThreshold Consecutive failures that open the breaker
     * @param int $openDurationSeconds Seconds the breaker stays open before a half-open probe
     * @param ClockInterface|null $clock Clock used to time the open window
     *
     * @throws InvalidArgumentException If the threshold is below 1 or the open duration is negative
     */
    public function __construct(
        ?CircuitBreakerStoreInterface $store = null,
        private readonly int $failureThreshold = self::DEFAULT_FAILURE_THRESHOLD,
        private readonly int $openDurationSeconds = self::DEFAULT_OPEN_DURATION_SECONDS,
        ?ClockInterface $clock = null
    ) {
        if ($this->failureThreshold < 1) {
            throw new InvalidArgumentException('Failure threshold must be at least 1.');
        }

        if ($this->openDurationSeconds < 0) {
            throw new InvalidArgumentException('Open duration cannot be negative.');
        }

        $this->store = $store ?? new InMemoryCircuitBreakerStore();
        $this->clock = $clock ?? new SystemClock();
    }

    /** @inheritDoc */
    public function isOpen(string $key): bool
    {
        $state = $this->store->get($key);

        if ($state === null || $state->openedAt === null) {
            return false;
        }

        if ($this->clock->now() - $state->openedAt < $this->openDurationSeconds) {
            return true;
        }

        /* The open window has elapsed: let exactly one call through as a probe. The failure count is preserved, so a
           probe that fails re-opens the breaker immediately rather than starting the count again from zero. */
        $this->store->set($key, new CircuitBreakerState($state->consecutiveFailures, null));

        return false;
    }

    /** @inheritDoc */
    public function recordSuccess(string $key): void
    {
        $this->store->delete($key);
    }

    /** @inheritDoc */
    public function recordFailure(string $key): void
    {
        $failures = ($this->store->get($key)->consecutiveFailures ?? 0) + 1;

        $this->store->set(
            $key,
            new CircuitBreakerState($failures, $failures >= $this->failureThreshold ? $this->clock->now() : null)
        );
    }
}
