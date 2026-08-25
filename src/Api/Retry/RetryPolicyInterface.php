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
 * Interface for retry policies in Comfino API requests.
 *
 * The three questions a retry loop has to ask are kept separate on purpose. Folding them into a single
 * {@see shouldRetry()} made the executor unable to tell "this error was never worth retrying" from "we ran out of
 * attempts" from "we ran out of time budget", so it threw the raw transport error on all three paths and the
 * exhaustion exception - the one carrying the attempt count and the final timeouts - was unreachable.
 */
interface RetryPolicyInterface
{
    /** Default maximum number of retry attempts for any policy implementation. */
    public const DEFAULT_MAX_ATTEMPTS = 3;

    /** Default delay before the first retry, in milliseconds; doubled per attempt and jittered. */
    public const DEFAULT_BASE_DELAY_MS = 100;

    /** Default ceiling for the retry delay, in milliseconds. */
    public const DEFAULT_MAX_DELAY_MS = 2000;

    /**
     * Classifies an error by its nature alone - no attempt counting, no time budget.
     *
     * @param mixed $error The error encountered during the API request
     * @param bool $requestIsIdempotent Whether replaying the request is free of side effects. A 500 on a
     *                                  non-idempotent request is not retried, because the server may already have
     *                                  applied it
     */
    public function classify(mixed $error, bool $requestIsIdempotent = true): Classification;

    /**
     * Whether the error is worth another attempt, ignoring attempt and time budgets.
     *
     * @param mixed $error The error encountered during the API request
     * @param bool $requestIsIdempotent Whether replaying the request is free of side effects
     */
    public function isRetryable(mixed $error, bool $requestIsIdempotent = true): bool;

    /**
     * Whether the attempt budget allows another attempt after the given one.
     *
     * @param int $attemptNumber The attempt that has just been made, counting from 1
     */
    public function hasAttemptsLeft(int $attemptNumber): bool;

    /**
     * Whether the total transfer-time budget still leaves a usable timeout for the given attempt.
     *
     * @param int $attemptNumber The attempt about to be made, counting from 1
     */
    public function hasTimeBudgetLeft(int $attemptNumber): bool;

    /**
     * Delay to wait before the attempt following the given one, in milliseconds.
     *
     * @param int $attemptNumber The attempt that has just failed, counting from 1
     *
     * @return int Delay in milliseconds; 0 means retry immediately
     */
    public function delayFor(int $attemptNumber): int;

    /**
     * Determines if a retry should be attempted based on the error and attempt number.
     *
     * @deprecated Since 3.0.0. The conjunction of {@see isRetryable()}, {@see hasAttemptsLeft()} and
     *             {@see hasTimeBudgetLeft()}, kept so that callers written against the old surface keep working. It
     *             cannot express which of the three failed, which is exactly why the retry executor no longer uses it.
     *
     * @param mixed $error The error encountered during the API request
     * @param int $attemptNumber The current attempt number
     *
     * @return bool True if a retry should be attempted, false otherwise
     */
    public function shouldRetry(mixed $error, int $attemptNumber): bool;

    /**
     * Retrieves the connection timeout for the specified attempt number.
     *
     * @param int $attemptNumber The current attempt number
     *
     * @return int The connection timeout in seconds
     */
    public function getConnectionTimeout(int $attemptNumber): int;

    /**
     * Retrieves the transfer timeout for the specified attempt number.
     *
     * @param int $attemptNumber The current attempt number
     *
     * @return int The transfer timeout in seconds
     */
    public function getTransferTimeout(int $attemptNumber): int;

    /**
     * Retrieves the maximum number of retry attempts allowed.
     *
     * @return int The maximum number of retry attempts
     */
    public function getMaxAttempts(): int;

    /**
     * Retrieves the base connection timeout used for retry calculations.
     *
     * @return int The base connection timeout in seconds
     */
    public function getBaseConnectionTimeout(): int;

    /**
     * Retrieves the base transfer timeout used for retry calculations.
     *
     * @return int The base transfer timeout in seconds
     */
    public function getBaseTransferTimeout(): int;
}
