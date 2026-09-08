<?php

/**
 * ComfinoPay PHP API client
 *
 * Backend routines for communication with the ComfinoPay payment gateway REST API.
 *
 * @package Comfino\Tests\Unit\Api\RateLimit
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-api-client
 */

declare(strict_types=1);

namespace Comfino\Tests\Unit\Api\RateLimit;

use Comfino\Api\ApiContext;
use Comfino\Api\Dto\Payment\LoanQueryCriteria;
use Comfino\Api\Exception\RateLimitExceeded;
use Comfino\Api\Exception\ServiceUnavailable;
use Comfino\Api\HttpErrorExceptionInterface;
use Comfino\Api\OnLimit;
use Comfino\Api\RateLimit\InMemoryTokenBucketStore;
use Comfino\Api\RateLimit\NullRateLimiter;
use Comfino\Api\RateLimit\RateLimitKey;
use Comfino\Api\RateLimit\TokenBucketRateLimiter;
use Comfino\Api\RateLimit\TwoTierRateLimiter;
use Comfino\Api\RequestOptions;
use Comfino\Api\SharedClient;
use Comfino\Api\Support\FrozenClock;
use Http\Mock\Client as MockHttpClient;
use InvalidArgumentException;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Throwable;

final class OutboundRateLimiterTest extends TestCase
{
    private FrozenClock $clock;

    protected function setUp(): void
    {
        $this->clock = new FrozenClock(1000.0);
    }

    public function testBurstUpToCapacityIsAccepted(): void
    {
        $limiter = $this->makeLimiter(3, 1.0);

        for ($i = 0; $i < 3; $i++) {
            $this->assertTrue($limiter->reserve('key')->accepted);
        }

        $this->assertFalse($limiter->reserve('key')->accepted);
    }

    public function testRejectionReportsWhenCapacityReturns(): void
    {
        $limiter = $this->makeLimiter(1, 2.0);

        $limiter->reserve('key');
        $reservation = $limiter->reserve('key');

        $this->assertFalse($reservation->accepted);
        // One token at two tokens per second is half a second away.
        $this->assertSame(500, $reservation->retryAfterMs);
    }

    public function testBucketRefillsOverTime(): void
    {
        $limiter = $this->makeLimiter(2, 1.0);

        $limiter->reserve('key');
        $limiter->reserve('key');
        $this->assertFalse($limiter->reserve('key')->accepted);

        $this->clock->advance(1.0);

        $this->assertTrue($limiter->reserve('key')->accepted);
    }

    public function testRefillNeverExceedsCapacity(): void
    {
        $limiter = $this->makeLimiter(2, 10.0);

        $limiter->reserve('key', 2);
        $this->clock->advance(60.0);

        $this->assertTrue($limiter->reserve('key', 2)->accepted);
        $this->assertFalse($limiter->reserve('key')->accepted);
    }

    public function testKeysAreIndependentSoOneTenantCannotStarveAnother(): void
    {
        $limiter = $this->makeLimiter(1, 1.0);

        $this->assertTrue($limiter->reserve(RateLimitKey::build('tenant-a', '/orders'))->accepted);
        $this->assertTrue($limiter->reserve(RateLimitKey::build('tenant-b', '/orders'))->accepted);
        $this->assertFalse($limiter->reserve(RateLimitKey::build('tenant-a', '/orders'))->accepted);
    }

    public function testTheKeyIsTheEndpointSoQueryParametersShareOneBucket(): void
    {
        $limiter = $this->makeLimiter(1, 1.0);
        $probe = 'https://api-ecommerce.craty.pl/v1/financial-products';

        $this->assertTrue($limiter->reserve(RateLimitKey::build('a', $probe . '?loanAmount=130000'))->accepted);

        /*
         * The cart total is as close to unique per call as a query parameter gets. Keyed on the whole URI this second
         * reservation would find an untouched bucket, and the limiter would be inert on the one endpoint whose volume
         * is the reason to have one.
         */
        $this->assertFalse($limiter->reserve(RateLimitKey::build('a', $probe . '?loanAmount=130100'))->accepted);
    }

