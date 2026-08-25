<?php

/**
 * Comfino PHP API client
 *
 * Backend routines for communication with the Comfino payment gateway REST API.
 *
 * @package Comfino\Tests\Unit\Api\Retry
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-api-client
 */

declare(strict_types=1);

namespace Comfino\Tests\Unit\Api\Retry;

use Comfino\Api\Client;
use Comfino\Api\Exception\AuthorizationError;
use Comfino\Api\Exception\ConnectionTimeout;
use Comfino\Api\Exception\RetryExhaustedException;
use Comfino\Api\HttpErrorExceptionInterface;
use Comfino\Api\Retry\ExponentialBackoffRetryPolicy;
use Comfino\Api\Retry\NoDelaySleeper;
use Comfino\Api\Retry\RetryExhaustionReason;
use Comfino\Api\Retry\RetryExecutor;
use Comfino\Api\Retry\TimeoutConfig;
use Comfino\Tests\Unit\Api\Stub\FakeNetworkException;
use Http\Mock\Client as MockHttpClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Throwable;

/**
 * Regression suite for the retry exit that used to be unreachable.
 *
 * Every assertion here is driven by the **real** {@see ExponentialBackoffRetryPolicy}, not an always-retry mock. That
 * is the point: the mock is exactly what hid the defect, because it answered "retry" forever and so never exercised
 * the path where the policy declines. With the production policy the executor always left through `throw $error`, the
 * exhaustion exception after the loop was dead code, and the {@see ConnectionTimeout} that carries the attempt count,
 * the final timeouts, the URI and the body was never produced - so every plugin's `catch (ConnectionTimeout)` branch
 * was dead for the one scenario it was written for.
 *
 * One case per exit: attempts spent, and budget spent before the attempts are.
 */
final class RealPolicyRetryExhaustionTest extends TestCase
{
    private Psr17Factory $psr17Factory;
    private MockHttpClient $mockHttpClient;

    protected function setUp(): void
    {
        $this->psr17Factory = new Psr17Factory();
        $this->mockHttpClient = new MockHttpClient($this->psr17Factory);
    }

    /**
     * Exit 1: the attempt budget runs out. Three attempts, everyone a network failure, a budget generous enough that
     * it is never the binding constraint.
     *
     * @throws ClientExceptionInterface
     * @throws HttpErrorExceptionInterface
     * @throws Throwable
     */
    public function testAttemptsSpentUnderTheRealPolicyProducesConnectionTimeout(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->mockHttpClient->addException(new FakeNetworkException());
        }

        $policy = new ExponentialBackoffRetryPolicy(new TimeoutConfig(1, 3), 3, 1000);

        try {
            $this->makeClient($policy)->isShopAccountActive();

            $this->fail('Expected ConnectionTimeout to be thrown.');
        } catch (ConnectionTimeout $e) {
            $this->assertSame(3, $e->getConnectAttemptIdx());
            $this->assertSame($policy->getConnectionTimeout(3), $e->getConnectionTimeout());
            $this->assertSame($policy->getTransferTimeout(3), $e->getTransferTimeout());
            $this->assertStringContainsString('/v1/user/is-active', $e->getUrl());
            $this->assertStringContainsString('attempts exhausted', $e->getMessage());
            $this->assertInstanceOf(FakeNetworkException::class, $e->getPrevious());
        }
    }

    /**
     * Exit 2: the total transfer budget runs out before the attempts do. A budget of 15s against base transfer
     * timeouts of 5s/10s leaves nothing for a third attempt, so the sequence stops at two even though the policy
     * allows five.
     *
     * @throws ClientExceptionInterface
     * @throws HttpErrorExceptionInterface
     * @throws Throwable
     */
    public function testBudgetSpentUnderTheRealPolicyProducesConnectionTimeout(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->mockHttpClient->addException(new FakeNetworkException());
        }

        $policy = new ExponentialBackoffRetryPolicy(new TimeoutConfig(1, 5), 5, 15);

        // Sanity check on the fixture: the third attempt is what the budget cannot afford.
        $this->assertSame(0, $policy->getTransferTimeout(3));

        try {
            $this->makeClient($policy)->isShopAccountActive();

            $this->fail('Expected ConnectionTimeout to be thrown.');
        } catch (ConnectionTimeout $e) {
            $this->assertSame(2, $e->getConnectAttemptIdx());
            $this->assertStringContainsString('time budget exhausted', $e->getMessage());
        }
    }

    /**
     * A non-retryable error still leaves as itself. Wrapping it would hide the status code and the response body behind
     * a timeout that never happened.
     *
     * @throws ClientExceptionInterface
     * @throws HttpErrorExceptionInterface
     * @throws Throwable
     */
    public function testNonRetryableErrorIsNotReportedAsATimeout(): void
    {
        $this->mockHttpClient->addResponse(
            $this->psr17Factory->createResponse(401)
                ->withHeader('Content-Type', 'application/json')
                ->withBody($this->psr17Factory->createStream('{"errors":["unauthorized"]}'))
        );

        $this->expectException(AuthorizationError::class);

        $this->makeClient(new ExponentialBackoffRetryPolicy(new TimeoutConfig(1, 3), 3))->isShopAccountActive();
    }

    /**
     * The exhaustion reason is reported on the exception itself, not only in the message.
     *
     * @throws Throwable
     */
    public function testRetryExhaustedExceptionNamesTheExitItTookThrough(): void
    {
        $executor = new RetryExecutor(
            new ExponentialBackoffRetryPolicy(new TimeoutConfig(1, 3), 2, null),
            new NoDelaySleeper()
        );

        try {
            $executor->execute(static fn () => throw new FakeNetworkException());

            $this->fail('Expected RetryExhaustedException to be thrown.');
        } catch (RetryExhaustedException $e) {
            $this->assertSame(RetryExhaustionReason::AttemptsExhausted, $e->getReason());
            $this->assertSame(2, $e->getAttemptCount());
            $this->assertNotNull($e->getLastTimeoutConfig());
        }
    }

    /**
     * Builds a client whose retries never actually sleep, so the suite stays fast while still running the real policy.
     */
    private function makeClient(ExponentialBackoffRetryPolicy $policy): Client
    {
        $client = new Client(
            $this->mockHttpClient,
            $this->psr17Factory,
            $this->psr17Factory,
            'test-api-key',
            1,
            null,
            new RetryExecutor($policy, new NoDelaySleeper())
        );
        $client->enableSandboxMode();

        return $client;
    }
}
