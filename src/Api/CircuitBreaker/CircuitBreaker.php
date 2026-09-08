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
 *
 * The probe is stamped on the state ({@see CircuitBreakerState::$probeStartedAt}) and `openedAt` is kept, so the
 * breaker stays open to everyone but the caller holding the probe. A probe older than the open window is treated
 * as lost and re-claimed, so a caller that dies mid-probe cannot wedge the breaker open.
 *
 * With a plain {@see CircuitBreakerStoreInterface} the claim is a blind write, which is exact in one process and
 * loses a genuine race between workers - two that read before either wrote both probe. A store implementing
 * {@see AtomicCircuitBreakerStoreInterface} makes the claim a swap, so exactly one worker wins however they
 * interleave. {@see isExact()} reports which of the two is in effect.
 */
final class CircuitBreaker implements CircuitBreakerInterface
{
    /** Consecutive transport-level or 5xx failures that open the breaker. */
    public const DEFAULT_FAILURE_THRESHOLD = 5;

    /** Seconds the breaker stays open before it lets a probe through. */
    public const DEFAULT_OPEN_DURATION_SECONDS = 30;

    /** Swap attempts before an increment is written unconditionally. See {@see recordFailure()}. */
    private const MAX_SWAP_ATTEMPTS = 3;

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

        // The open window has elapsed: one caller gets a probe, everyone else keeps failing fast.
        return !$this->claimProbe($key, $state);
    }

    /**
     * Tries to become the one caller that probes a half-open breaker.
     *
     * The failure count is carried through, so a probe that fails re-opens the breaker immediately instead of starting
     * the count again from zero, and `openedAt` is carried through as well - that is what keeps the breaker reading as
     * open to every other caller while this one probes.
     *
     * @param string $key Breaker key
     * @param CircuitBreakerState $state State read by the caller, whose open window has elapsed
     *
     * @return bool True when this caller may probe, false when someone else holds the probe
     */
    private function claimProbe(string $key, CircuitBreakerState $state): bool
    {
        $now = $this->clock->now();

        if ($state->probeStartedAt !== null && $now - $state->probeStartedAt < $this->openDurationSeconds) {
            // Another caller is probing right now. Its outcome will close or re-open the breaker for all of us.
            return false;
        }

        $probing = new CircuitBreakerState($state->consecutiveFailures, $state->openedAt, $now);

        if ($this->store instanceof AtomicCircuitBreakerStoreInterface) {
            /* Losing the swap means another caller claimed the probe between our read and our write, and the honest
               answer for this one is then "still open" - not "probe as well", which is the whole fleet probing at
               once. */
            return $this->store->compareAndSet($key, $state, $probing);
        }

        /* A blind write: correct for a process-local store, and for a shared one it claims the probe for anyone whose
           read raced ours. See the class docblock - that is the cost of a store that cannot swap. */
        $this->store->set($key, $probing);

        return true;
    }

    /**
     * Whether the half-open probe is claimed rather than assumed when the store is shared between workers.
     *
     * True exactly when the injected store implements {@see AtomicCircuitBreakerStoreInterface}. A false here is not a
     * correctness bug the way it is for the rate limiter - the failure count is a heuristic and losing an increment
     * only delays opening - but it does mean the probe is per worker, so a fleet of N workers sends N probes per open
     * window.
     */
    public function isExact(): bool
    {
        return $this->store instanceof AtomicCircuitBreakerStoreInterface;
    }

    /** @inheritDoc */
    public function recordSuccess(string $key): void
    {
        $this->store->delete($key);
    }

    /** @inheritDoc */
    public function recordFailure(string $key): void
    {
        if (!$this->store instanceof AtomicCircuitBreakerStoreInterface) {
            $this->writeFailure($key, $this->store->get($key));

            return;
        }

        for ($attempt = 0; $attempt < self::MAX_SWAP_ATTEMPTS; $attempt++) {
            $current = $this->store->get($key);

            if ($this->store->compareAndSet($key, $current, $this->nextFailureState($current))) {
                return;
            }
        }

        /* Every swap lost, which means other workers are recording failures for this key at the same time - so the
           breaker is learning what it needs to anyway. Write unconditionally rather than drop the observation: an
           undercount delays opening, and silently discarding a failure is the one outcome with no upside. */
        $this->writeFailure($key, $this->store->get($key));
    }

    /**
     * Records the increment with a plain write.
     *
     * @param string $key Breaker key
     * @param CircuitBreakerState|null $current State read immediately before, or null when nothing is stored
     */
    private function writeFailure(string $key, ?CircuitBreakerState $current): void
    {
        $this->store->set($key, $this->nextFailureState($current));
    }

    /**
     * The state one failure after $current, opening the breaker when the threshold is reached.
     *
     * @param CircuitBreakerState|null $current State read immediately before, or null when nothing is stored
     */
    private function nextFailureState(?CircuitBreakerState $current): CircuitBreakerState
    {
        $failures = ($current->consecutiveFailures ?? 0) + 1;

        /* The probe stamp is deliberately dropped: this failure *is* the probe's outcome, so the next caller after the
           re-opened window must be able to claim a fresh one. */
        return new CircuitBreakerState($failures, $failures >= $this->failureThreshold ? $this->clock->now() : null);
    }
}
