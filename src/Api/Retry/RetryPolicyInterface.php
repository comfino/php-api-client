<?php

/**
 * Comfino PHP API client
 *
 * Backend routines for communication with the Comfino payment gateway REST API.
 *
 * @package Comfino\Api\Retry
 * @author Artur Kozubski <akozubski@comperia.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-api-client
 */

declare(strict_types=1);

namespace Comfino\Api\Retry;

/**
 * Interface for retry policies in Comfino API requests.
 */
interface RetryPolicyInterface
{
    /** Default maximum number of retry attempts for any policy implementation. */
    public const DEFAULT_MAX_ATTEMPTS = 3;

    /**
     * Determines if a retry should be attempted based on the error and attempt number.
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
