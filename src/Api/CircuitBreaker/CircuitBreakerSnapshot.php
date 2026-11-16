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
 * Read-only view of one breaker key, so a host can show it in an admin panel without interpreting
 * {@see CircuitBreakerState} or knowing the open-window length and probe rules.
 */
final class CircuitBreakerSnapshot
{
    /**
     * @param CircuitBreakerStatus $status Closed, open or half-open
     * @param int $consecutiveFailures Consecutive failures recorded since the last success
     * @param float|null $openedAt Unix timestamp at which the breaker opened, or null while it is closed
     * @param float|null $retryAt Unix timestamp at which the next probe may start; null while closed. In the past
     *                            for a half-open breaker whose probe has not been claimed yet
     */
    public function __construct(
        public readonly CircuitBreakerStatus $status,
        public readonly int $consecutiveFailures,
        public readonly ?float $openedAt,
        public readonly ?float $retryAt
    ) {
    }
}
