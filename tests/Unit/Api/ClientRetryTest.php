<?php

/**
 * Comfino PHP API client
 *
 * Backend routines for communication with the Comfino payment gateway REST API.
 *
 * @package Comfino\Tests\Unit\Api
 * @author Artur Kozubski <akozubski@comperia.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-api-client
 */

declare(strict_types=1);

namespace Comfino\Tests\Unit\Api;

use Comfino\Api\Client;
use Comfino\Api\Exception\ConnectionTimeout;
use Comfino\Api\Retry\ExponentialBackoffRetryPolicy;
use Comfino\Api\Retry\RetryExecutor;
use Comfino\Api\Retry\RetryPolicyInterface;
use Comfino\Api\Retry\TimeoutConfig;
use Comfino\Tests\Unit\Api\Stub\FakeNetworkException;
use Comfino\Tests\Unit\Api\Stub\TimeoutAwareMockHttpClient;
use Http\Mock\Client as MockHttpClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

final class ClientRetryTest extends TestCase
{
    private Psr17Factory $psr17Factory;
    private MockHttpClient $mockHttpClient;

    protected function setUp(): void
    {
        $this->psr17Factory = new Psr17Factory();
        $this->mockHttpClient = new MockHttpClient($this->psr17Factory);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function createJsonResponse(int $status, string $body): ResponseInterface
    {
        return $this->psr17Factory->createResponse($status)
            ->withHeader('Content-Type', 'application/json')
            ->withBody($this->psr17Factory->createStream($body));
    }

    /**
     * Builds a Client backed by MockHttpClient with the given RetryExecutor.
     */
    private function makeClient(RetryExecutor $retryExecutor): Client
    {
        $client = new Client(
            $this->mockHttpClient,
            $this->psr17Factory,
            $this->psr17Factory,
            'test-api-key',
            1,
            null,
            $retryExecutor
        );
        $client->enableSandboxMode();

        return $client;
    }

    /**
     * A mock RetryPolicyInterface whose shouldRetry() always returns true, so the RetryExecutor runs all maxAttempts
     * and then throws RetryExhaustedException.
     *
     * @param int $connectionTimeout Value returned for getConnectionTimeout()
     * @param int $transferTimeout Value returned for getTransferTimeout()
     */
    private function makeAlwaysRetryPolicy(
        int $maxAttempts = 2,
        int $connectionTimeout = 5,
        int $transferTimeout = 15
    ): RetryPolicyInterface {
        $policy = $this->createMock(RetryPolicyInterface::class);
        $policy->method('getMaxAttempts')->willReturn($maxAttempts);
        $policy->method('shouldRetry')->willReturn(true);
        $policy->method('getConnectionTimeout')->willReturn($connectionTimeout);
        $policy->method('getTransferTimeout')->willReturn($transferTimeout);
        $policy->method('getBaseConnectionTimeout')->willReturn($connectionTimeout);
        $policy->method('getBaseTransferTimeout')->willReturn($transferTimeout);

        return $policy;
    }

    // =========================================================================
    // sendRequest - with retry executor
    // =========================================================================

    // -------------------------------------------------------------------------
    // Success paths
    // -------------------------------------------------------------------------

    /**
     * When a RetryExecutor is injected and the first attempt succeeds, the response is returned normally
     * (no retry triggered).
     *
     * @throws ClientExceptionInterface
     */
    public function testWithRetryExecutorSucceedsOnFirstAttempt(): void
    {
        $this->mockHttpClient->addResponse($this->createJsonResponse(200, 'true'));

        $policy = new ExponentialBackoffRetryPolicy(new TimeoutConfig(1, 3), 2);
        $client = $this->makeClient(new RetryExecutor($policy));

        $this->assertTrue($client->isShopAccountActive());
    }

    /**
     * When the first attempt throws a retryable network error and the second succeeds, the client returns the
     * successful response. The HTTP client does NOT implement TimeoutAwareClientInterface, so no timeout escalation is
     * attempted.
     *
     * @throws ClientExceptionInterface
     */
    public function testWithRetryExecutorSucceedsAfterRetryWithoutTimeoutAwareClient(): void
    {
        $this->mockHttpClient->addException(new FakeNetworkException());
        $this->mockHttpClient->addResponse($this->createJsonResponse(200, 'true'));

        $client = $this->makeClient(new RetryExecutor(new ExponentialBackoffRetryPolicy(new TimeoutConfig(1, 3), 2)));

        $this->assertTrue($client->isShopAccountActive());
    }

    // -------------------------------------------------------------------------
    // TimeoutAwareClientInterface - updateTimeouts() escalation
    // -------------------------------------------------------------------------

    /**
     * When the HTTP client implements TimeoutAwareClientInterface and a retry occurs, updateTimeouts() is called with
     * the next attempt's escalated values.
     *
     * @throws ClientExceptionInterface
     */
    public function testUpdateTimeoutsIsCalledOnRetryWhenClientIsTimeoutAware(): void
    {
        $timeoutAwareClient = new TimeoutAwareMockHttpClient();
        $timeoutAwareClient->addException(new FakeNetworkException());
        $timeoutAwareClient->addResponse($this->createJsonResponse(200, 'true'));

        $policy = new ExponentialBackoffRetryPolicy(new TimeoutConfig(1, 3), 2);
        $retryExecutor = new RetryExecutor($policy);

        $client = new Client(
            $timeoutAwareClient,
            $this->psr17Factory,
            $this->psr17Factory,
            'test-api-key',
            1,
            null,
            $retryExecutor
        );
        $client->enableSandboxMode();
        $client->isShopAccountActive();

        // onRetry fires for attempt 1 → updateTimeouts is called with attempt-2 values.
        $this->assertCount(1, $timeoutAwareClient->updateTimeoutCalls);

        [$conn, $transfer] = $timeoutAwareClient->updateTimeoutCalls[0];

        $this->assertSame($policy->getConnectionTimeout(2), $conn);
        $this->assertSame($policy->getTransferTimeout(2), $transfer);
    }

    /**
     * When the HTTP client does NOT implement TimeoutAwareClientInterface, no updateTimeouts() call is made even when
     * a retry occurs.
     *
     * @throws ClientExceptionInterface
     */
    public function testUpdateTimeoutsIsNotCalledForNonTimeoutAwareClient(): void
    {
        // mockHttpClient does not implement TimeoutAwareClientInterface.
        $this->mockHttpClient->addException(new FakeNetworkException());
        $this->mockHttpClient->addResponse($this->createJsonResponse(200, 'true'));

        $result = $this->makeClient(new RetryExecutor(new ExponentialBackoffRetryPolicy(new TimeoutConfig(1, 3), 2)))
            ->isShopAccountActive();

        // Reaching here without a call to updateTimeouts() on a non-timeout-aware client is the contract being tested.
        $this->assertTrue($result);
    }

    // -------------------------------------------------------------------------
    // RetryExhaustedException → ConnectionTimeout conversion
    // -------------------------------------------------------------------------

    /**
     * When all retry attempts are exhausted, the RetryExhaustedException is converted into a ConnectionTimeout
     * exception (so that platform plugins don't need to know about the internal retry exception).
     *
     * @throws ClientExceptionInterface
     */
    public function testRetryExhaustedIsConvertedToConnectionTimeout(): void
    {
        /* Two attempts, both fail - the always-retry mock policy causes the executor to throw RetryExhaustedException
           after the loop. */
        $this->mockHttpClient->addException(new RuntimeException('Network error'));
        $this->mockHttpClient->addException(new RuntimeException('Network error'));

        $client = $this->makeClient(new RetryExecutor($this->makeAlwaysRetryPolicy(2)));

        $this->expectException(ConnectionTimeout::class);

        $client->isShopAccountActive();
    }

    /**
     * The ConnectionTimeout produced from an exhausted retry carries the attempt count and the timeout values from the
     * last retry configuration.
     *
     * @throws ClientExceptionInterface
     */
    public function testConnectionTimeoutCarriesAttemptCountAndTimeoutValues(): void
    {
        $this->mockHttpClient->addException(new RuntimeException('err'));
        $this->mockHttpClient->addException(new RuntimeException('err'));

        $client = $this->makeClient(new RetryExecutor($this->makeAlwaysRetryPolicy()));

        try {
            $client->isShopAccountActive();

            $this->fail('Expected ConnectionTimeout to be thrown.');
        } catch (ConnectionTimeout $e) {
            // Attempt count comes from RetryExhaustedException::getAttemptCount().
            $this->assertSame(2, $e->getConnectAttemptIdx());

            // Timeout values come from the last TimeoutConfig built inside RetryExecutor.
            $this->assertSame(5, $e->getConnectionTimeout());
            $this->assertSame(15, $e->getTransferTimeout());
        }
    }

    /**
     * The ConnectionTimeout's URL is populated from the request object when the RetryExhaustedException carries no
     * request URI (the normal case, since RetryExecutor uses the plain constructor, not withRequestContext()).
     *
     * @throws ClientExceptionInterface
     */
    public function testConnectionTimeoutUrlComesFromRequestObject(): void
    {
        $this->mockHttpClient->addException(new RuntimeException('err'));
        $this->mockHttpClient->addException(new RuntimeException('err'));

        $client = $this->makeClient(new RetryExecutor($this->makeAlwaysRetryPolicy(2)));

        try {
            $client->isShopAccountActive();

            $this->fail('Expected ConnectionTimeout to be thrown.');
        } catch (ConnectionTimeout $e) {
            $this->assertStringContainsString('/v1/user/is-active', $e->getUrl());
        }
    }

    /**
     * When the base policy timeouts are used as fallback (lastTimeoutConfig is null), the ConnectionTimeout still
     * carries sensible values. We verify this by asserting that getConnectionTimeout() / getTransferTimeout()
     * match what getBaseConnectionTimeout() / getBaseTransferTimeout() return.
     *
     * Note: RetryExecutor always sets a lastTimeoutConfig via TimeoutConfig::fromRetryPolicy(), so in practice the
     * null-coalescing fallback is defensive. We exercise the non-null path here (which is the real path) to confirm
     * values are carried correctly.
     *
     * @throws ClientExceptionInterface
     */
    public function testConnectionTimeoutTimeoutsMatchPolicyValues(): void
    {
        $this->mockHttpClient->addException(new RuntimeException('err'));
        $this->mockHttpClient->addException(new RuntimeException('err'));

        $policy = $this->makeAlwaysRetryPolicy(2, connectionTimeout: 3, transferTimeout: 9);
        $client = $this->makeClient(new RetryExecutor($policy));

        try {
            $client->isShopAccountActive();

            $this->fail('Expected ConnectionTimeout.');
        } catch (ConnectionTimeout $e) {
            $this->assertSame($policy->getConnectionTimeout(2), $e->getConnectionTimeout());
            $this->assertSame($policy->getTransferTimeout(2), $e->getTransferTimeout());
        }
    }
}
