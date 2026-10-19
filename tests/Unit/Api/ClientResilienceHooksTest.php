<?php

/**
 * ComfinoPay PHP API client
 *
 * Backend routines for communication with the ComfinoPay payment gateway REST API.
 *
 * @package Comfino\Tests\Unit\Api
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-api-client
 */

declare(strict_types=1);

namespace Comfino\Tests\Unit\Api;

use Comfino\Api\ApiContext;
use Comfino\Api\CircuitBreaker\CircuitBreakerInterface;
use Comfino\Api\Client;
use Comfino\Api\Exception\ServiceUnavailable;
use Comfino\Api\HttpErrorExceptionInterface;
use Comfino\Api\RateLimit\OutboundRateLimiterInterface;
use Comfino\Api\RateLimit\Reservation;
use Comfino\Api\RequestObserverInterface;
use Http\Mock\Client as MockHttpClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Throwable;

/**
 * The legacy credential-bound client reaches the circuit breaker, rate limiter and request observer of the shared client it forwards to.
 */
final class ClientResilienceHooksTest extends TestCase
{
    private Psr17Factory $psr17Factory;
    private MockHttpClient $transport;

    protected function setUp(): void
    {
        parent::setUp();

        $this->psr17Factory = new Psr17Factory();
        $this->transport = new MockHttpClient($this->psr17Factory);
    }

    /**
     * @throws Throwable
     * @throws HttpErrorExceptionInterface
     * @throws ClientExceptionInterface
     */
    public function testOpenBreakerFailsFastWithoutReachingTheTransport(): void
    {
        $breaker = $this->createMock(CircuitBreakerInterface::class);
        $breaker->method('isOpen')->willReturn(true);

        $client = $this->client($breaker);

        try {
            $client->getWidgetKey();
            $this->fail('The call must fail fast.');
        } catch (ServiceUnavailable $e) {
            $this->assertStringContainsString('circuit breaker is open', $e->getMessage());
        }

        $this->assertSame([], $this->transport->getRequests());
    }

    /**
     * @throws Throwable
     * @throws HttpErrorExceptionInterface
     * @throws ClientExceptionInterface
     */
    public function testBreakerKeyCarriesTheTenantKey(): void
    {
        $breaker = $this->createMock(CircuitBreakerInterface::class);
        $breaker->expects($this->once())->method('isOpen')->with('store_3|api-ecommerce.comfino.pl')->willReturn(false);
        $this->queueJson('"widget-key"');

        $client = $this->client($breaker);
        $client->setTenantKey('store_3');

        $this->assertSame('widget-key', $client->getWidgetKey());
    }

    /**
     * @throws Throwable
     * @throws HttpErrorExceptionInterface
     * @throws ClientExceptionInterface
     */
    public function testSetterAfterAFirstRequestRebuildsTheSharedClient(): void
    {
        $this->queueJson('"first"');
        $client = $this->client();
        $client->getWidgetKey();

        $breaker = $this->createMock(CircuitBreakerInterface::class);
        $breaker->method('isOpen')->willReturn(true);
        $client->setCircuitBreaker($breaker);

        $this->expectException(ServiceUnavailable::class);

        $client->getWidgetKey();
    }

    /**
     * @throws Throwable
     * @throws HttpErrorExceptionInterface
     * @throws ClientExceptionInterface
     */
    public function testObserverIsNotifiedOncePerRequestWithTheTenantKey(): void
    {
        $this->queueJson('"widget-key"');
        $tenants = [];

        $observer = $this->createMock(RequestObserverInterface::class);
        $observer->expects($this->once())->method('onRequest');
        $observer->expects($this->once())->method('onResponse')->willReturnCallback(
            static function (ApiContext $context) use (&$tenants): void {
                $tenants[] = $context->tenantKey;
            }
        );

        $client = new Client($this->transport, $this->psr17Factory, $this->psr17Factory, 'key', requestObserver: $observer);
        $client->setTenantKey('store_1');
        $client->getWidgetKey();

        $this->assertSame(['store_1'], $tenants);
    }

    /**
     * @throws Throwable
     * @throws HttpErrorExceptionInterface
     * @throws ClientExceptionInterface
     */
    public function testRateLimiterRejectsBeforeTheTransport(): void
    {
        $limiter = $this->createMock(OutboundRateLimiterInterface::class);
        $limiter->method('reserve')->willReturn(new Reservation(false, 1000));

        $client = new Client($this->transport, $this->psr17Factory, $this->psr17Factory, 'key', rateLimiter: $limiter);

        try {
            $client->getWidgetKey();
            $this->fail('The call must be rejected.');
        } catch (ServiceUnavailable) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame([], $this->transport->getRequests());
    }

    /**
     * @throws HttpErrorExceptionInterface
     * @throws Throwable
     * @throws ClientExceptionInterface
     */
    public function testDefaultConstructionHasNoGuards(): void
    {
        $this->queueJson('"widget-key"');

        $this->assertSame('widget-key', $this->client()->getWidgetKey());
        $this->assertCount(1, $this->transport->getRequests());
    }

    private function client(?CircuitBreakerInterface $breaker = null): Client
    {
        return new Client($this->transport, $this->psr17Factory, $this->psr17Factory, 'key', circuitBreaker: $breaker);
    }

    private function queueJson(string $body): void
    {
        $this->transport->addResponse(
            $this->psr17Factory->createResponse(200)
                ->withHeader('Content-Type', 'application/json')
                ->withBody($this->psr17Factory->createStream($body))
        );
    }
}
