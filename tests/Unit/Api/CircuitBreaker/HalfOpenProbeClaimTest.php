<?php

/**
 * Comfino PHP API client
 *
 * Backend routines for communication with the Comfino payment gateway REST API.
 *
 * @package Comfino\Tests\Unit\Api\CircuitBreaker
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-api-client
 */

declare(strict_types=1);

namespace Comfino\Tests\Unit\Api\CircuitBreaker;

use Comfino\Api\CircuitBreaker\CircuitBreaker;
use Comfino\Api\CircuitBreaker\CircuitBreakerStoreInterface;
use Comfino\Api\CircuitBreaker\InMemoryCircuitBreakerStore;
use Comfino\Api\Support\FrozenClock;
use Comfino\Tests\Unit\Api\Stub\PlainCircuitBreakerStore;
use Comfino\Tests\Unit\Api\Stub\SwapRefusingCircuitBreakerStore;
use PHPUnit\Framework\TestCase;

/**
 * Who gets the half-open probe when several workers share one breaker store.
 *
 * This is the part of a shared breaker that a get-then-set store gets wrong in a way that matters. Every worker that
 * looks at the key after the open window elapses reads "open, window over" and concludes the probe is its own — so a
 * host that is down receives one probe per worker, on a timer, which is the synchronized burst the breaker was
 * installed to prevent. Two breaker instances over one store stand in for two workers.
 */
final class HalfOpenProbeClaimTest extends TestCase
{
    private FrozenClock $clock;

    protected function setUp(): void
    {
        $this->clock = new FrozenClock(1000.0);
    }

    public function testExactlyOneWorkerClaimsTheProbeWhenTheStoreCanSwap(): void
    {
        $store = new InMemoryCircuitBreakerStore();
        [$workerA, $workerB] = [$this->breaker($store), $this->breaker($store)];

        $this->openBreaker($workerA);
        $this->clock->advance(31.0);

        $this->assertFalse($workerA->isOpen('key'), 'The first worker to look claims the probe.');
        $this->assertTrue($workerB->isOpen('key'), 'The second must keep failing fast, not probe as well.');
    }

    /**
     * A store that cannot swap still half-opens correctly; what it loses is only a genuine race.
     *
     * The claim is a blind write, so two workers whose reads interleave before either writes both probe. That is not
     * reproducible in one process — the write lands before the second read — and it is the documented cost of the
     * plain interface rather than something this test can catch. What it *can* pin is that the sequential behavior is
     * the same, so a plugin on a process-local store sees no change.
     */
    public function testAStoreThatCannotSwapStillHalfOpens(): void
    {
        $store = new PlainCircuitBreakerStore();
        [$workerA, $workerB] = [$this->breaker($store), $this->breaker($store)];

        $this->openBreaker($workerA);
        $this->clock->advance(31.0);

        $this->assertFalse($workerA->isOpen('key'), 'The first caller probes.');
        $this->assertTrue($workerB->isOpen('key'), 'The claim was written, so the second caller sees it.');
    }

    /**
     * Losing the swap is the one case where a caller is told "open" while the window has elapsed.
     *
     * A store refusing every swap stands in for a worker that is always beaten to the claim. It must fail fast rather
     * than probe anyway — probing on a lost claim is the fleet-sized burst the claim exists to prevent.
     */
    public function testACallerThatLosesTheClaimKeepsFailingFast(): void
    {
        $breaker = $this->breaker(new SwapRefusingCircuitBreakerStore());

        $this->openBreaker($breaker);
        $this->clock->advance(31.0);

        $this->assertTrue($breaker->isOpen('key'));
    }

    /**
     * A probe that never comes back must not wedge the breaker.
     *
     * The claim is a stamp, not a lock, so a worker that dies mid-probe leaves it set forever. Treating a probe older
     * than the open window as lost is what turns "no probe ever again" into "one more probe, one window later".
     */
    public function testAProbeThatIsNeverResolvedIsReclaimedAfterTheOpenWindow(): void
    {
        $store = new InMemoryCircuitBreakerStore();
        [$workerA, $workerB] = [$this->breaker($store), $this->breaker($store)];

        $this->openBreaker($workerA);
        $this->clock->advance(31.0);

        $this->assertFalse($workerA->isOpen('key'), 'Worker A claims the probe and then, notionally, dies.');
        $this->assertTrue($workerB->isOpen('key'));

        $this->clock->advance(31.0);

        $this->assertFalse($workerB->isOpen('key'), 'The abandoned probe has aged out and may be re-claimed.');
    }

    public function testASuccessfulProbeClosesTheBreakerForEveryone(): void
    {
        $store = new InMemoryCircuitBreakerStore();
        [$workerA, $workerB] = [$this->breaker($store), $this->breaker($store)];

        $this->openBreaker($workerA);
        $this->clock->advance(31.0);
        $workerA->isOpen('key');
        $workerA->recordSuccess('key');

        $this->assertFalse($workerB->isOpen('key'));
    }

    public function testAFailedProbeReopensTheBreakerForEveryone(): void
    {
        $store = new InMemoryCircuitBreakerStore();
        [$workerA, $workerB] = [$this->breaker($store), $this->breaker($store)];

        $this->openBreaker($workerA);
        $this->clock->advance(31.0);
        $workerA->isOpen('key');
        $workerA->recordFailure('key');

        $this->assertTrue($workerB->isOpen('key'));
    }

    public function testTheBreakerReportsWhetherItCanClaim(): void
    {
        $this->assertTrue($this->breaker(new InMemoryCircuitBreakerStore())->isExact());
        $this->assertFalse($this->breaker(new PlainCircuitBreakerStore())->isExact());
    }

    /**
     * A failure is never silently dropped, however, contended the key is.
     *
     * The swap loop's exhaustion path writes unconditionally, because an undercounted failure only delays opening
     * while a discarded one can keep a dead host looking healthy forever.
     */
    public function testAFailureIsRecordedEvenWhenEverySwapIsLost(): void
    {
        $breaker = $this->breaker(new SwapRefusingCircuitBreakerStore(), threshold: 1);
        $breaker->recordFailure('key');

        $this->assertTrue($breaker->isOpen('key'));
    }

    private function breaker(CircuitBreakerStoreInterface $store, int $threshold = 2): CircuitBreaker
    {
        return new CircuitBreaker($store, $threshold, 30, $this->clock);
    }

    private function openBreaker(CircuitBreaker $breaker): void
    {
        $breaker->recordFailure('key');
        $breaker->recordFailure('key');

        $this->assertTrue($breaker->isOpen('key'), 'Precondition: the breaker is open.');
    }
}
