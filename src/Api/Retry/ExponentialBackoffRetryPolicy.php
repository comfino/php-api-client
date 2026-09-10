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

use InvalidArgumentException;

/**
 * Exponential backoff retry policy for ComfinoPay API requests.
 *
 * Two schedules grow per attempt, and they answer different failure modes:
 *
 *  - The **timeout** doubles, which is the right response to a slow far side - a request that needed more time than
 *    the first attempt allowed gets more time on the second;
 *  - The **delay** doubles too, drawn with full jitter, which is the right response to a refused connection or a
 *    struggling API - three instant retries are three instant failures and, across the tenants sharing a node, a
 *    synchronized retry storm.
 *
 * Until 3.0.0 only the first schedule existed, so the class name promised a backoff the loop never performed. Both are
 * bounded: the escalation by a total transfer budget shared across attempts, the delay by {@see $maxDelayMs}. The two
 * bounds cover different clocks, so the worst-case wall-clock cost of a call is the sum of both -
 * {@see getWorstCaseWallClockMs()} reports it.
 */
class ExponentialBackoffRetryPolicy implements RetryPolicyInterface
{
    public const MAX_CONNECTION_TIMEOUT = 30; // Maximal connection timeout is 30 seconds
    public const MAX_TRANSFER_TIMEOUT = 60; // Maximal transfer timeout is 60 seconds
    public const MIN_TRANSFER_TIMEOUT_MULTIPLIER = 3; // Minimum transfer timeout multiplier is 3

    /**
     * Default budget (in seconds) for the transfer time of all attempts of a single API call taken together. The
     * per-attempt caps above bound one attempt only, so without a total budget the escalation multiplies the cost of
     * an unresponsive API by the attempt count: base timeouts of 3s/9s over 3 attempts block the calling process for
     * up to 63 seconds. On a request-scoped runtime that is a worker held hostage for the whole outage, so the total
     * is bounded by default, and callers running off the request path (cron, admin tooling) can opt out explicitly.
     */
    public const DEFAULT_MAX_TOTAL_TRANSFER_TIMEOUT = 15;

    private readonly ErrorClassifier $errorClassifier;

    /** @var int|null Effective total transfer budget, never below the base transfer timeout; null = unbounded */
    private readonly ?int $totalTransferTimeout;

    /**
     * @param TimeoutConfig $timeoutConfig Base connection and transfer timeouts
     * @param int $maxAttempts Maximum number of attempts
     * @param int|null $maxTotalTransferTimeout Transfer time budget in seconds for all attempts of one API call taken
     *                                          together; null disables the bound and restores unlimited escalation,
     *                                          which is only safe off the request path. A budget below the base
     *                                          transfer timeout is raised to it, so the first attempt always gets the
     *                                          timeout it was configured with
     * @param int $baseDelayMs Delay before the first retry, in milliseconds; doubled per attempt and then jittered.
     *                         Zero disables the delay entirely, which is the right choice on a shopper-facing path
     *                         whose latency budget cannot absorb a sleep
     * @param int $maxDelayMs Ceiling for the retry delay, in milliseconds
     * @param ErrorClassifier|null $errorClassifier Classifier deciding which failures are transient
     *
     * @throws InvalidArgumentException If base connection timeout is less than 1, base transfer timeout is less than
     *                                  minimum multiplier times base connection timeout, or either delay is negative
     */
    public function __construct(
        private readonly TimeoutConfig $timeoutConfig,
        private readonly int $maxAttempts = self::DEFAULT_MAX_ATTEMPTS,
        ?int $maxTotalTransferTimeout = self::DEFAULT_MAX_TOTAL_TRANSFER_TIMEOUT,
        private readonly int $baseDelayMs = self::DEFAULT_BASE_DELAY_MS,
        private readonly int $maxDelayMs = self::DEFAULT_MAX_DELAY_MS,
        ?ErrorClassifier $errorClassifier = null
    ) {
        $this->totalTransferTimeout = $maxTotalTransferTimeout !== null
            ? max($maxTotalTransferTimeout, $timeoutConfig->transferTimeout)
            : null;

        if ($this->timeoutConfig->connectionTimeout < 1) {
            throw new InvalidArgumentException('Base connection timeout must be at least 1 second.');
        }

        // Transfer timeout must be greater than connection timeout.
        $currentTransferTimeout = $this->timeoutConfig->connectionTimeout * self::MIN_TRANSFER_TIMEOUT_MULTIPLIER;

        if ($this->timeoutConfig->transferTimeout < $currentTransferTimeout) {
            throw new InvalidArgumentException(
                sprintf('Transfer timeout must be at least %dx connection timeout.', self::MIN_TRANSFER_TIMEOUT_MULTIPLIER)
            );
        }

        if ($this->maxAttempts < 1) {
            throw new InvalidArgumentException('Maximum attempts must be at least 1.');
        }

        if ($this->baseDelayMs < 0 || $this->maxDelayMs < 0) {
            throw new InvalidArgumentException('Retry delays cannot be negative.');
        }

        $this->errorClassifier = $errorClassifier ?? new ErrorClassifier();
    }

