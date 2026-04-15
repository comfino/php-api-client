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

use InvalidArgumentException;
use Throwable;

/**
 * Exponential backoff retry policy for Comfino API requests.
 */
class ExponentialBackoffRetryPolicy implements RetryPolicyInterface
{
    public const MAX_CONNECTION_TIMEOUT = 30; // Maximal connection timeout is 30 seconds
    public const MAX_TRANSFER_TIMEOUT = 60; // Maximal transfer timeout is 60 seconds
    public const MIN_TRANSFER_TIMEOUT_MULTIPLIER = 3; // Minimum transfer timeout multiplier is 3

    private readonly Psr18ErrorDetector $errorDetector;

    /**
     * @param TimeoutConfig $timeoutConfig Base connection and transfer timeouts
     * @param int $maxAttempts Maximum number of attempts
     *
     * @throws InvalidArgumentException If base connection timeout is less than 1 or base transfer timeout is less
     *                                   than minimum multiplier times base connection timeout
     */
    public function __construct(
        private readonly TimeoutConfig $timeoutConfig,
        private readonly int $maxAttempts = self::DEFAULT_MAX_ATTEMPTS
    ) {
        if ($this->timeoutConfig->connectionTimeout < 1) {
            throw new InvalidArgumentException('Base connection timeout must be at least 1 second.');
        }

        // Transfer timeout must be greater than connection timeout.
        $currentTransferTimeout = $this->timeoutConfig->connectionTimeout * self::MIN_TRANSFER_TIMEOUT_MULTIPLIER;

        if ($this->timeoutConfig->transferTimeout < $currentTransferTimeout) {
            throw new InvalidArgumentException(
                sprintf(
                    'Transfer timeout must be at least %dx connection timeout.',
                    self::MIN_TRANSFER_TIMEOUT_MULTIPLIER
                )
            );
        }

        if ($this->maxAttempts < 1) {
            throw new InvalidArgumentException('Maximum attempts must be at least 1.');
        }

        $this->errorDetector = new Psr18ErrorDetector();
    }

    /** @inheritDoc */
    public function shouldRetry(mixed $error, int $attemptNumber): bool
    {
        if ($attemptNumber >= $this->maxAttempts) {
            return false;
        }

        return $error instanceof Throwable && $this->errorDetector->isRetryable($error);
    }

    /** @inheritDoc */
    public function getConnectionTimeout(int $attemptNumber): int
    {
        return $this->calculateTimeout(
            $this->timeoutConfig->connectionTimeout,
            $attemptNumber,
            self::MAX_CONNECTION_TIMEOUT
        );
    }

    /** @inheritDoc */
    public function getTransferTimeout(int $attemptNumber): int
    {
        return $this->calculateTimeout(
            $this->timeoutConfig->transferTimeout,
            $attemptNumber,
            self::MAX_TRANSFER_TIMEOUT
        );
    }

    /** @inheritDoc */
    public function getMaxAttempts(): int
    {
        return $this->maxAttempts;
    }

    /** @inheritDoc */
    public function getBaseConnectionTimeout(): int
    {
        return $this->timeoutConfig->connectionTimeout;
    }

    /** @inheritDoc */
    public function getBaseTransferTimeout(): int
    {
        return $this->timeoutConfig->transferTimeout;
    }

    /**
     * Calculates the timeout based on the attempt number and the maximum timeout.
     *
     * @param int $baseTimeout Base timeout in seconds
     * @param int $attemptNumber Attempt number
     * @param int $maxTimeout Maximum timeout in seconds
     *
     * @return int Calculated timeout in seconds
     */
    private function calculateTimeout(int $baseTimeout, int $attemptNumber, int $maxTimeout): int
    {
        if ($attemptNumber < 1 || $attemptNumber > $this->maxAttempts) {
            return $baseTimeout;
        }

        if ($this->maxAttempts <= 1) {
            return $baseTimeout;
        }

        $timeout = $baseTimeout << ($attemptNumber - 1);

        return min($timeout, $maxTimeout);
    }
}
