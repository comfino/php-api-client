<?php

/**
 * ComfinoPay PHP API client
 *
 * Backend routines for communication with the ComfinoPay payment gateway REST API.
 *
 * @package Comfino\Tests\Unit\Api\Retry
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-api-client
 */

declare(strict_types=1);

namespace Comfino\Tests\Unit\Api\Retry;

use Comfino\Api\Exception\RetryExhaustedException;
use Comfino\Api\Retry\Classification;
use Comfino\Api\Retry\ExponentialBackoffRetryPolicy;
use Comfino\Api\Retry\Psr18ErrorDetector;
use Comfino\Api\Retry\RetryExecutor;
use Comfino\Api\Retry\RetryPolicyInterface;
use Comfino\Api\Retry\TimeoutConfig;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use RuntimeException;
use Throwable;

final class RetryExecutorTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Psr18ErrorDetector
    // -------------------------------------------------------------------------

    public function testNetworkExceptionIsRetryable(): void
    {
        $detector = new Psr18ErrorDetector();
        $exception = $this->createMock(NetworkExceptionInterface::class);

        $this->assertTrue($detector->isRetryable($exception));
    }

    public function testClientExceptionWithCurlTimeoutCodeIsRetryable(): void
    {
        $detector = new Psr18ErrorDetector();
        $exception = new class ('timeout', 28) extends RuntimeException implements ClientExceptionInterface {
        };

        $this->assertTrue($detector->isRetryable($exception));
    }

    public function testClientExceptionWithOtherCodeIsNotRetryable(): void
    {
        $detector = new Psr18ErrorDetector();
        $exception = new class ('error', 6) extends RuntimeException implements ClientExceptionInterface {
        };

        $this->assertFalse($detector->isRetryable($exception));
    }

    public function testGenericRuntimeExceptionIsNotRetryable(): void
    {
        $detector = new Psr18ErrorDetector();

        $this->assertFalse($detector->isRetryable(new RuntimeException('generic error')));
    }

    // -------------------------------------------------------------------------
    // TimeoutConfig
    // -------------------------------------------------------------------------

    public function testTimeoutConfigStoresValues(): void
    {
        $config = new TimeoutConfig(10, 30);

        $this->assertSame(10, $config->connectionTimeout);
        $this->assertSame(30, $config->transferTimeout);
    }

    public function testTimeoutConfigNegativeConnectionTimeoutThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/negative/i');

        new TimeoutConfig(-1, 10);
    }

    public function testTimeoutConfigNegativeTransferTimeoutThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/negative/i');

        new TimeoutConfig(0, -1);
    }

    public function testTimeoutConfigTransferLessThanConnectionThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new TimeoutConfig(10, 5);
    }

    public function testTimeoutConfigEquality(): void
    {
        $a = new TimeoutConfig(5, 15);
        $b = new TimeoutConfig(5, 15);
        $c = new TimeoutConfig(5, 20);

        $this->assertTrue($a->equals($b));
        $this->assertFalse($a->equals($c));
    }

    public function testTimeoutConfigToString(): void
    {
        $this->assertSame('TimeoutConfig(connection=5s, transfer=15s)', (string) new TimeoutConfig(5, 15));
    }

    public function testTimeoutConfigFromRetryPolicy(): void
    {
        $policy = $this->createMock(RetryPolicyInterface::class);
        $policy->method('getConnectionTimeout')->with(2)->willReturn(10);
        $policy->method('getTransferTimeout')->with(2)->willReturn(30);

        $config = TimeoutConfig::fromRetryPolicy($policy, 2);

        $this->assertSame(10, $config->connectionTimeout);
        $this->assertSame(30, $config->transferTimeout);
    }

    // -------------------------------------------------------------------------
    // ExponentialBackoffRetryPolicy
    // -------------------------------------------------------------------------

    /**
     * Valid config: connectionTimeout=5, transferTimeout=15 (15 = 5 * MIN_MULTIPLIER=3).
     *
     * The total transfer budget is disabled by default here, so that the tests below keep describing the escalation
     * maths itself. Tests covering the budget pass an explicit one.
     */
    private function makePolicy(
        int $conn = 5,
        int $transfer = 15,
        int $attempts = 3,
        ?int $maxTotalTransfer = null
    ): ExponentialBackoffRetryPolicy {
        return new ExponentialBackoffRetryPolicy(new TimeoutConfig($conn, $transfer), $attempts, $maxTotalTransfer);
    }

    public function testExponentialBackoffConstructionWithValidConfig(): void
    {
        $policy = $this->makePolicy();

        $this->assertSame(3, $policy->getMaxAttempts());
        $this->assertSame(5, $policy->getBaseConnectionTimeout());
        $this->assertSame(15, $policy->getBaseTransferTimeout());
    }

    public function testExponentialBackoffZeroConnectionTimeoutThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/connection timeout/i');

        /* TimeoutConfig(1, 1) is valid but ExponentialBackoffRetryPolicy requires transfer >= 3x connection.
           Use TimeoutConfig(1, 3) for 0-connection test: since TimeoutConfig won't accept connection=0, transfer=0.
           We create TimeoutConfig with conn=1, transfer=3 (exactly 3x). To get connection=0 in the policy, we would
           need conn=0 which TimeoutConfig allows (0 >= 0). Actually TimeoutConfig(0, 0) is valid (both 0).
           ExponentialBackoffRetryPolicy then checks conn < 1 → throws. */

        new ExponentialBackoffRetryPolicy(new TimeoutConfig(0, 0), 3);
    }

    public function testExponentialBackoffTransferTimeoutLessThanThreeXConnectionThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/transfer timeout/i');

        // conn=5, transfer=14 → 14 < 5*3=15 → should throw
        new ExponentialBackoffRetryPolicy(new TimeoutConfig(5, 14), 3);
    }

    public function testExponentialBackoffZeroMaxAttemptsThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/attempts/i');

        new ExponentialBackoffRetryPolicy(new TimeoutConfig(5, 15), 0);
    }

    public function testExponentialBackoffTimeoutEscalatesExponentially(): void
    {
        $policy = $this->makePolicy(5, 15, 4);

        // bit-shift: baseTimeout << (attempt - 1), capped at max
        $this->assertSame(5, $policy->getConnectionTimeout(1));   // 5 << 0 = 5
        $this->assertSame(10, $policy->getConnectionTimeout(2));  // 5 << 1 = 10
        $this->assertSame(20, $policy->getConnectionTimeout(3));  // 5 << 2 = 20
        $this->assertSame(15, $policy->getTransferTimeout(1));    // 15 << 0 = 15
        $this->assertSame(30, $policy->getTransferTimeout(2));    // 15 << 1 = 30
        $this->assertSame(60, $policy->getTransferTimeout(3));    // 15 << 2 = 60 (= MAX_TRANSFER_TIMEOUT)
    }

    public function testExponentialBackoffConnectionTimeoutCapsAtMax(): void
    {
        // attempt 4: 10 << 3 = 80, capped at MAX_CONNECTION_TIMEOUT=30
        $this->assertSame(30, $this->makePolicy(10, 30, 5)->getConnectionTimeout(4));
    }

    public function testExponentialBackoffTransferTimeoutCapsAtMax(): void
    {
        // attempt 4: 15 << 3 = 120, capped at MAX_TRANSFER_TIMEOUT=60
        $this->assertSame(60, $this->makePolicy(5, 15, 5)->getTransferTimeout(4));
    }

    public function testExponentialBackoffShouldRetryReturnsTrueForRetryableError(): void
    {
        $policy = $this->makePolicy(5, 15, 3);
        $retryable = $this->createMock(NetworkExceptionInterface::class);

        $this->assertTrue($policy->shouldRetry($retryable, 1));
        $this->assertTrue($policy->shouldRetry($retryable, 2));
    }

    public function testExponentialBackoffShouldRetryReturnsFalseAtMaxAttempts(): void
    {
        $policy = $this->makePolicy();
        $retryable = $this->createMock(NetworkExceptionInterface::class);

        $this->assertFalse($policy->shouldRetry($retryable, 3));  // attemptNumber >= maxAttempts
    }

    public function testExponentialBackoffShouldRetryReturnsFalseForNonRetryableError(): void
    {
        $this->assertFalse($this->makePolicy()->shouldRetry(new RuntimeException('non-retryable'), 1));
    }

    public function testExponentialBackoffBoundsTheTotalTransferTimeoutByDefault(): void
    {
        // Unbounded, base timeouts of 3s/9s over 3 attempts escalate to 9 + 18 + 36 = 63 seconds per API call.
        $policy = new ExponentialBackoffRetryPolicy(new TimeoutConfig(3, 9), 3);

        $this->assertSame(ExponentialBackoffRetryPolicy::DEFAULT_MAX_TOTAL_TRANSFER_TIMEOUT, $policy->getMaxTotalTransferTimeout());

        $totalTransferTimeout = 0;

        for ($attempt = 1; $attempt <= $policy->getMaxAttempts(); $attempt++) {
            $totalTransferTimeout += $policy->getTransferTimeout($attempt);
        }

        $this->assertSame(ExponentialBackoffRetryPolicy::DEFAULT_MAX_TOTAL_TRANSFER_TIMEOUT, $totalTransferTimeout);
    }

    public function testExponentialBackoffSpendsTheTotalTransferBudgetAcrossAttempts(): void
    {
        // Escalation would be 15, 30, 60; a 40 second budget leaves 25 for the second attempt and nothing after it.
        $policy = $this->makePolicy(5, 15, 3, 40);

        $this->assertSame(15, $policy->getTransferTimeout(1));
        $this->assertSame(25, $policy->getTransferTimeout(2));
        $this->assertSame(0, $policy->getTransferTimeout(3));
    }

    public function testExponentialBackoffCapsTheConnectionTimeoutByTheRemainingBudget(): void
    {
        /* The second attempt's transfer timeout is cut to 5 seconds by the budget, so its connection timeout cannot
           stay at the escalated 10: TimeoutConfig rejects a connection timeout above the transfer one. */
        $policy = $this->makePolicy(5, 15, 3, 20);

        $this->assertSame(5, $policy->getTransferTimeout(2));
        $this->assertSame(5, $policy->getConnectionTimeout(2));

        new TimeoutConfig($policy->getConnectionTimeout(2), $policy->getTransferTimeout(2));
    }

    public function testExponentialBackoffStopsRetryingOnceTheTotalBudgetIsSpent(): void
    {
        $policy = $this->makePolicy(5, 15, 3, 20);
        $retryable = $this->createMock(NetworkExceptionInterface::class);

        // The budget leaves 5 seconds for attempt 2 and nothing for attempt 3, which must therefore not be started.
        $this->assertTrue($policy->shouldRetry($retryable, 1));
        $this->assertFalse($policy->shouldRetry($retryable, 2));
    }

    public function testExponentialBackoffRaisesATooSmallBudgetToTheBaseTransferTimeout(): void
    {
        // The first attempt must always get the transfer timeout it was configured with.
        $policy = $this->makePolicy(5, 15, 3, 4);

        $this->assertSame(15, $policy->getMaxTotalTransferTimeout());
        $this->assertSame(15, $policy->getTransferTimeout(1));
        $this->assertSame(0, $policy->getTransferTimeout(2));
    }

    public function testExponentialBackoffLeavesEscalationUnboundedWhenTheBudgetIsDisabled(): void
    {
        $policy = $this->makePolicy(5, 15, 3, null);
        $retryable = $this->createMock(NetworkExceptionInterface::class);

        $this->assertNull($policy->getMaxTotalTransferTimeout());
        $this->assertSame(60, $policy->getTransferTimeout(3));
        $this->assertTrue($policy->shouldRetry($retryable, 2));
    }

    // -------------------------------------------------------------------------
    // RetryExecutor
    // -------------------------------------------------------------------------

    private function makeMockPolicy(int $maxAttempts = 3, bool $shouldRetry = true): RetryPolicyInterface
    {
        $policy = $this->createMock(RetryPolicyInterface::class);
        $policy->method('getMaxAttempts')->willReturn($maxAttempts);
        $policy->method('classify')->willReturn($shouldRetry ? Classification::retry() : Classification::fatal());
        $policy->method('isRetryable')->willReturn($shouldRetry);
        $policy->method('hasAttemptsLeft')->willReturnCallback(static fn (int $attempt): bool => $attempt < $maxAttempts);
        $policy->method('hasTimeBudgetLeft')->willReturn(true);
        $policy->method('delayFor')->willReturn(0);
        $policy->method('shouldRetry')->willReturn($shouldRetry);
        $policy->method('getConnectionTimeout')->willReturn(5);
        $policy->method('getTransferTimeout')->willReturn(15);

        return $policy;
    }

    /**
     * @throws Throwable
     */
    public function testExecuteSuccessOnFirstAttemptReturnsResult(): void
    {
        $this->assertSame('result-value', (new RetryExecutor($this->makeMockPolicy()))->execute(fn () => 'result-value'));
    }

    /**
     * @throws Throwable
     */
    public function testExecuteSuccessAfterOneRetryReturnsResult(): void
    {
        $calls = 0;
        $result = (new RetryExecutor($this->makeMockPolicy()))->execute(function () use (&$calls) {
            $calls++;

            if ($calls < 2) {
                throw new RuntimeException('transient');
            }

            return 'ok';
        });

        $this->assertSame('ok', $result);
        $this->assertSame(2, $calls);
    }

    /**
     * @throws Throwable
     */
    public function testExecuteNonRetryableErrorIsPropagatedImmediately(): void
    {
        $calls = 0;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('non-retryable');

        (new RetryExecutor($this->makeMockPolicy(3, false)))->execute(function () use (&$calls) {
            $calls++;

            throw new RuntimeException('non-retryable');
        });
    }

    /**
     * @throws Throwable
     */
    public function testExecuteNonRetryableErrorOnlyCallsOperationOnce(): void
    {
        $calls = 0;

        try {
            (new RetryExecutor($this->makeMockPolicy(3, false)))->execute(function () use (&$calls) {
                $calls++;

                throw new RuntimeException('stop');
            });
        } catch (RuntimeException) {
            // Expected
        }

        $this->assertSame(1, $calls);
    }

    /**
     * @throws Throwable
     */
    public function testExecuteAllAttemptsExhaustedThrowsRetryExhaustedException(): void
    {
        $this->expectException(RetryExhaustedException::class);

        (new RetryExecutor($this->makeMockPolicy()))->execute(fn () => throw new RuntimeException('always fails'));
    }

    /**
     * @throws Throwable
     */
    public function testExecuteRetryExhaustedExceptionCarriesAttemptCount(): void
    {
        $exception = null;

        try {
            (new RetryExecutor($this->makeMockPolicy()))->execute(fn () => throw new RuntimeException('fail'));
        } catch (RetryExhaustedException $e) {
            $exception = $e;
        }

        $this->assertNotNull($exception);
        $this->assertSame(3, $exception->getAttemptCount());
        $this->assertInstanceOf(RuntimeException::class, $exception->getOriginalError());
    }

    /**
     * @throws Throwable
     */
    public function testExecuteOnRetryCallbackInvokedForEachRetriableAttempt(): void
    {
        $retryAttempts = [];
        $onRetry = static function (int $attempt, Throwable $error) use (&$retryAttempts): void {
            $retryAttempts[] = $attempt;
        };

        try {
            (new RetryExecutor($this->makeMockPolicy()))
                ->execute(fn () => throw new RuntimeException('fail'), $onRetry);
        } catch (RetryExhaustedException) {
            // Expected after exhaustion
        }

        /* onRetry fires for attempts 1 and 2 only. Attempt 3 is the last the policy allows, so the executor leaves
           through RetryExhaustedException rather than announcing a retry that will never happen - which is exactly the
           distinction the old single-boolean policy could not draw. */
        $this->assertSame([1, 2], $retryAttempts);
    }

    public function testGetRetryPolicyReturnsInjectedPolicy(): void
    {
        $policy = $this->makeMockPolicy();

        $this->assertSame($policy, (new RetryExecutor($policy))->getRetryPolicy());
    }
}
