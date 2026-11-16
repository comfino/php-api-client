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

use Comfino\Api\Exception\RetryExhaustedException;
use Throwable;

/**
 * Retry executor for ComfinoPay API requests.
 *
 * The loop distinguishes three exits: a never-retryable error is rethrown untouched; a spent attempt budget or a spent
 * transfer-time budget both raise {@see RetryExhaustedException}, with reason `AttemptsExhausted` or `TimeBudgetExhausted`
 * respectively - see {@see RetryExhaustionReason} for these plus the third, a request that could not be replayed.
 *
 * The client derives {@see \Comfino\Api\Exception\ConnectionTimeout} from the exhaustion exception; every plugin's
 * timeout handling is written against it, carrying the attempt count, final timeouts, request URI and request body.
 */
class RetryExecutor
{
    private readonly SleeperInterface $sleeper;
    private readonly RetryObserverInterface $observer;

    /**
     * @param RetryPolicyInterface $retryPolicy The retry policy to use
     * @param SleeperInterface|null $sleeper Delay implementation; defaults to a real {@see Sleeper}. Pass {@see NoDelaySleeper}
     *                                       in tests, or on a path that must not block
     * @param RetryObserverInterface|null $observer Observation hook for retry and give-up events
     */
    public function __construct(
        private readonly RetryPolicyInterface $retryPolicy,
        ?SleeperInterface $sleeper = null,
        ?RetryObserverInterface $observer = null
    ) {
        $this->sleeper = $sleeper ?? new Sleeper();
        $this->observer = $observer ?? new NullRetryObserver();
    }

    /**
     * Executes a callable with automatic retry logic.
     *
     * @param callable $operation A callable invoked as fn(int $attempt, TimeoutConfig $timeouts): mixed. The two arguments are optional for
     *                            the callee - a zero-argument closure stays valid - but a caller that needs per-attempt timeouts on the
     *                            transport reads them from there rather than mutating a shared client.
     * @param callable|null $onRetry Optional callback for retry events: fn(int $attempt, \Throwable $error) => void
     * @param RetryContext|null $context Request-level facts used for the replay-safety check and for observability
     *
     * @return mixed The return value from $operation
     *
     * @throws RetryExhaustedException When the attempt or time budget is spent
     * @throws Throwable For non-retryable errors
     */
    public function execute(callable $operation, ?callable $onRetry = null, ?RetryContext $context = null): mixed
    {
        $context ??= new RetryContext();
        $timeoutConfig = TimeoutConfig::fromRetryPolicy($this->retryPolicy, 1);

        for ($attempt = 1; $attempt <= $this->retryPolicy->getMaxAttempts(); $attempt++) {
            $timeoutConfig = TimeoutConfig::fromRetryPolicy($this->retryPolicy, $attempt);

            try {
                return $operation($attempt, $timeoutConfig);
            } catch (Throwable $error) {
                $classification = $this->retryPolicy->classify($error, $context->idempotent);

                if (!$classification->isRetryable()) {
                    /* Nothing about this error suggests another attempt would fare better. The caller gets it exactly
                       as the transport produced it - wrapping it would hide the status code and the response body. */
                    throw $error;
                }

                if (($reason = $this->exhaustionReason($attempt, $context)) !== null) {
                    $this->observer->onGiveUp($context->tenantKey, $context->request, $error, $attempt, $reason);

                    throw RetryExhaustedException::withRequestContext(
                        $error,
                        $attempt,
                        $timeoutConfig,
                        $context->request?->getUri()->__toString(),
                        null,
                        $reason
                    );
                }

                $delayMs = $classification->retryAfterMs ?? $this->retryPolicy->delayFor($attempt);

                $this->observer->onRetry($context->tenantKey, $context->request, $error, $attempt, $delayMs);

                if ($onRetry !== null) {
                    $onRetry($attempt, $error);
                }

                $this->sleeper->sleepMs($delayMs);
            }
        }

        /* Unreachable: the loop above always leaves through a return or a throw. Kept so that a future edit to the
           bounds cannot fall out of the function with no result. */
        throw new RetryExhaustedException(
            null,
            $this->retryPolicy->getMaxAttempts(),
            $timeoutConfig,
            RetryExhaustionReason::AttemptsExhausted
        );
    }

    /**
     * Returns the retry policy used by this executor.
     */
    public function getRetryPolicy(): RetryPolicyInterface
    {
        return $this->retryPolicy;
    }

    /**
     * Returns a copy of this executor whose policy allows at most the given number of attempts.
     *
     * Lets a call site lower the attempt ceiling - a checkout probe to one, an order creation to one - without rebuilding
     * the client, the executor, and the policy for every call.
     *
     * @param int $maxAttempts Attempt ceiling for the returned executor
     */
    public function withMaxAttempts(int $maxAttempts): self
    {
        if ($maxAttempts >= $this->retryPolicy->getMaxAttempts()) {
            return $this;
        }

        return new self(
            new CappedAttemptsRetryPolicy($this->retryPolicy, $maxAttempts),
            $this->sleeper,
            $this->observer
        );
    }

    /**
     * Returns the sleeper used between attempts.
     */
    public function getSleeper(): SleeperInterface
    {
        return $this->sleeper;
    }

    /**
     * Decides whether the sequence has to stop after the given attempt, and why. Null means another attempt is due.
     *
     * @param int $attempt The attempt that has just failed, counting from 1
     * @param RetryContext $context Request-level facts for this call
     */
    private function exhaustionReason(int $attempt, RetryContext $context): ?RetryExhaustionReason
    {
        if (!$context->idempotent) {
            /* Replaying a request the caller declared non-idempotent can duplicate an effect the server may already have
               applied, and a duplicated effect is worse than a surfaced error. */
            return RetryExhaustionReason::NotReplayable;
        }

        if (!$this->retryPolicy->hasAttemptsLeft($attempt)) {
            return RetryExhaustionReason::AttemptsExhausted;
        }

        if (!$this->retryPolicy->hasTimeBudgetLeft($attempt + 1)) {
            return RetryExhaustionReason::TimeBudgetExhausted;
        }

        return null;
    }
}
