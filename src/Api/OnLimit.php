<?php

/**
 * ComfinoPay PHP API client
 *
 * Backend routines for communication with the ComfinoPay payment gateway REST API.
 *
 * @package Comfino\Api
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-api-client
 */

declare(strict_types=1);

namespace Comfino\Api;

/**
 * What to do when the outbound rate limiter rejects a call.
 *
 * Deliberately a call-site decision rather than a library one: the same limiter serves a checkout render, where the
 * right answer is to surface the failure so the shopper can pick another payment method, and a background worker, where
 * the right answer is to hand the call to a queue and try again later. Neither answer may block a request thread.
 */
enum OnLimit
{
    /** Surface {@see \Comfino\Api\Exception\ServiceUnavailable} immediately - the shopper-facing choice. */
    case FailFast;
    /**
     * Surface {@see \Comfino\Api\Exception\RateLimitExceeded}, which carries the retry-after hint, so the host can
     * enqueue the call - the worker-facing choice.
     */
    case Queue;
}
