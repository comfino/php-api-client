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

use Comfino\Api\AbstractClient;
use Comfino\Api\ApiContext;
use Comfino\Api\HttpErrorExceptionInterface;
use Comfino\Api\NullRequestObserver;
use Comfino\Api\OnLimit;
use Comfino\Api\RequestObserverInterface;
use Comfino\Api\RequestOptions;
use Comfino\Api\Retry\ExponentialBackoffRetryPolicy;
use Comfino\Api\Retry\NoDelaySleeper;
use Comfino\Api\Retry\RetryExecutor;
use Comfino\Api\Retry\TimeoutConfig;
use Comfino\Api\SharedClient;
use Comfino\Api\Dto\Payment\LoanQueryCriteria;
use Comfino\Tests\Unit\Api\Stub\FakeNetworkException;
use Http\Mock\Client as MockHttpClient;
use InvalidArgumentException;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * The property the whole multi-tenant redesign exists for: a call made for one merchant can never carry another
 * merchant's credential, even though both merchants are served by one client instance over one transport.
 *
 * Previously the only way to get that guarantee was discipline - construct a client per credential and throw it away -
 * which worked but allocated a client, a retry executor, and a policy per availability probe, and left the library no
 * way to amortize anything.
 */
final class SharedClientMultitenancyTest extends TestCase
{
    private Psr17Factory $psr17Factory;
    private MockHttpClient $mockHttpClient;

    protected function setUp(): void
    {
        $this->psr17Factory = new Psr17Factory();
        $this->mockHttpClient = new MockHttpClient($this->psr17Factory);
    }

    /**
     * @throws ClientExceptionInterface
     * @throws Throwable
     * @throws HttpErrorExceptionInterface
     */
    public function testEachTenantsCallCarriesOnlyItsOwnKey(): void
    {
        $client = $this->makeClient();
        $tenantA = new ApiContext('key-tenant-a', true, tenantKey: 'a');
        $tenantB = new ApiContext('key-tenant-b', true, tenantKey: 'b');

        $this->mockHttpClient->addResponse($this->jsonResponse('true'));
        $client->isShopAccountActive($tenantA);
        $requestA = $this->mockHttpClient->getLastRequest();

        $this->mockHttpClient->addResponse($this->jsonResponse('true'));
        $client->isShopAccountActive($tenantB);
        $requestB = $this->mockHttpClient->getLastRequest();

        $this->assertSame('key-tenant-a', $requestA->getHeaderLine('Api-Key'));
        $this->assertSame('key-tenant-b', $requestB->getHeaderLine('Api-Key'));
    }

    /**
     * @throws ClientExceptionInterface
     * @throws HttpErrorExceptionInterface
     * @throws Throwable
     */
    public function testSandboxModeIsPerTenantNotPerClient(): void
    {
        $client = $this->makeClient();

        $this->mockHttpClient->addResponse($this->jsonResponse('true'));
        $client->isShopAccountActive(new ApiContext('key-a', true));
        $sandboxUri = (string) $this->mockHttpClient->getLastRequest()->getUri();

        $this->mockHttpClient->addResponse($this->jsonResponse('true'));
        $client->isShopAccountActive(new ApiContext('key-b', false));
        $productionUri = (string) $this->mockHttpClient->getLastRequest()->getUri();

        // A production merchant's order created against the sandbox host is the failure a shared flag causes.
        $this->assertStringStartsWith(AbstractClient::SANDBOX_API_BASE_URL, $sandboxUri);
        $this->assertStringStartsWith(AbstractClient::PRODUCTION_API_BASE_URL, $productionUri);
    }

    /**
     * @throws ClientExceptionInterface
     * @throws HttpErrorExceptionInterface
     * @throws Throwable
     */
    public function testCustomHeadersDoNotAccumulateAcrossTenants(): void
    {
        $client = $this->makeClient();
        $withHeader = (new ApiContext('key-a', true))->withCustomHeader('X-Tenant-Note', 'a');
        $withoutHeader = new ApiContext('key-b', true);

        $this->mockHttpClient->addResponse($this->jsonResponse('true'));
        $client->isShopAccountActive($withHeader);
        $this->assertSame('a', $this->mockHttpClient->getLastRequest()->getHeaderLine('X-Tenant-Note'));

        $this->mockHttpClient->addResponse($this->jsonResponse('true'));
        $client->isShopAccountActive($withoutHeader);
        $this->assertFalse($this->mockHttpClient->getLastRequest()->hasHeader('X-Tenant-Note'));
    }

