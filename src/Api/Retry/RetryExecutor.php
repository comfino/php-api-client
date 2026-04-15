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

use Comfino\Api\Exception\RetryExhaustedException;
use Throwable;

/**
 * Retry executor for Comfino API requests.
 */
class RetryExecutor
{
    /**
     * @param RetryPolicyInterface $retryPolicy The retry policy to use
     */
    public function __construct(private readonly RetryPolicyInterface $retryPolicy)
    {
    }

    /**
     * Executes a callable with automatic retry logic.
     *
     * @param callable $operation A callable that returns the HTTP response
     * @param callable|null $onRetry Optional callback for retry events: fn(int $attempt, \Throwable $error) => void
     *
     * @return mixed The return value from $operation
     *
     * @throws RetryExhaustedException When all retry attempts are exhausted
     * @throws Throwable For non-retryable errors
     */
    public function execute(callable $operation, ?callable $onRetry = null): mixed
    {
        $lastError = null;
        $lastTimeoutConfig = null;

        for ($attempt = 1; $attempt <= $this->retryPolicy->getMaxAttempts(); $attempt++) {
            try {
                // Get timeout config for the current attempt.
                $lastTimeoutConfig = TimeoutConfig::fromRetryPolicy($this->retryPolicy, $attempt);

                return $operation();
            } catch (Throwable $error) {
                $lastError = $error;

                if ($this->retryPolicy->shouldRetry($error, $attempt)) {
                    // Call the onRetry callback if provided.
                    if ($onRetry !== null) {
                        $onRetry($attempt, $error);
                    }

                    continue;
                }

                throw $error;
            }
        }

        throw new RetryExhaustedException($lastError, $this->retryPolicy->getMaxAttempts(), $lastTimeoutConfig);
    }

    /**
     * Returns the retry policy used by this executor.
     */
    public function getRetryPolicy(): RetryPolicyInterface
    {
        return $this->retryPolicy;
    }
}