    /**
     * Builds a policy that retries without ever sleeping between attempts.
     *
     * For request paths whose latency budget cannot absorb a backoff delay. Note that this keeps the retry storm the
     * delay exists to prevent, so prefer {@see failFast()} on a shopper-facing path and let a queue own the retry.
     *
     * @param TimeoutConfig $timeoutConfig Base connection and transfer timeouts
     * @param int $maxAttempts Maximum number of attempts
     * @param int|null $maxTotalTransferTimeout Transfer time budget shared by all attempts, in seconds
     */
    public static function withoutDelay(
        TimeoutConfig $timeoutConfig,
        int $maxAttempts = self::DEFAULT_MAX_ATTEMPTS,
        ?int $maxTotalTransferTimeout = self::DEFAULT_MAX_TOTAL_TRANSFER_TIMEOUT
    ): self {
        return new self($timeoutConfig, $maxAttempts, $maxTotalTransferTimeout, 0, 0);
    }

    /**
     * Builds a single-attempt policy: no retry, no delay, fail as soon as the transport does.
     *
     * The honest configuration for a checkout render - one attempt, surface the failure, let the shopper pick another
     * method or let an outbound queue own the retry - rather than sleeping inside a request a shopper is waiting on.
     *
     * @param TimeoutConfig $timeoutConfig Base connection and transfer timeouts
     */
    public static function failFast(TimeoutConfig $timeoutConfig): self
    {
        return new self($timeoutConfig, 1, null, 0, 0);
    }

    /** @inheritDoc */
    public function classify(mixed $error, bool $requestIsIdempotent = true): Classification
    {
        return $this->errorClassifier->classify($error, $requestIsIdempotent);
    }

    /** @inheritDoc */
    public function isRetryable(mixed $error, bool $requestIsIdempotent = true): bool
    {
        return $this->classify($error, $requestIsIdempotent)->isRetryable();
    }

    /** @inheritDoc */
    public function hasAttemptsLeft(int $attemptNumber): bool
    {
        return $attemptNumber < $this->maxAttempts;
    }

    /** @inheritDoc */
    public function hasTimeBudgetLeft(int $attemptNumber): bool
    {
        /* A zero timeout is not "no budget left" to most transports - it reads as "no timeout at all", which would
           turn the bound into unlimited blocking. So an attempt is only affordable while a whole second remains. */
        return $this->getTransferTimeout($attemptNumber) >= 1;
    }

