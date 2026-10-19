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

use Psr\Http\Message\RequestInterface;
use Throwable;

/**
 * Observation hook for the retry loop.
 *
 * Replaces the bare `onRetry` callable, which carried an attempt number and an error and nothing else - no tenant, no
 * endpoint, no delay - and so could not feed a metric like `comfino_api_retry_total{tenant, endpoint, attempt}`
 * without patching the library. Every method has a no-op default in {@see NullRetryObserver}, so implementing only the
 * interesting one is fine.
 */
interface RetryObserverInterface
{
    /**
     * Called once per retry decision, before the delay is slept and the next attempt is made.
     *
     * @param string|null $tenantKey Stable per-tenant key from the API context, when the host supplied one
     * @param RequestInterface|null $request The PSR-7 request about to be replayed, when the caller had one to hand
     * @param Throwable $error The failure that triggered the retry
     * @param int $attempt Number of the attempt that just failed, counting from 1
     * @param int $delayMs Delay that will be slept before the next attempt
     */
    public function onRetry(
        ?string $tenantKey,
        ?RequestInterface $request,
        Throwable $error,
        int $attempt,
        int $delayMs
    ): void;

    /**
     * Called when the retry sequence gives up.
     *
     * @param string|null $tenantKey Stable per-tenant key from the API context, when the host supplied one
     * @param RequestInterface|null $request The PSR-7 request that failed, when the caller had one to hand
     * @param Throwable $error The final failure
     * @param int $attempts Number of attempts made
     * @param RetryExhaustionReason $reason Why the sequence stopped
     */
    public function onGiveUp(
        ?string $tenantKey,
        ?RequestInterface $request,
        Throwable $error,
        int $attempts,
        RetryExhaustionReason $reason
    ): void;
}
