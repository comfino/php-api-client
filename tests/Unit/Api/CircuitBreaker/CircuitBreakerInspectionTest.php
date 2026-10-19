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

use Comfino\Api\CircuitBreaker\CircuitBreaker;
use Comfino\Api\CircuitBreaker\CircuitBreakerStatus;
use Comfino\Api\CircuitBreaker\InMemoryCircuitBreakerStore;
use Comfino\Api\CircuitBreaker\InspectableCircuitBreakerInterface;
use Comfino\Api\Support\FrozenClock;
use PHPUnit\Framework\TestCase;

final class CircuitBreakerInspectionTest extends TestCase
{
    private FrozenClock $clock;
    private CircuitBreaker $breaker;

    protected function setUp(): void
    {
        $this->clock = new FrozenClock(1000.0);
        $this->breaker = new CircuitBreaker(new InMemoryCircuitBreakerStore(), 5, 30, $this->clock);
    }

    public function testUnknownKeyIsClosedWithNoFailures(): void
    {
        $snapshot = $this->breaker->inspect('k');

        $this->assertSame(CircuitBreakerStatus::Closed, $snapshot->status);
        $this->assertSame(0, $snapshot->consecutiveFailures);
        $this->assertNull($snapshot->openedAt);
        $this->assertNull($snapshot->retryAt);
    }

    public function testFourFailuresAreStillClosed(): void
    {
        $this->fail4();

        $snapshot = $this->breaker->inspect('k');

        $this->assertSame(CircuitBreakerStatus::Closed, $snapshot->status);
        $this->assertSame(4, $snapshot->consecutiveFailures);
        $this->assertNull($snapshot->retryAt);
    }

    public function testFiveFailuresOpenTheBreaker(): void
    {
        $this->fail4();
        $this->breaker->recordFailure('k');

        $snapshot = $this->breaker->inspect('k');

        $this->assertSame(CircuitBreakerStatus::Open, $snapshot->status);
        $this->assertSame(5, $snapshot->consecutiveFailures);
        $this->assertSame(1000.0, $snapshot->openedAt);
        $this->assertSame(1030.0, $snapshot->retryAt);
    }

    public function testElapsedWindowReadsHalfOpenAndInspectionDoesNotClaimTheProbe(): void
    {
        $this->open();
        $this->clock->advance(31.0);

        $snapshot = $this->breaker->inspect('k');

        $this->assertSame(CircuitBreakerStatus::HalfOpen, $snapshot->status);
        $this->assertSame(1030.0, $snapshot->retryAt);

        // Inspecting twice, then the real caller still gets the probe.
        $this->breaker->inspect('k');
        $this->assertFalse($this->breaker->isOpen('k'));
    }

    public function testClaimedProbeStaysHalfOpenAndPointsAtItsOwnExpiry(): void
    {
        $this->open();
        $this->clock->advance(31.0);
        $this->breaker->isOpen('k');

        $snapshot = $this->breaker->inspect('k');

        $this->assertSame(CircuitBreakerStatus::HalfOpen, $snapshot->status);
        $this->assertSame(1061.0, $snapshot->retryAt);
    }

    public function testSuccessfulProbeClosesAndResetsTheCounter(): void
    {
        $this->open();
        $this->clock->advance(31.0);
        $this->breaker->isOpen('k');
        $this->breaker->recordSuccess('k');

        $snapshot = $this->breaker->inspect('k');

        $this->assertSame(CircuitBreakerStatus::Closed, $snapshot->status);
        $this->assertSame(0, $snapshot->consecutiveFailures);
    }

    private function fail4(): void
    {
        for ($i = 0; $i < 4; $i++) {
            $this->breaker->recordFailure('k');
        }
    }

    private function open(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->breaker->recordFailure('k');
        }
    }
}
