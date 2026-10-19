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

use Comfino\Api\Exception\AuthorizationError;
use Comfino\Api\Exception\ConnectionTimeout;
use Comfino\Api\Exception\ServiceUnavailable;
use Comfino\Api\Retry\ErrorClassifier;
use Comfino\Api\Retry\RetryVerdict;
use Comfino\Api\Support\FrozenClock;
use Comfino\Tests\Unit\Api\Stub\FakeNetworkException;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use RuntimeException;

final class ErrorClassifierTest extends TestCase
{
    private Psr17Factory $psr17Factory;

    protected function setUp(): void
    {
        $this->psr17Factory = new Psr17Factory();
    }

    // -------------------------------------------------------------------------
    // Transport-level failures
    // -------------------------------------------------------------------------

    public function testNetworkExceptionIsRetryable(): void
    {
        $this->assertTrue((new ErrorClassifier())->isRetryable(new FakeNetworkException()));
    }

    public function testCurlTimeoutCodedClientExceptionIsRetryable(): void
    {
        $exception = new class ('timeout', 28) extends RuntimeException implements ClientExceptionInterface {
        };

        $this->assertTrue((new ErrorClassifier())->isRetryable($exception));
    }

    public function testConnectionTimeoutIsRetryable(): void
    {
        $this->assertTrue((new ErrorClassifier())->isRetryable(new ConnectionTimeout('timed out')));
    }

    public function testPlainRuntimeExceptionIsFatal(): void
    {
        $this->assertSame(RetryVerdict::Fatal, (new ErrorClassifier())->classify(new RuntimeException('boom'))->verdict);
    }

    public function testNonThrowableIsFatal(): void
    {
        $this->assertSame(RetryVerdict::Fatal, (new ErrorClassifier())->classify('not an error')->verdict);
    }

    // -------------------------------------------------------------------------
    // HTTP statuses - the set the queue-side classifier always treated as transient and the inline path did not
    // -------------------------------------------------------------------------

    /**
     * @dataProvider retryableStatusProvider
     */
    public function testTransientStatusIsRetryable(int $statusCode): void
    {
        $this->assertTrue((new ErrorClassifier())->classifyResponse($this->psr17Factory->createResponse($statusCode))->isRetryable());
    }

    /**
     * @return array<string, array{int}>
     */
    public static function retryableStatusProvider(): array
    {
        return [
            '429 Too Many Requests' => [429],
            '502 Bad Gateway' => [502],
            '503 Service Unavailable' => [503],
            '504 Gateway Timeout' => [504],
        ];
    }

    public function testServiceUnavailableExceptionIsRetryable(): void
    {
        $this->assertTrue((new ErrorClassifier())->isRetryable(new ServiceUnavailable('down')));
    }

    public function testAuthorizationErrorIsFatal(): void
    {
        /* A 401 is a configuration problem, not a transient one. Retrying it only multiplies the audit-log noise, and
           feeding it to a circuit breaker would let one merchant's wrong key block every healthy tenant. */
        $this->assertFalse((new ErrorClassifier())->isRetryable(new AuthorizationError('bad key')));
    }

    public function testServerErrorIsRetryableOnlyForAnIdempotentRequest(): void
    {
        $classifier = new ErrorClassifier();
        $response = $this->psr17Factory->createResponse(500);

        $this->assertTrue($classifier->classifyResponse($response, true)->isRetryable());
        $this->assertFalse($classifier->classifyResponse($response, false)->isRetryable());
    }

    public function testNotFoundIsFatal(): void
    {
        $this->assertFalse((new ErrorClassifier())->classifyResponse($this->psr17Factory->createResponse(404))->isRetryable());
    }

    public function testSuccessfulResponseClassifiesAsSuccess(): void
    {
        $this->assertSame(RetryVerdict::Success, (new ErrorClassifier())->classifyResponse($this->psr17Factory->createResponse(200))->verdict);
    }

    // -------------------------------------------------------------------------
    // Retry-After
    // -------------------------------------------------------------------------

    public function testRetryAfterDeltaSecondsIsHonored(): void
    {
        $classification = (new ErrorClassifier())->classifyResponse($this->psr17Factory->createResponse(429)->withHeader('Retry-After', '2'));

        $this->assertSame(RetryVerdict::RetryAfter, $classification->verdict);
        $this->assertSame(2000, $classification->retryAfterMs);
    }

    public function testRetryAfterHttpDateIsHonored(): void
    {
        $clock = new FrozenClock(1000000000.0);
        $classifier = new ErrorClassifier(ErrorClassifier::DEFAULT_MAX_RETRY_AFTER_MS, $clock);
        $httpDate = gmdate('D, d M Y H:i:s \G\M\T', 1000000005);

        $classification = $classifier->classifyResponse($this->psr17Factory->createResponse(503)->withHeader('Retry-After', $httpDate));

        $this->assertSame(5000, $classification->retryAfterMs);
    }

    public function testRetryAfterIsClampedToTheCeiling(): void
    {
        // A hostile or simply wrong header must not be able to park a worker for an hour.
        $classification = (new ErrorClassifier(5000))->classifyResponse($this->psr17Factory->createResponse(429)->withHeader('Retry-After', '3600'));

        $this->assertSame(5000, $classification->retryAfterMs);
    }

    public function testRetryAfterInThePastClampsToZero(): void
    {
        $clock = new FrozenClock(1000000000.0);
        $classifier = new ErrorClassifier(ErrorClassifier::DEFAULT_MAX_RETRY_AFTER_MS, $clock);
        $httpDate = gmdate('D, d M Y H:i:s \G\M\T', 999999000);

        $classification = $classifier->classifyResponse($this->psr17Factory->createResponse(503)->withHeader('Retry-After', $httpDate));

        $this->assertSame(0, $classification->retryAfterMs);
    }

    public function testUnparseableRetryAfterFallsBackToTheBackoffDelay(): void
    {
        $classification = (new ErrorClassifier())->classifyResponse($this->psr17Factory->createResponse(429)->withHeader('Retry-After', 'soon-ish'));

        $this->assertSame(RetryVerdict::Retry, $classification->verdict);
        $this->assertNull($classification->retryAfterMs);
    }
}
