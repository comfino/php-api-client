<?php

/**
 * ComfinoPay PHP API client
 *
 * Backend routines for communication with the ComfinoPay payment gateway REST API.
 *
 * @package Comfino\Tests\Unit
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-api-client
 */

declare(strict_types=1);

namespace Comfino\Tests\Unit\Api\Response;

use Comfino\Api\Exception\Conflict;
use Comfino\Api\Exception\NotFound;
use Comfino\Api\Exception\RequestValidationError;
use Comfino\Api\Exception\ResponseValidationError;
use Comfino\Api\Exception\ServiceUnavailable;
use Comfino\Api\Exception\TooManyRequests;
use Comfino\Api\HttpErrorDetailsInterface;
use Comfino\Api\Request;
use Comfino\Api\Request\CancelOrder as CancelOrderRequest;
use Comfino\Api\Request\GetWidgetKey as GetWidgetKeyRequest;
use Comfino\Api\Response\Base;
use Comfino\Api\Response\CreateOrder;
use Comfino\Api\Response\GetWidgetKey;
use Comfino\Api\Retry\ErrorClassifier;
use Comfino\Api\Serializer\Json;
use JsonException;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

/**
 * Status-first classification of error responses, the diagnostic flags on the exceptions and the response-level URL check.
 */
final class ErrorResponseClassificationTest extends TestCase
{
    private const HTML_BODY = '<html><body>Bad gateway</body></html>';

    private Psr17Factory $factory;
    private Json $serializer;

    protected function setUp(): void
    {
        $this->factory = new Psr17Factory();
        $this->serializer = new Json();
    }

    public function testHtmlFiveHundredLabelledJsonIsServiceUnavailable(): void
    {
        try {
            new GetWidgetKey($this->request(new GetWidgetKeyRequest()), $this->response(503, self::HTML_BODY), $this->serializer);
            $this->fail('Expected ServiceUnavailable.');
        } catch (ServiceUnavailable $e) {
            $this->assertSame(503, $e->getCode());
            $this->assertTrue($e->hasNonNegotiatedBody());
            $this->assertStringContainsString('not JSON', $e->getResponseBody());
            $this->assertTrue((new ErrorClassifier())->isRetryable($e));
        }
    }

    public function testHtmlFourOhFourLabelledJsonIsNotFound(): void
    {
        $this->expectException(NotFound::class);

        new GetWidgetKey($this->request(new GetWidgetKeyRequest()), $this->response(404, self::HTML_BODY), $this->serializer);
    }

    public function testUndecodableBodyOnSuccessStaysAResponseValidationError(): void
    {
        $this->expectException(ResponseValidationError::class);

        new GetWidgetKey($this->request(new GetWidgetKeyRequest()), $this->response(200, self::HTML_BODY), $this->serializer);
    }

    public function testJsonValidationBodyKeepsItsRenderedMessage(): void
    {
        try {
            new GetWidgetKey(
                $this->request(new GetWidgetKeyRequest()),
                $this->response(400, '{"errors":{"cart":"too big"}}'),
                $this->serializer
            );
            $this->fail('Expected RequestValidationError.');
        } catch (RequestValidationError $e) {
            $this->assertSame('cart: too big', $e->getMessage());
            $this->assertFalse($e->hasNonNegotiatedBody());
        }
    }

    public function testJsonServiceUnavailableIsNotFlagged(): void
    {
        try {
            new GetWidgetKey($this->request(new GetWidgetKeyRequest()), $this->response(503, '{"message":"down"}'), $this->serializer);
            $this->fail('Expected ServiceUnavailable.');
        } catch (ServiceUnavailable $e) {
            $this->assertFalse($e->hasNonNegotiatedBody());
        }
    }