    /**
     * @throws ClientExceptionInterface
     * @throws HttpErrorExceptionInterface
     * @throws Throwable
     */
    public function testCorrelationIdIsNotSharedBetweenTenants(): void
    {
        $client = $this->makeClient();

        $this->mockHttpClient->addResponse($this->jsonResponse('true'));
        $client->isShopAccountActive(new ApiContext('key-a', true));
        $trackIdA = $this->mockHttpClient->getLastRequest()->getHeaderLine('Comfino-Track-Id');

        $this->mockHttpClient->addResponse($this->jsonResponse('true'));
        $client->isShopAccountActive(new ApiContext('key-b', true));
        $trackIdB = $this->mockHttpClient->getLastRequest()->getHeaderLine('Comfino-Track-Id');

        /* A lazily minted, instance-cached ID would weld every later tenant's calls to the first tenant's, and then
           leak that ID into the second tenant's order record. */
        $this->assertNotSame('', $trackIdA);
        $this->assertNotSame($trackIdA, $trackIdB);
    }

    /**
     * @throws ClientExceptionInterface
     * @throws HttpErrorExceptionInterface
     * @throws Throwable
     */
    public function testOneContextKeepsItsCorrelationIdAcrossCalls(): void
    {
        $client = $this->makeClient();
        $context = (new ApiContext('key-a', true))->withGeneratedTrackId('unit-host');

        $this->mockHttpClient->addResponse($this->jsonResponse('true'));
        $client->isShopAccountActive($context);
        $first = $this->mockHttpClient->getLastRequest()->getHeaderLine('Comfino-Track-Id');

        $this->mockHttpClient->addResponse($this->jsonResponse('true'));
        $client->isShopAccountActive($context);
        $second = $this->mockHttpClient->getLastRequest()->getHeaderLine('Comfino-Track-Id');

        $this->assertSame($first, $second);
        $this->assertStringStartsWith('unit-host-', $first);
    }

    // -------------------------------------------------------------------------
    // ApiContext as a value object
    // -------------------------------------------------------------------------

    public function testWithersLeaveTheOriginalUntouched(): void
    {
        $original = new ApiContext('key-a', false, tenantKey: 'a');
        $derived = $original->withApiKey('key-b')->withSandboxMode(true)->withTenantKey('b');

        $this->assertSame('key-a', $original->apiKey);
        $this->assertFalse($original->sandboxMode);
        $this->assertSame('a', $original->tenantKey);
        $this->assertSame('key-b', $derived->apiKey);
        $this->assertTrue($derived->sandboxMode);
        $this->assertSame('b', $derived->tenantKey);
    }

    public function testGeneratedTrackIdIsNotOverwrittenBySubsequentGeneration(): void
    {
        $context = (new ApiContext('key'))->withGeneratedTrackId('host');

        $this->assertSame($context, $context->withGeneratedTrackId('other-host'));
    }

    public function testDisallowedBaseUrlIsRejectedAtConstructionTime(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ApiContext('key', false, 'https://evil.example.com');
    }

    public function testHeaderInjectionIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/header injection/i');

