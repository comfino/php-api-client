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

/**
 * An atomic breaker store whose every swap is lost, standing in for a key several workers are hammering at once.
 */
final class SwapRefusingCircuitBreakerStore implements AtomicCircuitBreakerStoreInterface
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

    public function compareAndSet(string $key, ?CircuitBreakerState $expected, CircuitBreakerState $new): bool
    {
        return false;
    }
}
