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
 * Verdict returned by {@see ErrorClassifier} for a failed (or throttled) API call.
 */
enum RetryVerdict
{
    /** Transient failure: retry after the policy's own backoff delay. */
    case Retry;
    /** Transient failure whose response told us when to come back: retry after {@see Classification::$retryAfterMs}. */
    case RetryAfter;
    /** Permanent failure: surface it to the caller without another attempt. */
    case Fatal;
    /** Not a failure at all - the response is usable. */
    case Success;
}
