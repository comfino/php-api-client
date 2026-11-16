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
 * Optional capability of a {@see CircuitBreakerInterface}: report the state of a key without changing it.
 *
 * A separate interface rather than a new method on {@see CircuitBreakerInterface}, so a host's own breaker keeps working.
 */
interface InspectableCircuitBreakerInterface
{
    /**
     * Describes the breaker for a key. Never claims the half-open probe and never writes to the store.
     *
     * @param string $key Breaker key, conventionally {@see CircuitBreakerKey::build()}
     */
    public function inspect(string $key): CircuitBreakerSnapshot;
}
