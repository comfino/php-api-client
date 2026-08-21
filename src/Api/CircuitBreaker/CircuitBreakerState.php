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
 * Immutable breaker state for one key: how many consecutive failures have been seen, and when the breaker opened.
 */
final class CircuitBreakerState
{
    /**
     * @param int $consecutiveFailures Consecutive failures recorded since the last success
     * @param float|null $openedAt Unix timestamp at which the breaker opened, or null while it is closed
     */
    public function __construct(
        public readonly int $consecutiveFailures = 0,
        public readonly ?float $openedAt = null
    ) {
    }
}