        (new ApiContext('key'))->withCustomHeader('X-Note', "value\r\nX-Injected: yes");
    }

    public function testMalformedTrackIdIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new ApiContext('key'))->withTrackId('not a valid track id!');
    }

    public function testApiHostIsDerivedFromTheEffectiveBaseUrl(): void
    {
        $this->assertSame('api-ecommerce.craty.pl', (new ApiContext('key', true))->getApiHost());
        $this->assertSame('api-ecommerce.comfino.pl', (new ApiContext('key', false))->getApiHost());
    }

    // -------------------------------------------------------------------------
    // Per-call options
    // -------------------------------------------------------------------------

    /**
     * @throws ClientExceptionInterface
     * @throws HttpErrorExceptionInterface
     * @throws Throwable
     */
    public function testPerCallApiVersionOverridesTheClientDefault(): void
    {
        $client = $this->makeClient();

        $this->mockHttpClient->addResponse($this->jsonResponse('true'));
        $client->isShopAccountActive(
            new ApiContext('key-a', true),
            null,
            null,
            (new RequestOptions())->andApiVersion(3)
        );

        $this->assertStringContainsString('/v3/', (string) $this->mockHttpClient->getLastRequest()->getUri());
    }

    /**
     * @throws ClientExceptionInterface
     */
    public function testPerCallAttemptCapStopsTheRetrySequenceEarly(): void
    {
        $client = $this->makeClient();

        for ($i = 0; $i < 3; $i++) {
            $this->mockHttpClient->addException(new FakeNetworkException());
        }

        try {
            $client->isShopAccountActive(new ApiContext('key-a', true), null, null, RequestOptions::failFast());

            $this->fail('Expected a network failure to surface.');
        } catch (Throwable) {
            // A single attempt was made, so two of the three queued failures are still pending.
        }

        $this->assertCount(1, $this->mockHttpClient->getRequests());
    }

    public function testAttemptCeilingBelowOneIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        RequestOptions::attempts(0);
    }

    // -------------------------------------------------------------------------
    // Observability
    // -------------------------------------------------------------------------

    /**
     * @throws ClientExceptionInterface
     * @throws HttpErrorExceptionInterface
     * @throws Throwable
     */
    public function testObserverSeesTheTenantOnEveryCall(): void
    {
        $seen = [];

        $observer = new class ($seen) implements RequestObserverInterface {
            /** @param array<int, array{string|null, string, int}> $seen */
            public function __construct(public array &$seen)
            {
            }

            public function onRequest(ApiContext $context, RequestInterface $request, int $attempt): void
            {
                $this->seen[] = [$context->tenantKey, 'request', $attempt];
            }

            public function onResponse(
                ApiContext $context,
                RequestInterface $request,
                ResponseInterface $response,
                float $durationMs
            ): void {
                $this->seen[] = [$context->tenantKey, 'response', $response->getStatusCode()];
            }

            public function onFailure(
                ApiContext $context,
                RequestInterface $request,
                Throwable $error,
                int $attempt
            ): void {
                $this->seen[] = [$context->tenantKey, 'failure', $attempt];
            }
        };

        $client = $this->makeClient($observer);

        $this->mockHttpClient->addResponse($this->jsonResponse('true'));
        $client->isShopAccountActive(new ApiContext('key-a', true, tenantKey: 'tenant-a'));

        $this->assertSame([['tenant-a', 'request', 1], ['tenant-a', 'response', 200]], $seen);
    }

    // -------------------------------------------------------------------------
    // The legacy surface, still working
    // -------------------------------------------------------------------------

    /**
     * @throws ClientExceptionInterface
     * @throws HttpErrorExceptionInterface
     * @throws Throwable
     */
    public function testTwoBoundClientsShareOneTransportWithoutSharingCredentials(): void
    {
        $shared = $this->makeClient();
        $boundA = $shared->bind(new ApiContext('key-a', true));
        $boundB = $shared->bind(new ApiContext('key-b', true));

        $this->mockHttpClient->addResponse($this->jsonResponse('true'));
        $boundA->isShopAccountActive();
        $this->assertSame('key-a', $this->mockHttpClient->getLastRequest()->getHeaderLine('Api-Key'));

        $this->mockHttpClient->addResponse($this->jsonResponse('true'));
        $boundB->isShopAccountActive();
        $this->assertSame('key-b', $this->mockHttpClient->getLastRequest()->getHeaderLine('Api-Key'));

        $this->assertSame($shared, $boundA->getSharedClient());
        $this->assertSame($shared, $boundB->getSharedClient());
    }

    public function testBoundClientSettersReplaceItsOwnContextOnly(): void
    {
        $shared = $this->makeClient();
        $boundA = $shared->bind(new ApiContext('key-a', true));
        $boundB = $shared->bind(new ApiContext('key-b', true));

        $boundA->setApiKey('rotated-a');

        $this->assertSame('rotated-a', $boundA->getApiKey());
        $this->assertSame('key-b', $boundB->getApiKey());
    }

    public function testBoundClientClearsCustomHeaders(): void
    {
        $bound = $this->makeClient()->bind(new ApiContext('key-a', true));

        $bound->addCustomHeader('X-One', '1');
        $bound->addCustomHeader('X-Two', '2');
        $bound->removeCustomHeader('X-One');

        $this->assertSame(['X-Two' => '2'], $bound->getContext()->customHeaders);

        $bound->clearCustomHeaders();

        $this->assertSame([], $bound->getContext()->customHeaders);
    }

    /**
     * @throws ClientExceptionInterface
     * @throws HttpErrorExceptionInterface
     * @throws Throwable
     */
    public function testFinancialProductsCallCarriesTheContextLanguageAndCurrency(): void
    {
        $client = $this->makeClient();
        $context = (new ApiContext('key-a', true))->withApiLanguage('en')->withApiCurrency('EUR');

        $this->mockHttpClient->addResponse($this->jsonResponse('[]'));
        $client->getFinancialProducts($context, new LoanQueryCriteria(10000, null, null, null));

        $request = $this->mockHttpClient->getLastRequest();

        $this->assertSame('en', $request->getHeaderLine('Api-Language'));
        $this->assertSame('EUR', $request->getHeaderLine('Api-Currency'));
    }

    public function testDefaultOnLimitIsFailFast(): void
    {
        $this->assertSame(OnLimit::FailFast, (new RequestOptions())->onLimit);
    }

    private function makeClient(?RequestObserverInterface $observer = null): SharedClient
    {
        return new SharedClient(
            $this->mockHttpClient,
            $this->psr17Factory,
            $this->psr17Factory,
            1,
            null,
            new RetryExecutor(
                ExponentialBackoffRetryPolicy::withoutDelay(new TimeoutConfig(1, 3), 3),
                new NoDelaySleeper()
            ),
            $observer ?? new NullRequestObserver()
        );
    }

    private function jsonResponse(string $body, int $statusCode = 200): ResponseInterface
    {
        return $this->psr17Factory->createResponse($statusCode)
            ->withHeader('Content-Type', 'application/json')
            ->withBody($this->psr17Factory->createStream($body));
    }
}
