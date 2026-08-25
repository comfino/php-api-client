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

use InvalidArgumentException;

/**
 * Decorator that lowers a policy's attempt ceiling without touching anything else about it.
 *
 * Exists so that an attempt count can be a per-call-site decision rather than a property of the client. Previously the
 * only way to send one call with two attempts and another with one was to build a second client, a second executor, and
 * a second policy - which is exactly the per-call object churn the shared client is meant to remove.
 *
 * Lowers only: a call site cannot grant itself more attempts than the policy allows, so a request-level override can
 * never widen the blast radius of a retry storm the policy was sized to prevent.
 */
final class CappedAttemptsRetryPolicy implements RetryPolicyInterface
{
    private readonly int $maxAttempts;

    /**
     * @param RetryPolicyInterface $policy Policy to delegate to
     * @param int $maxAttempts Attempt ceiling for this call; capped at the delegate's own ceiling
     *
     * @throws InvalidArgumentException If the ceiling is below 1
     */
    public function __construct(private readonly RetryPolicyInterface $policy, int $maxAttempts)
    {
        if ($maxAttempts < 1) {
            throw new InvalidArgumentException('Maximum attempts must be at least 1.');
        }

        $this->maxAttempts = min($maxAttempts, $policy->getMaxAttempts());
    }

    /** @inheritDoc */
    public function classify(mixed $error, bool $requestIsIdempotent = true): Classification
    {
        return $this->policy->classify($error, $requestIsIdempotent);
    }

    /** @inheritDoc */
    public function isRetryable(mixed $error, bool $requestIsIdempotent = true): bool
    {
        return $this->policy->isRetryable($error, $requestIsIdempotent);
    }

    /** @inheritDoc */
    public function hasAttemptsLeft(int $attemptNumber): bool
    {
        return $attemptNumber < $this->maxAttempts;
    }

    /** @inheritDoc */
    public function hasTimeBudgetLeft(int $attemptNumber): bool
    {
        return $this->policy->hasTimeBudgetLeft($attemptNumber);
    }

    /** @inheritDoc */
    public function delayFor(int $attemptNumber): int
    {
        return $this->policy->delayFor($attemptNumber);
    }

    /** @inheritDoc */
    public function shouldRetry(mixed $error, int $attemptNumber): bool
    {
        return $this->hasAttemptsLeft($attemptNumber)
            && $this->hasTimeBudgetLeft($attemptNumber + 1)
            && $this->isRetryable($error);
    }

    /** @inheritDoc */
    public function getConnectionTimeout(int $attemptNumber): int
    {
        return $this->policy->getConnectionTimeout($attemptNumber);
    }

    /** @inheritDoc */
    public function getTransferTimeout(int $attemptNumber): int
    {
        return $this->policy->getTransferTimeout($attemptNumber);
    }

    /** @inheritDoc */
    public function getMaxAttempts(): int
    {
        return $this->maxAttempts;
    }

    /** @inheritDoc */
    public function getBaseConnectionTimeout(): int
    {
        return $this->policy->getBaseConnectionTimeout();
    }

    /** @inheritDoc */
    public function getBaseTransferTimeout(): int
    {
        return $this->policy->getBaseTransferTimeout();
    }
}