    public function testPlainTextBodyIsFlagged(): void
    {
        try {
            new GetWidgetKey(
                $this->request(new GetWidgetKeyRequest()),
                $this->factory->createResponse(400)
                    ->withHeader('Content-Type', 'text/plain')
                    ->withBody($this->factory->createStream('nope')),
                $this->serializer
            );
            $this->fail('Expected RequestValidationError.');
        } catch (RequestValidationError $e) {
            $this->assertTrue($e->hasNonNegotiatedBody());
        }
    }

    public function testEmptyBodyIsNotFlagged(): void
    {
        try {
            new GetWidgetKey(
                $this->request(new GetWidgetKeyRequest()),
                $this->factory->createResponse(502),
                $this->serializer
            );
            $this->fail('Expected ServiceUnavailable.');
        } catch (ServiceUnavailable $e) {
            $this->assertFalse($e->hasNonNegotiatedBody());
        }
    }

    public function testTooManyRequestsCarriesRetryAfterSeconds(): void
    {
        try {
            new GetWidgetKey(
                $this->request(new GetWidgetKeyRequest()),
                $this->response(429, '{}')->withHeader('Retry-After', '30'),
                $this->serializer
            );
            $this->fail('Expected TooManyRequests.');
        } catch (TooManyRequests $e) {
            $this->assertSame(30, $e->getRetryAfter());
            $this->assertSame(429, $e->getStatusCode());
            $this->assertTrue((new ErrorClassifier())->isRetryable($e));
        }
    }

    public function testTooManyRequestsRetryAfterAsHttpDateIsSecondsFromNow(): void
    {
        $date = gmdate('D, d M Y H:i:s \G\M\T', time() + 120);

        try {
            new GetWidgetKey(
                $this->request(new GetWidgetKeyRequest()),
                $this->response(429, '{}')->withHeader('Retry-After', $date),
                $this->serializer
            );
            $this->fail('Expected TooManyRequests.');
        } catch (TooManyRequests $e) {
            $this->assertEqualsWithDelta(120, $e->getRetryAfter(), 3);
        }
    }

    public function testTooManyRequestsWithoutHeaderHasNoRetryAfter(): void
    {
        try {
            new GetWidgetKey($this->request(new GetWidgetKeyRequest()), $this->response(429, '{}'), $this->serializer);
            $this->fail('Expected TooManyRequests.');
        } catch (TooManyRequests $e) {
            $this->assertNull($e->getRetryAfter());
        }
    }

    /** Pins the 4.0.0 hierarchy: a 429 is transient, so `catch (RequestValidationError)` must not catch it. */
    public function testTooManyRequestsIsNotARequestValidationError(): void
    {
        try {
            new GetWidgetKey($this->request(new GetWidgetKeyRequest()), $this->response(429, '{"message":"slow down"}'), $this->serializer);
            $this->fail('Expected TooManyRequests.');
        } catch (RequestValidationError) {
            $this->fail('A 429 must not be a RequestValidationError.');
        } catch (TooManyRequests $e) {
            $this->assertNotNull($e->getResponse());
            $this->assertSame(['message' => 'slow down'], $e->getDeserializedResponseBody());
        }
    }

    public function testCancelOrderDeclaresOnlyNotFoundAsIdempotent(): void
    {
        $request = $this->request(new CancelOrderRequest('order-1'));

        try {
            new Base($request, $this->response(404, '{}'), $this->serializer);
            $this->fail('Expected NotFound.');
        } catch (NotFound $e) {
            $this->assertTrue($e->isIdempotentFailure());
        }

        try {
            new Base($request, $this->response(409, '{}'), $this->serializer);
            $this->fail('Expected Conflict.');
        } catch (Conflict $e) {
            $this->assertFalse($e->isIdempotentFailure());
        }

        try {
            new Base($request, $this->response(400, '{}'), $this->serializer);
            $this->fail('Expected RequestValidationError.');
        } catch (RequestValidationError $e) {
            $this->assertFalse($e->isIdempotentFailure());
        }
    }

