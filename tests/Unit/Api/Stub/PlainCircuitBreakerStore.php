<?php

/**
 * Comfino PHP API client
 *
 * Backend routines for communication with the Comfino payment gateway REST API.
 *
 * @package Comfino\Tests\Unit\Api\Stub
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-api-client
 */

declare(strict_types=1);

namespace Comfino\Tests\Unit\Api\Stub;

use Comfino\Api\CircuitBreaker\CircuitBreakerState;
use Comfino\Api\CircuitBreaker\CircuitBreakerStoreInterface;

/**
 * A breaker store that implements only the get-then-set interface, so the non-swapping path can be exercised.
 *
 * Shared between breaker instances on purpose in the tests that use it: that is how it stands in for one store behind
 * several workers.
 */
final class PlainCircuitBreakerStore implements CircuitBreakerStoreInterface
{
    /** @var array<string, CircuitBreakerState> */
    private array $states = [];

    public function get(string $key): ?CircuitBreakerState
    {
        return $this->states[$key] ?? null;
    }

    public function set(string $key, CircuitBreakerState $state): void
    {
        $this->states[$key] = $state;
    }

    public function delete(string $key): void
    {
        unset($this->states[$key]);
    }
}
