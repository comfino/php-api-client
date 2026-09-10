<?php

/**
 * ComfinoPay PHP API client
 *
 * Backend routines for communication with the ComfinoPay payment gateway REST API.
 *
 * @package Comfino\Tests\Unit\Api\Retry
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-api-client
 */

declare(strict_types=1);

namespace Comfino\Tests\Unit\Api\Retry;

use Comfino\Api\Exception\RetryExhaustedException;
use Comfino\Api\Retry\CallbackTimeoutAwareClient;
use Comfino\Api\Retry\ExponentialBackoffRetryPolicy;
use Comfino\Api\Retry\NoDelaySleeper;
use Comfino\Api\Retry\RetryContext;
use Comfino\Api\Retry\RetryExecutor;
use Comfino\Api\Retry\RetryExhaustionReason;
use Comfino\Api\Retry\RetryObserverInterface;
use Comfino\Api\Retry\TimeoutConfig;
use Comfino\Tests\Unit\Api\Stub\FakeNetworkException;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * The delay half of the backoff, which the class name promised for two major versions without ever performing it: three
 * attempts against a refused connection all fired within microseconds, which is three instant failures and, across the
 * tenants sharing a node, a synchronized retry storm.
 */
final class BackoffDelayTest extends TestCase
{
    public function testDelayGrowsExponentiallyWithinTheJitterCeiling(): void
    {
        $policy = new ExponentialBackoffRetryPolicy(new TimeoutConfig(1, 3), 5, null, 100, 2000);

        /* Full jitter means the delay is a draw from [0, ceiling], so the ceiling is what can be asserted. Sampling
           repeatedly keeps the assertion meaningful without making it depend on one particular draw. */
        foreach ([1 => 100, 2 => 200, 3 => 400, 4 => 800] as $attempt => $ceiling) {
            for ($i = 0; $i < 50; $i++) {
                $delay = $policy->delayFor($attempt);

                $this->assertGreaterThanOrEqual(0, $delay);
                $this->assertLessThanOrEqual($ceiling, $delay);
            }
        }
    }

    public function testDelayIsCappedAtTheConfiguredCeiling(): void
    {
        $policy = new ExponentialBackoffRetryPolicy(new TimeoutConfig(1, 3), 10, null, 100, 500);

        for ($i = 0; $i < 50; $i++) {
            $this->assertLessThanOrEqual(500, $policy->delayFor(8));
        }
    }

    public function testJitterActuallyVariesTheDelay(): void
    {
        $policy = new ExponentialBackoffRetryPolicy(new TimeoutConfig(1, 3), 5, null, 1000, 2000);
        $delays = [];

        for ($i = 0; $i < 40; $i++) {
            $delays[$policy->delayFor(2)] = true;
        }

        /* Deterministic backoff would keep every tenant on a shared node retrying in lockstep, turning one blip into a
           synchronized second wave. Several distinct draws out of 40 is enough to show the jitter is real. */
        $this->assertGreaterThan(1, count($delays));
    }

    public function testWithoutDelayFactoryNeverSleeps(): void
    {
        $policy = ExponentialBackoffRetryPolicy::withoutDelay(new TimeoutConfig(1, 3), 3);

        $this->assertSame(0, $policy->delayFor(1));
        $this->assertSame(0, $policy->delayFor(2));
    }

    public function testFailFastFactoryAllowsExactlyOneAttempt(): void
    {
        $policy = ExponentialBackoffRetryPolicy::failFast(new TimeoutConfig(1, 3));

        $this->assertSame(1, $policy->getMaxAttempts());
        $this->assertFalse($policy->hasAttemptsLeft(1));
        $this->assertSame(0, $policy->delayFor(1));
    }

    public function testExecutorSleepsBetweenAttempts(): void
    {
        $sleeper = new NoDelaySleeper();
        $executor = new RetryExecutor(new ExponentialBackoffRetryPolicy(new TimeoutConfig(1, 3), 3, null, 100, 2000), $sleeper);

        try {
            $executor->execute(static fn () => throw new FakeNetworkException());
        } catch (Throwable) {
            // Expected once the attempts are spent.
        }

        // Two retries between three attempts, so two delays - and never one after the final attempt.
        $this->assertCount(2, $sleeper->requestedDelays);
    }

    public function testRetryAfterOverridesTheBackoffDelay(): void
    {
        $psr17Factory = new Psr17Factory();
        $sleeper = new NoDelaySleeper();
        $executor = new RetryExecutor(new ExponentialBackoffRetryPolicy(new TimeoutConfig(1, 3), 2, null, 100, 2000), $sleeper);
        $response = $psr17Factory->createResponse(429)->withHeader('Retry-After', '7');

        try {
            $executor->execute(static fn () => throw new \Comfino\Api\Retry\RetryableResponse($response));
        } catch (Throwable) {
            // Expected once the attempts are spent.
        }

        /* A far side that says when to come back is obeyed rather than second-guessed - honoring it is the difference
           between backing off and hammering. */
        $this->assertSame([7000], $sleeper->requestedDelays);
    }

    public function testWorstCaseWallClockCountsBothTheTransferBudgetAndTheDelays(): void
    {
        $policy = new ExponentialBackoffRetryPolicy(new TimeoutConfig(1, 3), 3, 15, 100, 2000);

        // 15s of transfer budget, plus the two delay ceilings (100 ms + 200 ms) the sequence can spend sleeping.
        $this->assertSame(15300, $policy->getWorstCaseWallClockMs());
    }

    public function testNegativeDelayIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/delays cannot be negative/i');

