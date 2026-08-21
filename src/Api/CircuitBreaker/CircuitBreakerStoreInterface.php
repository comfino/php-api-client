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
 * Where a {@see CircuitBreaker} keeps its per-key state.
 *
 * Separate from the breaker so the policy lives in one place and the storage matches the deployment: an APCu or
 * per-request array store for a plugin, a shared Redis or database store for a connector whose workers must agree on
 * whether a host is down.
 */
interface CircuitBreakerStoreInterface
{
    /**
     * Returns the stored state for the key or null when nothing is recorded.
     *
     * @param string $key Breaker key
     */
    public function get(string $key): ?CircuitBreakerState;

    /**
     * Stores the state for the key.
     *
     * @param string $key Breaker key
     * @param CircuitBreakerState $state State to store
     */
    public function set(string $key, CircuitBreakerState $state): void;

    /**
     * Removes any stored state for the key.
     *
     * @param string $key Breaker key
     */
    public function delete(string $key): void;
}
