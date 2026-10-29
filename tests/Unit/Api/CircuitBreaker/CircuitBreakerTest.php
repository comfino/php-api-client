<?php

/**
 * ComfinoPay PHP API client
 *
 * Backend routines for communication with the ComfinoPay payment gateway REST API.
 *
 * @package Comfino\Tests\Unit\Api\CircuitBreaker
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-api-client
 */

declare(strict_types=1);

namespace Comfino\Tests\Unit\Api\CircuitBreaker;

use Comfino\Api\ApiContext;
use Comfino\Api\CircuitBreaker\CircuitBreaker;
use Comfino\Api\CircuitBreaker\CircuitBreakerKey;
use Comfino\Api\CircuitBreaker\InMemoryCircuitBreakerStore;
use Comfino\Api\Exception\ServiceUnavailable;
use Comfino\Api\Retry\ExponentialBackoffRetryPolicy;
use Comfino\Api\Retry\NoDelaySleeper;
use Comfino\Api\Retry\RetryExecutor;
use Comfino\Api\Retry\TimeoutConfig;
use Comfino\Api\SharedClient;
use Comfino\Api\Support\FrozenClock;
use Comfino\Tests\Unit\Api\Stub\FakeNetworkException;
use Http\Mock\Client as MockHttpClient;
use InvalidArgumentException;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Throwable;

final class CircuitBreakerTest extends TestCase
{
    private FrozenClock $clock;

    protected function setUp(): void
    {
        $this->clock = new FrozenClock(1000.0);
    }

    public function testBreakerStaysClosedBelowTheThreshold(): void
    {
        $breaker = $this->makeBreaker(3);

        $breaker->recordFailure('key');
        $breaker->recordFailure('key');

        $this->assertFalse($breaker->isOpen('key'));
    }

    public function testBreakerOpensAtTheThreshold(): void
    {
        $breaker = $this->makeBreaker(3);

        for ($i = 0; $i < 3; $i++) {
            $breaker->recordFailure('key');
        }

        $this->assertTrue($breaker->isOpen('key'));
    }

    public function testSuccessClosesTheBreakerAndResetsTheCount(): void
    {
        $breaker = $this->makeBreaker(2);

        $breaker->recordFailure('key');
        $breaker->recordSuccess('key');
        $breaker->recordFailure('key');

        $this->assertFalse($breaker->isOpen('key'));
    }

    public function testBreakerLetsOneProbeThroughAfterTheOpenWindow(): void
    {
        $breaker = $this->makeBreaker(1, 30);

        $breaker->recordFailure('key');
        $this->assertTrue($breaker->isOpen('key'));

        $this->clock->advance(31.0);

        // Half-open: the probe is allowed.
        $this->assertFalse($breaker->isOpen('key'));
    }

    public function testAFailedProbeReopensTheBreakerImmediately(): void
    {
        $breaker = $this->makeBreaker(3, 30);

        for ($i = 0; $i < 3; $i++) {
            $breaker->recordFailure('key');
        }

        $this->clock->advance(31.0);
        $breaker->isOpen('key');

        /* The failure count is preserved across the half-open probe, so one more failure re-opens the breaker rather
           than restarting the count from zero. */
        $breaker->recordFailure('key');

        $this->assertTrue($breaker->isOpen('key'));
    }

    public function testKeysAreIndependent(): void
    {
        $breaker = $this->makeBreaker(1);

        $breaker->recordFailure(CircuitBreakerKey::build('tenant-a', 'api-ecommerce.comfino.pl'));

        $this->assertTrue($breaker->isOpen(CircuitBreakerKey::build('tenant-a', 'api-ecommerce.comfino.pl')));
        $this->assertFalse($breaker->isOpen(CircuitBreakerKey::build('tenant-b', 'api-ecommerce.comfino.pl')));
    }

    public function testKeyCombinesTenantAndHost(): void
    {
        $this->assertSame('tenant-a|api.example', CircuitBreakerKey::build('tenant-a', 'api.example'));
        $this->assertSame('_|api.example', CircuitBreakerKey::build(null, 'api.example'));
    }

    public function testThresholdBelowOneIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new CircuitBreaker(new InMemoryCircuitBreakerStore(), 0);
    }

    // -------------------------------------------------------------------------
    // Wired into the client
    // -------------------------------------------------------------------------

    public function testOpenBreakerFailsFastWithoutTouchingTheTransport(): void
    {
        $psr17Factory = new Psr17Factory();
        $mockHttpClient = new MockHttpClient($psr17Factory);
        $breaker = $this->makeBreaker(1);
        $context = new ApiContext('key-a', true, tenantKey: 'tenant-a');

        $client = new SharedClient(
            $mockHttpClient,
            $psr17Factory,
            $psr17Factory,
            1,
            null,
            new RetryExecutor(
                ExponentialBackoffRetryPolicy::failFast(new TimeoutConfig(1, 3)),
                new NoDelaySleeper()
            ),
            null,
            null,
            $breaker
        );

        $mockHttpClient->addException(new FakeNetworkException());

        try {
            $client->isShopAccountActive($context);
        } catch (Throwable) {
            // The first call is what trips the breaker.
        }

        $requestsBefore = count($mockHttpClient->getRequests());

        $this->expectException(ServiceUnavailable::class);

        try {
            $client->isShopAccountActive($context);
        } finally {
            /* An open breaker is what keeps a ComfinoPay outage from becoming a connector outage: every worker would
               otherwise pay the full timeout on a dead socket, on every checkout render. */
            $this->assertCount($requestsBefore, $mockHttpClient->getRequests());
        }
    }

    public function testAuthorizationFailureDoesNotTripTheBreaker(): void
    {
        $psr17Factory = new Psr17Factory();
        $mockHttpClient = new MockHttpClient($psr17Factory);
        $breaker = $this->makeBreaker(1);
        $context = new ApiContext('wrong-key', true, tenantKey: 'tenant-a');

        $client = new SharedClient($mockHttpClient, $psr17Factory, $psr17Factory, 1, null, null, null, null, $breaker);

        $mockHttpClient->addResponse(
            $psr17Factory->createResponse(401)
                ->withHeader('Content-Type', 'application/json')
                ->withBody($psr17Factory->createStream('{"errors":["unauthorized"]}'))
        );

        try {
            $client->isShopAccountActive($context);
        } catch (Throwable) {
            // Expected: the key is wrong.
        }

        /* One merchant's wrong key must not open a breaker that would then block every healthy tenant on the same
           host - only transport failures and 5xx feed it. */
        $this->assertFalse($breaker->isOpen(CircuitBreakerKey::build('tenant-a', 'api-ecommerce.craty.pl')));
    }

    private function makeBreaker(int $threshold, int $openDuration = 30): CircuitBreaker
    {
        return new CircuitBreaker(new InMemoryCircuitBreakerStore(), $threshold, $openDuration, $this->clock);
    }
}
