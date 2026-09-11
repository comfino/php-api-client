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

use InvalidArgumentException;

/**
 * Process-local breaker store backed by a plain array.
 *
 * Enough for a php-fpm plugin, where the process serves one request and dies, and for tests. A connector whose workers must agree on whether
 * a host is down needs a shared store instead, since the point of a breaker is that the second worker benefits from what the first one learned.
 *
 * Atomic by construction: PHP does not preempt between the read and the write of an array element, and the array is private to this process.
 *
 * The map is capped: a php-fpm process dies with the request, but a RoadRunner or Swoole worker serving many merchants
 * accumulates a key per (tenant, host) pair for the life of the worker and never shrinks.
 */
final class InMemoryCircuitBreakerStore implements AtomicCircuitBreakerStoreInterface
{
    /** Default ceiling on tracked keys. Keys are `tenantKey|host`, so this is merchants times API hosts. */
    public const DEFAULT_MAX_KEYS = 4096;

    /** Fraction of the map dropped per eviction, as a divisor - see {@see InMemoryTokenBucketStore}. */
    private const EVICTION_DIVISOR = 8;

    /** @var array<string, CircuitBreakerState> */
    private array $states = [];

    /**
     * @param int $maxKeys Ceiling on tracked keys before the least consequential state is evicted
     *
     * @throws InvalidArgumentException If the ceiling is below 1
     */
    public function __construct(private readonly int $maxKeys = self::DEFAULT_MAX_KEYS)
    {
        if ($this->maxKeys < 1) {
            throw new InvalidArgumentException('Key ceiling must be at least 1.');
        }
    }

    /** @inheritDoc */
    public function get(string $key): ?CircuitBreakerState
    {
        return $this->states[$key] ?? null;
    }

    /** @inheritDoc */
    public function set(string $key, CircuitBreakerState $state): void
    {
        if (!isset($this->states[$key]) && count($this->states) >= $this->maxKeys) {
            $this->evictLeastConsequential();
        }

        $this->states[$key] = $state;
    }

    /** Keys currently tracked. Exposed so a host can assert the map is not growing without a bound. */
    public function keyCount(): int
    {
        return count($this->states);
    }

    /**
     * Drops the state whose loss costs the least: closed breakers first, then the breakers open the longest.
     *
     * A *closed* state holds nothing but a partial failure count, and losing that only delays the breaker opening - the
     * class already accepts that trade for a lost swap ("an undercount delays opening"). An *open* state is the one
     * worth keeping, because dropping it lets a fleet back onto a host that is still down; among those, the one open
     * longest is closest to its window elapsing and a probe anyway, so it is the cheapest of the expensive ones.
     */
    private function evictLeastConsequential(): void
    {
        // Sorts closed states (openedAt null -> -INF) ahead of open ones, and older openedAt ahead of newer, in one ordering.
        $rank = array_map(
            static fn (CircuitBreakerState $state): float => $state->openedAt ?? -INF,
            $this->states
        );

        asort($rank);

        foreach (array_slice(array_keys($rank), 0, (int) ceil($this->maxKeys / self::EVICTION_DIVISOR)) as $key) {
            unset($this->states[$key]);
        }
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
     * Value equality for two states, either of which may be absent - every field included.
     *
     * `probeStartedAt` in particular: {@see CircuitBreaker::claimProbe} claims the half-open probe with a swap whose new
     * state differs from the expected one in *only* that field, carrying the failure count and `openedAt` through unchanged.
     * Comparing the other two fields alone made that swap succeed unconditionally, so the claim degenerated to the blind
     * write it exists to replace and no test could observe a lost race. Since this class is the reference every shared
     * store (APCu, Redis, a database row) is written against, the omission would have been copied into the stores where
     * the race is real, and a fleet would then send one probe per worker per open window - exactly the stampede at a host
     * already known to be failing that the breaker is there to prevent.
     *
     * @param CircuitBreakerState|null $state1 First state
     * @param CircuitBreakerState|null $state2 Second state
     */
    private static function sameState(?CircuitBreakerState $state1, ?CircuitBreakerState $state2): bool
    {
        if ($state1 === null || $state2 === null) {
            return $state1 === $state2;
        }

        return $state1->consecutiveFailures === $state2->consecutiveFailures &&
            $state1->openedAt === $state2->openedAt && $state1->probeStartedAt === $state2->probeStartedAt;
    }
}
