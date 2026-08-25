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

use Comfino\Api\Exception\RetryExhaustedException;
use Throwable;

/**
 * Retry executor for Comfino API requests.
 *
 * The loop names all three exits, which is the whole point of the class:
 *
 *  - The error was never retryable → the raw error is rethrown, untouched;
 *  - The attempt budget is spent → {@see RetryExhaustedException} with reason `AttemptsExhausted`;
 *  - The transfer-time budget is spent → {@see RetryExhaustedException} with reason `TimeBudgetExhausted`.
 *
 * See {@see RetryExhaustionReason} for the reasons, including the third one: a request that could not be replayed.
 *
 * Before 3.0.0 all three left through `throw $error` because the policy answered them with one boolean, so the
 * exhaustion exception after the loop was unreachable and the {@see \Comfino\Api\Exception\ConnectionTimeout} the
 * client derives from it - the exception every plugin's timeout handling is written against, and the one carrying the
 * attempt count, the final timeouts, the request URI and the request body - was never produced.
 */
class RetryExecutor
{
    private readonly SleeperInterface $sleeper;
    private readonly RetryObserverInterface $observer;

    /**
     * @param RetryPolicyInterface $retryPolicy The retry policy to use
     * @param SleeperInterface|null $sleeper Delay implementation; defaults to a real {@see Sleeper}. Pass
     *                                       {@see NoDelaySleeper} in tests, or on a path that must not block
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
     * @param callable $operation A callable invoked as fn(int $attempt, TimeoutConfig $timeouts): mixed. The two
     *                            arguments are optional for the callee - a zero-argument closure stays valid - but a
     *                            caller that needs per-attempt timeouts on the transport reads them from there rather
     *                            than mutating a shared client
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
     * Lets a call site lower the attempt ceiling - a checkout probe to one, an order creation to one - without
     * rebuilding the client, the executor, and the policy for every call.
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
            /* Replaying a request the caller declared non-idempotent can duplicate an effect the server may already
               have applied, and a duplicated effect is worse than a surfaced error. */
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
