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
use Comfino\Api\HttpErrorExceptionInterface;
use Comfino\Api\Request;
use Comfino\Api\Request\CancelOrder;
use Comfino\Api\Request\CreateOrder as CreateOrderRequest;
use Comfino\Api\Request\GetWidgetKey;
use Comfino\Api\Request\IsShopAccountActive;
use Comfino\Api\Response\CustomResponse;
use Comfino\Api\Retry\ExponentialBackoffRetryPolicy;
use Comfino\Api\Retry\NoDelaySleeper;
use Comfino\Api\Retry\RetryExecutor;
use Comfino\Api\Retry\TimeoutConfig;
use Comfino\Api\SharedClient;
use Comfino\Shop\Order\OrderInterface;
use Comfino\Tests\Unit\Api\Stub\FakeNetworkException;
use Http\Mock\Client as MockHttpClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * Whether a failed request may be sent again.
 *
 * The audit that prompted this suite assumed `POST /orders` was unsafe to replay, and it would be for an endpoint with
 * no dedup key. It is not: the API keys the order on `orderId` (mandatory, unique per shop) and, when a request arrives
 * for an id that already exists with the same body hash, answers with the **existing** order at `201 Created`. So order
 * creation is idempotent - enforced server-side rather than here.
 *
 * The mechanism still matters for requests a caller writes itself: a `CustomRequest` hitting an endpoint with no such
 * key can declare `isIdempotent(): false` and will then be sent exactly once.
 */
final class RequestReplaySafetyTest extends TestCase
{
    private Psr17Factory $psr17Factory;
    private MockHttpClient $mockHttpClient;

    protected function setUp(): void
    {
        $this->psr17Factory = new Psr17Factory();
        $this->mockHttpClient = new MockHttpClient($this->psr17Factory);
    }

    public function testReadsAndCancellationsAreIdempotent(): void
    {
        $this->assertTrue((new IsShopAccountActive(null, null))->isIdempotent());
        $this->assertTrue((new CancelOrder('order-1'))->isIdempotent());
        $this->assertTrue((new GetWidgetKey())->isIdempotent());
    }

    public function testOrderCreationIsIdempotentBecauseTheApiDeduplicatesTheReplay(): void
    {
        $order = $this->createStub(OrderInterface::class);

        $this->assertTrue($this->makeCreateOrderRequest($order, false)->isIdempotent());
        $this->assertTrue($this->makeCreateOrderRequest($order, true)->isIdempotent());
    }

    public function testNonIdempotentRequestIsSentExactlyOnceDespiteARetryablePolicy(): void
    {
        $client = $this->makeClient();

        for ($i = 0; $i < 3; $i++) {
            $this->mockHttpClient->addException(new FakeNetworkException());
        }

        try {
            $client->sendCustomRequest(
                new ApiContext('key-a', true),
                $this->nonIdempotentRequest(),
                CustomResponse::class
            );

            $this->fail('Expected the transport failure to surface.');
        } catch (Throwable) {
            // Expected: the request cannot be replayed, so the first failure is final.
        }

        $this->assertCount(1, $this->mockHttpClient->getRequests());
    }

    public function testARetryPutsTheIdenticalBytesOnTheWire(): void
    {
        $client = $this->makeClient();

        $this->mockHttpClient->addException(new FakeNetworkException());
        $this->mockHttpClient->addResponse($this->jsonResponse('true'));

        $client->isShopAccountActive(new ApiContext('key-a', true));

        $requests = $this->mockHttpClient->getRequests();

        /* The API's dedup of a replayed order creation is keyed on the hash of the body, so a retry that rebuilt the
           request could be answered with a validation error instead of the original order. The PSR-7 request is built
           once per call and reused, which is what makes that key usable. */
        $this->assertCount(2, $requests);
        $this->assertSame((string) $requests[0]->getUri(), (string) $requests[1]->getUri());
        $this->assertSame((string) $requests[0]->getBody(), (string) $requests[1]->getBody());
        $this->assertSame($requests[0]->getHeaderLine('Comfino-Track-Id'), $requests[1]->getHeaderLine('Comfino-Track-Id'));
    }

    public function testTransientStatusIsRetriedForAnIdempotentRequest(): void
    {
        $client = $this->makeClient();

        $this->mockHttpClient->addResponse($this->jsonResponse('{"errors":["busy"]}', 503));
        $this->mockHttpClient->addResponse($this->jsonResponse('true'));

        /* The inline path used to give up on a 503 exactly where the SDK's queue-side classifier would have kept
           going - the two libraries disagreed about what "transient" meant. */
        $this->assertTrue($client->isShopAccountActive(new ApiContext('key-a', true)));
        $this->assertCount(2, $this->mockHttpClient->getRequests());
    }

    /**
     * @throws HttpErrorExceptionInterface
     * @throws Throwable
     * @throws ClientExceptionInterface
     */
    public function testExhaustedTransientStatusStillSurfacesTheRealHttpError(): void
    {
        $client = $this->makeClient();

        for ($i = 0; $i < 3; $i++) {
            $this->mockHttpClient->addResponse($this->jsonResponse('{"errors":["busy"]}', 503));
        }

        /* A call that gave up on a 503 must not be reported as a timeout: the status-to-exception mapping is what the
           caller's error handling is written against. */
        $this->expectException(\Comfino\Api\Exception\ServiceUnavailable::class);

        $client->isShopAccountActive(new ApiContext('key-a', true));
    }

    /**
     * @param OrderInterface $order Stub order; only the accessors the request touches are exercised
     */
    private function makeCreateOrderRequest(OrderInterface $order, bool $validateOnly): CreateOrderRequest
    {
        return new CreateOrderRequest($order, 'key', $validateOnly);
    }

    private function nonIdempotentRequest(): Request
    {
        return new class extends Request {
            public function __construct()
            {
                $this->setRequestMethod('POST');
                $this->setApiEndpointPath('things');
            }

            public function isIdempotent(): bool
            {
                return false;
            }

            protected function prepareRequestBody(): array
            {
                return ['thing' => 'value'];
            }
        };
    }

    private function makeClient(): SharedClient
    {
        return new SharedClient(
            $this->mockHttpClient,
            $this->psr17Factory,
            $this->psr17Factory,
            1,
            null,
            new RetryExecutor(ExponentialBackoffRetryPolicy::withoutDelay(new TimeoutConfig(1, 3), 3), new NoDelaySleeper())
        );
    }

    private function jsonResponse(string $body, int $statusCode = 200): ResponseInterface
    {
        return $this->psr17Factory->createResponse($statusCode)
            ->withHeader('Content-Type', 'application/json')
            ->withBody($this->psr17Factory->createStream($body));
    }
}
