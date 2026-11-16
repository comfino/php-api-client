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
 * Retry observer that records nothing. Used as the default, so the executor never has to null-check.
 */
final class NullRetryObserver implements RetryObserverInterface
{
    /** @inheritDoc */
    public function onRetry(
        ?string $tenantKey,
        ?RequestInterface $request,
        Throwable $error,
        int $attempt,
        int $delayMs
    ): void {
    }

    /** @inheritDoc */
    public function onGiveUp(
        ?string $tenantKey,
        ?RequestInterface $request,
        Throwable $error,
        int $attempts,
        RetryExhaustionReason $reason
    ): void {
    }
}
