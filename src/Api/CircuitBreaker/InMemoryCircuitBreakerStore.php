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

/**
 * Process-local breaker store backed by a plain array.
 *
 * Enough for a php-fpm plugin, where the process serves one request and dies, and for tests. A connector whose workers
 * must agree on whether a host is down needs a shared store instead - the point of a breaker is that the second worker
 * benefits from what the first one learned.
 */
final class InMemoryCircuitBreakerStore implements CircuitBreakerStoreInterface
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
}