    public function testHostsStayApartSoSandboxDoesNotSpendProductionsBudget(): void
    {
        $limiter = $this->makeLimiter(1, 1.0);

        $this->assertTrue($limiter->reserve(RateLimitKey::build('a', 'https://api-ecommerce.comfino.pl/v1/orders'))->accepted);
        $this->assertTrue($limiter->reserve(RateLimitKey::build('a', 'https://api-ecommerce.craty.pl/v1/orders'))->accepted);
    }

    public function testAnEndpointNameThatIsNotAUrlIsKeyedAsGiven(): void
    {
        $this->assertSame('a|orders', RateLimitKey::build('a', 'orders'));
        $this->assertSame('_|/orders', RateLimitKey::build(null, '/orders'));
        $this->assertSame('a|Comfino\\Api\\SharedClient', RateLimitKey::build('a', 'Comfino\\Api\\SharedClient'));
    }

    public function testACostLargerThanTheBucketIsRejectedRatherThanWaitingForever(): void
    {
        $reservation = $this->makeLimiter(2, 1.0)->reserve('key', 5);

        $this->assertFalse($reservation->accepted);
        $this->assertSame(2000, $reservation->retryAfterMs);
    }

    public function testInvalidConfigurationIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new TokenBucketRateLimiter(0, 1.0);
    }

    public function testZeroCostReservationIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->makeLimiter(2, 1.0)->reserve('key', 0);
    }

    public function testNullLimiterAcceptsEverything(): void
    {
        $this->assertTrue((new NullRateLimiter())->reserve('key', 1000)->accepted);
    }

    // -------------------------------------------------------------------------
    // Two tiers: fairness inside protection
    // -------------------------------------------------------------------------

    public function testGlobalTierProtectsTheApiEvenWhenEachTenantIsWithinItsShare(): void
    {
        $limiter = new TwoTierRateLimiter($this->makeLimiter(5, 1.0), $this->makeLimiter(2, 1.0));

        $this->assertTrue($limiter->reserve(RateLimitKey::build('a', '/orders'))->accepted);
        $this->assertTrue($limiter->reserve(RateLimitKey::build('b', '/orders'))->accepted);

        // Both tenants are still inside their own buckets, but the process as a whole has spent the account's quota.
        $this->assertFalse($limiter->reserve(RateLimitKey::build('c', '/orders'))->accepted);
    }

    public function testTenantTierIsConsultedFirstSoABusyTenantCannotBurnGlobalCapacity(): void
    {
        $globalLimiter = $this->makeLimiter(10, 1.0);
        $limiter = new TwoTierRateLimiter($this->makeLimiter(1, 1.0), $globalLimiter);

        $limiter->reserve(RateLimitKey::build('a', '/orders'));
        $limiter->reserve(RateLimitKey::build('a', '/orders'));
        $limiter->reserve(RateLimitKey::build('a', '/orders'));

        // Only the first call reached the global tier, leaving nine of its ten tokens for everyone else.
        $this->assertSame(8, $globalLimiter->reserve('_global')->remaining);
    }

    // -------------------------------------------------------------------------
    // Wired into the client: what happens on rejection is the call site's decision
    // -------------------------------------------------------------------------

    /**
     * @throws Throwable
     * @throws HttpErrorExceptionInterface
     * @throws ClientExceptionInterface
     */
    public function testFailFastCallSiteGetsServiceUnavailable(): void
    {
        $this->expectException(ServiceUnavailable::class);

        $this->makeExhaustedClient()->isShopAccountActive(
            new ApiContext('key-a', true, tenantKey: 'a'),
            null,
            null,
            (new RequestOptions())->andOnLimit(OnLimit::FailFast)
        );
    }

    /**
     * @throws Throwable
     * @throws HttpErrorExceptionInterface
     * @throws ClientExceptionInterface
     */
    public function testQueueingCallSiteGetsARetryAfterHint(): void
    {
        try {
            $this->makeExhaustedClient()->isShopAccountActive(
                new ApiContext('key-a', true, tenantKey: 'a'),
                null,
                null,
                (new RequestOptions())->andOnLimit(OnLimit::Queue)
            );

            $this->fail('Expected RateLimitExceeded to be thrown.');
        } catch (RateLimitExceeded $e) {
            $this->assertSame(429, $e->getStatusCode());
            $this->assertGreaterThan(0, $e->getRetryAfterMs());
        }
    }

    /**
     * @throws Throwable
     * @throws HttpErrorExceptionInterface
     * @throws ClientExceptionInterface
     */
    public function testRejectedCallNeverReachesTheTransport(): void
    {
        $psr17Factory = new Psr17Factory();
        $mockHttpClient = new MockHttpClient($psr17Factory);
        $client = new SharedClient(
            $mockHttpClient,
            $psr17Factory,
            $psr17Factory,
            1,
            null,
            null,
            null,
            new TokenBucketRateLimiter(1, 1.0, new InMemoryTokenBucketStore(), $this->clock)
        );
        $context = new ApiContext('key-a', true, tenantKey: 'a');

        $mockHttpClient->addResponse(
            $psr17Factory->createResponse(200)
                ->withHeader('Content-Type', 'application/json')
                ->withBody($psr17Factory->createStream('true'))
        );

        $client->isShopAccountActive($context);

        try {
            $client->isShopAccountActive($context);
        } catch (ServiceUnavailable) {
            // Expected: the bucket is empty.
        }

        // Never block a request thread on a limiter and never spend an attempt on a call the limiter declined.
        $this->assertCount(1, $mockHttpClient->getRequests());
    }

    /**
     * The whole-pipeline version of {@see testTheKeyIsTheEndpointSoQueryParametersShareOneBucket}: the availability
     * probe carries the cart total, so if the key were the request URI this second call would find a full bucket and
     * the limiter would never engage on the endpoint that carries the traffic.
     *
     * @throws Throwable
     * @throws HttpErrorExceptionInterface
     * @throws ClientExceptionInterface
     */
    public function testTwoProbesForDifferentCartTotalsShareTheEndpointsBucket(): void
    {
        $psr17Factory = new Psr17Factory();
        $mockHttpClient = new MockHttpClient($psr17Factory);
        $client = new SharedClient(
            $mockHttpClient,
            $psr17Factory,
            $psr17Factory,
            1,
            null,
            null,
            null,
            new TokenBucketRateLimiter(1, 1.0, new InMemoryTokenBucketStore(), $this->clock)
        );
        $context = new ApiContext('key-a', true, tenantKey: 'a');

        /* Two responses queued, so a second call that reaches the wire fails this test on the assertion, not on a
           missing mock. */
        for ($i = 0; $i < 2; $i++) {
            $mockHttpClient->addResponse(
                $psr17Factory->createResponse(200)
                    ->withHeader('Content-Type', 'application/json')
                    ->withBody($psr17Factory->createStream('[]'))
            );
        }

        $client->getFinancialProducts($context, new LoanQueryCriteria(130000));

        try {
            $client->getFinancialProducts($context, new LoanQueryCriteria(130100));

            $this->fail('Expected the second probe to be refused: it addresses the same endpoint.');
        } catch (ServiceUnavailable) {
            // Expected: one bucket for the endpoint, and the first probe spent it.
        }

        $this->assertCount(1, $mockHttpClient->getRequests());
    }

    private function makeExhaustedClient(): SharedClient
    {
        $psr17Factory = new Psr17Factory();
        $limiter = new TokenBucketRateLimiter(1, 1.0, new InMemoryTokenBucketStore(), $this->clock);
        $limiter->reserve(RateLimitKey::build('a', 'https://api-ecommerce.craty.pl/v1/user/is-active'));

        return new SharedClient(
            new MockHttpClient($psr17Factory),
            $psr17Factory,
            $psr17Factory,
            1,
            null,
            null,
            null,
            $limiter
        );
    }

    private function makeLimiter(int $capacity, float $refillPerSecond): TokenBucketRateLimiter
    {
        return new TokenBucketRateLimiter(
            $capacity,
            $refillPerSecond,
            new InMemoryTokenBucketStore(),
            $this->clock
        );
    }
}