    public function testOtherRequestsNeverDeclareAnIdempotentFailure(): void
    {
        try {
            new GetWidgetKey($this->request(new GetWidgetKeyRequest()), $this->response(404, '{}'), $this->serializer);
            $this->fail('Expected NotFound.');
        } catch (NotFound $e) {
            $this->assertFalse($e->isIdempotentFailure());
        }
    }

    /**
     * @dataProvider fieldErrorProvider
     *
     * @param array<string, list<string>> $expected
     */
    public function testFieldErrorsAreNormalized(string $body, array $expected): void
    {
        try {
            new GetWidgetKey($this->request(new GetWidgetKeyRequest()), $this->response(400, $body), $this->serializer);
            $this->fail('Expected RequestValidationError.');
        } catch (RequestValidationError $e) {
            $this->assertSame($expected, $e->getFieldErrors());
        }
    }

    /**
     * @return array<string, array{string, array<string, list<string>>}>
     */
    public static function fieldErrorProvider(): array
    {
        return [
            'scalar per field' => ['{"errors":{"f":"m"}}', ['f' => ['m']]],
            'list per field' => ['{"errors":{"f":["a","b"]}}', ['f' => ['a', 'b']]],
            'nested lists are flattened' => ['{"errors":{"f":[["a"],["b","c"]]}}', ['f' => ['a', 'b', 'c']]],
            'bare list names no field' => ['{"errors":["a"]}', ['' => ['a']]],
            'no errors key' => ['{"message":"x"}', []],
        ];
    }

    /**
     * @throws JsonException
     */
    public function testCreateOrderAcceptsHttpsApplicationUrl(): void
    {
        $response = $this->createOrder('https://app.comfino.pl/apply/abc');

        $this->assertTrue($response->hasTrustedApplicationUrl());
    }

    /**
     * @throws JsonException
     */
    public function testCreateOrderAcceptsForeignHttpsHostButDoesNotTrustIt(): void
    {
        $response = $this->createOrder('https://lender.example.com/apply');

        $this->assertSame('https://lender.example.com/apply', $response->applicationUrl);
        $this->assertFalse($response->hasTrustedApplicationUrl());
    }

    /**
     * @dataProvider unsafeApplicationUrlProvider
     *
     * @throws JsonException
     */
    public function testCreateOrderRejectsUnsafeApplicationUrl(string $url): void
    {
        $this->expectException(ResponseValidationError::class);

        $this->createOrder($url);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unsafeApplicationUrlProvider(): array
    {
        return [
            'plain http' => ['http://app.comfino.pl/apply'],
            'javascript' => ['javascript:alert(1)'],
            'data' => ['data:text/html;base64,PHNjcmlwdD4='],
            'scheme-relative' => ['//evil.example.com/apply'],
            'relative path' => ['/apply/abc'],
            'empty' => [''],
        ];
    }

    /**
     * @throws JsonException
     */
    public function testCreateOrderAcceptsPlainHttpInDevelopmentEnvironment(): void
    {
        putenv('COMFINO_DEV_ENV=TRUE');

        try {
            $this->assertSame('http://shop.test/apply', $this->createOrder('http://shop.test/apply')->applicationUrl);
        } finally {
            putenv('COMFINO_DEV_ENV');
        }
    }

    private function request(Request $request): Request
    {
        $request->setSerializer($this->serializer)->getPsrRequest($this->factory, $this->factory, 'https://api.test', 1);

        return $request;
    }

    private function response(int $status, string $body): ResponseInterface
    {
        return $this->factory->createResponse($status)
            ->withHeader('Content-Type', 'application/json')
            ->withBody($this->factory->createStream($body));
    }

    /**
     * @throws JsonException
     */
    private function createOrder(string $applicationUrl): CreateOrder
    {
        $body = json_encode(['status' => 'CREATED', 'externalId' => 'o-1', 'applicationUrl' => $applicationUrl], JSON_THROW_ON_ERROR);

        return new CreateOrder($this->request(new GetWidgetKeyRequest()), $this->response(201, $body), $this->serializer);
    }
}
