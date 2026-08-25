<?php

/**
 * Comfino PHP API client
 *
 * Backend routines for communication with the Comfino payment gateway REST API.
 *
 * @package Comfino\Api\Retry
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-api-client
 */

declare(strict_types=1);

namespace Comfino\Api\Retry;

/**
 * Why a retry sequence stopped.
 *
 * Kept as a separate axis from "was the error retryable at all", because the three exits used to be indistinguishable:
 * an error that was never worth retrying, a call that used up its attempts, and a call that used up its transfer-time
 * budget all left the executor the same way. Only the last two are retry exhaustion, and a caller sizing its own
 * latency budget needs to know which of them it hit.
 */
enum RetryExhaustionReason: string
{
    /** The policy's attempt count was spent. */
    case AttemptsExhausted = 'attempts_exhausted';
    /** The policy's total transfer-time budget was spent before the attempts were. */
    case TimeBudgetExhausted = 'time_budget_exhausted';
    /**
     * The request declared itself non-idempotent ({@see \Comfino\Api\Request::isIdempotent()}), so another attempt
     * risked applying twice a side effect the server may already have applied. No request in this library answers that
     * way - order creation is deduplicated API-side - but a caller's own request can.
     */
    case NotReplayable = 'not_replayable';
}