    /** @inheritDoc */
    public function delayFor(int $attemptNumber): int
    {
        if ($this->baseDelayMs < 1 || $this->maxDelayMs < 1 || $attemptNumber < 1) {
            return 0;
        }

        /* Full jitter: a uniform draw from [0, exponentially growing ceiling]. Deterministic backoff would keep every
           tenant on a shared node retrying in lockstep, which turns one blip into a synchronized second wave. */
        $ceiling = min($this->baseDelayMs << min($attemptNumber - 1, 30), $this->maxDelayMs);

        return random_int(0, $ceiling);
    }

    /** @inheritDoc */
    public function shouldRetry(mixed $error, int $attemptNumber): bool
    {
        return $this->hasAttemptsLeft($attemptNumber) &&  $this->hasTimeBudgetLeft($attemptNumber + 1) &&
            $this->isRetryable($error);
    }

    /** @inheritDoc */
    public function getConnectionTimeout(int $attemptNumber): int
    {
        $connectionTimeout = $this->calculateTimeout($this->timeoutConfig->connectionTimeout, $attemptNumber, self::MAX_CONNECTION_TIMEOUT);

        /* Connecting is part of the transfer, and TimeoutConfig rejects a connection timeout above the transfer one,
           so a transfer timeout shortened by the remaining budget caps the connection timeout as well. */
        return min($connectionTimeout, $this->getTransferTimeout($attemptNumber));
    }

    /** @inheritDoc */
    public function getTransferTimeout(int $attemptNumber): int
    {
        $transferTimeout = $this->calculateTimeout($this->timeoutConfig->transferTimeout, $attemptNumber, self::MAX_TRANSFER_TIMEOUT);

        if ($this->totalTransferTimeout === null || $attemptNumber < 1 || $attemptNumber > $this->maxAttempts) {
            return $transferTimeout;
        }

        /* Walk the attempts up to the requested one, spending the budget as the escalation would consume it: each
           attempt gets its escalated timeout or whatever is left of the budget, whichever is smaller. */
        $remainingBudget = $this->totalTransferTimeout;

        for ($attempt = 1; $attempt < $attemptNumber; $attempt++) {
            $remainingBudget -= min(
                $this->calculateTimeout(
                    $this->timeoutConfig->transferTimeout,
                    $attempt,
                    self::MAX_TRANSFER_TIMEOUT
                ),
                $remainingBudget
            );
        }

        return min($transferTimeout, $remainingBudget);
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
     * Returns the transfer time budget shared by all attempts of a single API call, or null when the escalation is
     * left unbounded. Exposed so that callers can report the worst-case cost of an API call they are about to make.
     */
    public function getMaxTotalTransferTimeout(): ?int
    {
        return $this->totalTransferTimeout;
    }

    /**
     * Returns the base retry delay in milliseconds. Zero means retries fire immediately.
     */
    public function getBaseDelayMs(): int
    {
        return $this->baseDelayMs;
    }

    /**
     * Returns the ceiling applied to the retry delay, in milliseconds.
     */
    public function getMaxDelayMs(): int
    {
        return $this->maxDelayMs;
    }

    /**
     * Returns the worst-case wall-clock cost of one API call under this policy, in milliseconds: the transfer budget
     * plus the maximum a full retry sequence can spend sleeping.
     *
     * The transfer budget bounds transfer time only, so the delays introduced by the backoff are spent on top of it.
     * A caller sizing a request-level latency budget needs this number, not the transfer one.
     */
    public function getWorstCaseWallClockMs(): int
    {
        $transferMs = 1000 * ($this->totalTransferTimeout ?? array_sum(
            array_map(fn (int $attempt): int => $this->getTransferTimeout($attempt), range(1, $this->maxAttempts))
        ));

        $delayMs = 0;

        for ($attempt = 1; $attempt < $this->maxAttempts; $attempt++) {
            $delayMs += $this->baseDelayMs > 0 && $this->maxDelayMs > 0
                ? min($this->baseDelayMs << min($attempt - 1, 30), $this->maxDelayMs)
                : 0;
        }

        return $transferMs + $delayMs;
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
