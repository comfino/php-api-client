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
 * Immutable breaker state for one key: the consecutive failures seen, when the breaker opened, and who is probing.
 */
final class CircuitBreakerState
{
    /**
     * @param int $consecutiveFailures Consecutive failures recorded since the last success
     * @param float|null $openedAt Unix timestamp at which the breaker opened, or null while it is closed
     * @param float|null $probeStartedAt Unix timestamp at which a caller claimed the half-open probe, or null when no
     *                                   probe is outstanding. A probe older than the open window is treated as lost
     *                                   and may be re-claimed, so a caller that died mid-probe cannot wedge the
     *                                   breaker open forever
     */
    public function __construct(
        public readonly int $consecutiveFailures = 0,
        public readonly ?float $openedAt = null,
        public readonly ?float $probeStartedAt = null
    ) {
    }
}
