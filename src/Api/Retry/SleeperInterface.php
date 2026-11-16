<?php

/**
 * ComfinoPay PHP API client
 *
 * Backend routines for communication with the ComfinoPay payment gateway REST API.
 *
 * @package Comfino\Api\Retry
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-api-client
 */

declare(strict_types=1);

namespace Comfino\Api\Retry;

/**
 * Injection point for the delay between retry attempts.
 *
 * The delay exists so that a refused connection or a struggling API gets a moment to recover instead of being hit
 * again within microseconds, but a real sleep inside a unit test is pure waste - hence the interface. Hosts on a tight
 * latency budget (a checkout render, for instance) should use {@see NoDelaySleeper} together with a single-attempt
 * policy rather than sleeping inside a shopper-facing request.
 */
interface SleeperInterface
{
    /**
     * Blocks the current process for the given number of milliseconds. A value of zero or less returns immediately.
     *
     * @param int $milliseconds Delay in milliseconds
     */
    public function sleepMs(int $milliseconds): void;
}