        new ExponentialBackoffRetryPolicy(new TimeoutConfig(1, 3), 3, null, -1, 2000);
    }

    // -------------------------------------------------------------------------
    // Replay safety
    // -------------------------------------------------------------------------

    public function testNonIdempotentRequestIsNotReplayed(): void
    {
        $calls = 0;
        $executor = new RetryExecutor(new ExponentialBackoffRetryPolicy(new TimeoutConfig(1, 3), 3), new NoDelaySleeper());

        try {
            $executor->execute(
                static function () use (&$calls) {
                    $calls++;

                    throw new FakeNetworkException();
                },
                null,
                new RetryContext(false)
            );

            $this->fail('Expected RetryExhaustedException to be thrown.');
        } catch (RetryExhaustedException $e) {
            /* A request whose effect nothing deduplicates is sent once; the failure is surfaced instead of risking a
               second application of it. */
            $this->assertSame(1, $calls);
            $this->assertSame(RetryExhaustionReason::NotReplayable, $e->getReason());
        }
    }

    public function testIdempotentRequestIsReplayedUpToTheAttemptCeiling(): void
    {
        $calls = 0;
        $executor = new RetryExecutor(new ExponentialBackoffRetryPolicy(new TimeoutConfig(1, 3), 3), new NoDelaySleeper());

        try {
            $executor->execute(
                static function () use (&$calls) {
                    $calls++;

                    throw new FakeNetworkException();
                },
                null,
                new RetryContext(true)
            );
        } catch (Throwable) {
            // Expected once the attempts are spent.
        }

        $this->assertSame(3, $calls);
    }

    // -------------------------------------------------------------------------
    // Observability and per-call attempt caps
    // -------------------------------------------------------------------------

    public function testObserverSeesEveryRetryAndTheGiveUp(): void
    {
        $retries = [];
        $giveUps = [];

        $observer = new class ($retries, $giveUps) implements RetryObserverInterface {
            /**
             * @param array<int, array{string|null, int, int}> $retries
             * @param array<int, array{string|null, int, RetryExhaustionReason}> $giveUps
             */
            public function __construct(public array &$retries, public array &$giveUps)
            {
            }

            public function onRetry(
                ?string $tenantKey,
                ?RequestInterface $request,
                Throwable $error,
                int $attempt,
                int $delayMs
            ): void {
                $this->retries[] = [$tenantKey, $attempt, $delayMs];
            }

            public function onGiveUp(
                ?string $tenantKey,
                ?RequestInterface $request,
                Throwable $error,
                int $attempts,
                RetryExhaustionReason $reason
            ): void {
                $this->giveUps[] = [$tenantKey, $attempts, $reason];
            }
        };

        $executor = new RetryExecutor(
            ExponentialBackoffRetryPolicy::withoutDelay(new TimeoutConfig(1, 3), 3),
            new NoDelaySleeper(),
            $observer
        );

        try {
            $executor->execute(
                static fn () => throw new FakeNetworkException(),
                null,
                new RetryContext(true, 'tenant-7')
            );
        } catch (Throwable) {
            // Expected once the attempts are spent.
        }

        $this->assertSame([['tenant-7', 1, 0], ['tenant-7', 2, 0]], $retries);
        $this->assertSame([['tenant-7', 3, RetryExhaustionReason::AttemptsExhausted]], $giveUps);
    }

    public function testPerCallAttemptCapLowersButNeverRaisesTheCeiling(): void
    {
        $executor = new RetryExecutor(ExponentialBackoffRetryPolicy::withoutDelay(new TimeoutConfig(1, 3), 3), new NoDelaySleeper());

        $this->assertSame(1, $executor->withMaxAttempts(1)->getRetryPolicy()->getMaxAttempts());
        $this->assertSame(3, $executor->withMaxAttempts(10)->getRetryPolicy()->getMaxAttempts());
    }

    // -------------------------------------------------------------------------
    // Per-request timeouts
    // -------------------------------------------------------------------------

    public function testCallbackTimeoutAwareClientProducesANewInstancePerTimeoutConfig(): void
    {
        $built = [];
        $transport = new CallbackTimeoutAwareClient(
            function (TimeoutConfig $timeouts) use (&$built): ClientInterface {
                $built[] = (string) $timeouts;

                return new class implements ClientInterface {
                    public function sendRequest(RequestInterface $request): ResponseInterface
                    {
                        return (new Psr17Factory())->createResponse(200);
                    }
                };
            },
            new TimeoutConfig(1, 3)
        );

        $escalated = $transport->withTimeouts(new TimeoutConfig(2, 6));

        /* The receiver is left untouched: on transport shared between tenants, reconfiguring it in place is how one
           tenant's escalated budget ends up applied to the next tenant's shopper-facing call. */
        $this->assertNotSame($transport, $escalated);
        $this->assertSame(3, $transport->getTimeouts()->transferTimeout);
        $this->assertSame(6, $escalated->getTimeouts()->transferTimeout);
        $this->assertSame(['TimeoutConfig(connection=1s, transfer=3s)', 'TimeoutConfig(connection=2s, transfer=6s)'], $built);
    }

    public function testCallbackTimeoutAwareClientReusesTheInstanceForIdenticalTimeouts(): void
    {
        $transport = new CallbackTimeoutAwareClient(
            fn (TimeoutConfig $timeouts): ClientInterface => new class implements ClientInterface {
                public function sendRequest(RequestInterface $request): ResponseInterface
                {
                    return (new Psr17Factory())->createResponse(200);
                }
            },
            new TimeoutConfig(1, 3)
        );

        $this->assertSame($transport, $transport->withTimeouts(new TimeoutConfig(1, 3)));
    }
}
