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

use Comfino\Api\CircuitBreaker\CircuitBreakerState;
use Comfino\Api\CircuitBreaker\InMemoryCircuitBreakerStore;
use Comfino\Api\RateLimit\InMemoryTokenBucketStore;
use Comfino\Api\RateLimit\TokenBucket;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * The process-local stores must not grow for the life of the process.
 *
 * A php-fpm worker dies with the request, so an uncapped map there is invisible. A RoadRunner or Swoole worker serving
 * many merchants accumulates a key per tenant - times endpoints for the limiter, times hosts for the breaker - and
 * never releases one, which is the same shape of leak the `Unknown*` enum flyweights are capped against.
 */
final class BoundedStoreTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Token bucket store
    // -------------------------------------------------------------------------

    public function testBucketMapStopsGrowingAtItsCeiling(): void
    {
        $store = new InMemoryTokenBucketStore(64);

        for ($key = 0; $key < 5000; $key++) {
            $store->set("shop-$key|/orders", new TokenBucket(1.0, 1000.0 + $key));
        }

        $this->assertLessThanOrEqual(64, $store->bucketCount());
        $this->assertGreaterThan(0, $store->bucketCount(), 'Eviction must trim the map, not empty it.');
    }

    /**
     * Eviction resets a key to a full bucket, so it always costs accuracy - the question is which key can afford it.
     * The bucket untouched longest has had the longest to refill, so it is closest to full already.
     */
    public function testTheLeastRecentlyUpdatedBucketIsTheOneDropped(): void
    {
        $store = new InMemoryTokenBucketStore(8);

        // Eight buckets, ascending staleness: 'stale-0' is the oldest.
        for ($key = 0; $key < 8; $key++) {
            $store->set("stale-$key", new TokenBucket(1.0, 1000.0 + $key));
        }

        $store->set('fresh', new TokenBucket(1.0, 2000.0));

        $this->assertNull($store->get('stale-0'), 'The stalest bucket should have been evicted.');
        $this->assertNotNull($store->get('stale-7'), 'A recently used bucket must survive.');
        $this->assertNotNull($store->get('fresh'));
    }

    public function testOverwritingAnExistingBucketNeverTriggersEviction(): void
    {
        $store = new InMemoryTokenBucketStore(4);

        foreach (['a', 'b', 'c', 'd'] as $index => $key) {
            $store->set($key, new TokenBucket(1.0, 1000.0 + $index));
        }

        // At the ceiling, but this key is already tracked - so nothing needs to make room for it.
        $store->set('a', new TokenBucket(2.0, 3000.0));

        $this->assertSame(4, $store->bucketCount());
        $this->assertNotNull($store->get('b'));
    }

    public function testABucketCeilingBelowOneIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new InMemoryTokenBucketStore(0);
    }

    // -------------------------------------------------------------------------
    // Circuit breaker store
    // -------------------------------------------------------------------------

    public function testBreakerMapStopsGrowingAtItsCeiling(): void
    {
        $store = new InMemoryCircuitBreakerStore(64);

        for ($key = 0; $key < 5000; $key++) {
            $store->set("shop-$key|api-ecommerce.comfino.pl", new CircuitBreakerState(1));
        }

        $this->assertLessThanOrEqual(64, $store->keyCount());
        $this->assertGreaterThan(0, $store->keyCount(), 'Eviction must trim the map, not empty it.');
    }

    /**
     * An open breaker is the state worth keeping - dropping it lets a fleet back onto a host that is still down. A
     * closed one holds only a partial failure count, and losing that merely delays opening.
     */
    public function testAnOpenBreakerSurvivesEvictionAheadOfClosedOnes(): void
    {
        $store = new InMemoryCircuitBreakerStore(8);

        $store->set('down-host', new CircuitBreakerState(5, 1000.0));

        // Fill the rest with closed states, then push past the ceiling.
        for ($key = 0; $key < 7; $key++) {
            $store->set("healthy-$key", new CircuitBreakerState(1));
        }

        $store->set('newcomer', new CircuitBreakerState(1));

        $survivor = $store->get('down-host');

        $this->assertNotNull($survivor, 'The open breaker must not be the one evicted.');
        $this->assertSame(1000.0, $survivor->openedAt);
    }

    public function testAKeyCeilingBelowOneIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new InMemoryCircuitBreakerStore(0);
    }

    /**
     * The defaults have to be generous enough that a realistic deployment never evicts - the cap is a backstop against
     * an unbounded worker, not a working limit.
     */
    public function testTheDefaultCeilingsHoldARealisticKeySet(): void
    {
        $buckets = new InMemoryTokenBucketStore();
        $states = new InMemoryCircuitBreakerStore();

        // 100 merchants across the ~18 endpoints this library calls, and both API hosts.
        for ($tenant = 0; $tenant < 100; $tenant++) {
            for ($endpoint = 0; $endpoint < 18; $endpoint++) {
                $buckets->set("shop-$tenant|/v1/endpoint-$endpoint", new TokenBucket(1.0, 1000.0));
            }

            foreach (['api-ecommerce.comfino.pl', 'api-ecommerce.craty.pl'] as $host) {
                $states->set("shop-$tenant|$host", new CircuitBreakerState(1));
            }
        }

        $this->assertSame(1800, $buckets->bucketCount(), 'No eviction at a realistic key count.');
        $this->assertSame(200, $states->keyCount());
    }
}
