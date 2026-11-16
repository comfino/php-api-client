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
 * Where a breaker stands, as seen by a caller that only wants to display it.
 */
enum CircuitBreakerStatus: string
{
    /** Calls go through; failures are being counted. */
    case Closed = 'closed';

    /** Calls fail fast until the open window elapses. */
    case Open = 'open';

    /** The open window has elapsed: one probe is let through (or already is), and its outcome decides. */
    case HalfOpen = 'half_open';
}
