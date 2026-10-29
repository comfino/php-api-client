<?php

/**
 * ComfinoPay PHP API client
 *
 * Backend routines for communication with the ComfinoPay payment gateway REST API.
 *
 * @package Comfino\Tests\Unit\Api\Response
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-api-client
 */

declare(strict_types=1);

namespace Comfino\Tests\Unit\Api\Response;

use Comfino\Api\Exception\AuthorizationError;
use Comfino\Api\Exception\Conflict;
use Comfino\Api\Exception\Forbidden;
use Comfino\Api\Exception\MethodNotAllowed;
use Comfino\Api\Exception\NotFound;
use Comfino\Api\Exception\RequestValidationError;
use Comfino\Api\Exception\ResponseValidationError;
use Comfino\Api\Exception\ServiceUnavailable;
use Comfino\Api\Request;
use Comfino\Api\Request\GetWidgetKey as GetWidgetKeyRequest;
use Comfino\Api\Response;
use Comfino\Api\Response\Base;
use Comfino\Api\Response\CreateOrder;
use Comfino\Api\Response\GetFinancialProducts;
use Comfino\Api\Response\GetProductTypes;
use Comfino\Api\Response\GetUserSettings;
use Comfino\Api\Response\GetWidgetKey;
use Comfino\Api\Response\GetWidgetTypes;
use Comfino\Api\Response\IsShopAccountActive;
use Comfino\Api\Serializer\Json;
use Comfino\Api\Serializer\MsgPack;
use Comfino\Enum\LoanType;
use Comfino\Enum\WidgetType;
use Comfino\Tests\Unit\Api\Stub\DeserializationGadget;
use Comfino\Tests\Unit\Api\Stub\HostileStream;
use Comfino\Tests\Unit\Api\Stub\TinyCapResponse;
use JsonException;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

final class ResponseParsingTest extends TestCase
{
    private Psr17Factory $factory;
    private Json $serializer;
    private Request $baseRequest;

    protected function setUp(): void
    {
        $this->factory = new Psr17Factory();
        $this->serializer = new Json();
        // Initialize the request URI so exception constructors receive a non-null string.
        $this->baseRequest = new GetWidgetKeyRequest();
        $this->baseRequest->setSerializer($this->serializer)
            ->getPsrRequest($this->factory, $this->factory, 'https://api.test', 1);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeJsonResponse(int $status, string $body, string $reasonPhrase = ''): ResponseInterface
    {
        return $this->factory->createResponse($status, $reasonPhrase)
            ->withHeader('Content-Type', 'application/json')
            ->withBody($this->factory->createStream($body));
    }

    /**
     * @throws JsonException
     */
    private function json(mixed $data): string
    {
        return json_encode($data, JSON_THROW_ON_ERROR);
    }

    /** One chunk of read granularity, the most the reader may overshoot the cap by. */
    private const READ_SLACK = 65536;

    private function responseWithStream(StreamInterface $stream): ResponseInterface
    {
        return $this->factory->createResponse(200)
            ->withHeader('Content-Type', 'application/json')
            ->withBody($stream);
    }

    private function makeResponse(int $status, string $contentType, string $body): ResponseInterface
    {
        return $this->factory->createResponse($status)
            ->withHeader('Content-Type', $contentType)
            ->withBody($this->factory->createStream($body));
    }

    // -------------------------------------------------------------------------
    // Response body size cap
    // -------------------------------------------------------------------------

    /**
     * `getContents()` reads whatever the far side sends, so a hostile or wedged host could answer a widget-key lookup
     * with a multi-gigabyte body and exhaust the shop's PHP memory limit - a checkout outage for the price of one
     * request. The stream here never ends; the reader must stop anyway.
     */
    public function testAnEndlessBodyIsRefusedInsteadOfBuffered(): void
    {
        $stream = new HostileStream();

        try {
            new GetWidgetKey($this->baseRequest, $this->responseWithStream($stream), $this->serializer);
            $this->fail('Expected ResponseValidationError was not thrown.');
        } catch (ResponseValidationError $e) {
            $this->assertStringContainsString('exceeds the', $e->getMessage());
        }

        $this->assertLessThanOrEqual(
            Response::MAX_RESPONSE_BODY_BYTES + self::READ_SLACK,
            $stream->bytesRead(),
            'The reader must stop at the cap, not drain the stream.'
        );
    }

    /**
     * A body that declares an impossible length is refused before a single byte is read - the cheapest possible
     * rejection, and the common case since a real flood arrives with a Content-Length.
     */
    public function testAnOversizedDeclaredLengthIsRefusedWithoutReading(): void
    {
        $stream = new HostileStream(Response::MAX_RESPONSE_BODY_BYTES + 1, failOnRead: true);

        $this->expectException(ResponseValidationError::class);

        new GetWidgetKey($this->baseRequest, $this->responseWithStream($stream), $this->serializer);
    }

    /**
     * The cap must not clip a legitimate response, so a body right at the limit still parses. Asserted against a
     * subclass with a small cap - which also pins the documented override - rather than allocating 8 MB to prove the
     * reader avoids allocating too much.
     */
    public function testABodyExactlyAtTheCapIsStillRead(): void
    {
        $cap = TinyCapResponse::MAX_RESPONSE_BODY_BYTES;
        $body = '"' . str_repeat('k', $cap - 2) . '"';

        $this->assertSame($cap, strlen($body));

        $response = new TinyCapResponse(
            $this->baseRequest,
            $this->makeResponse(200, 'application/json', $body),
            $this->serializer
        );

        $this->assertSame($cap - 2, strlen((string) $response->body));
    }

    /**
     * One byte over is refused, so the boundary is the cap itself and not "somewhere near it".
     */
    public function testABodyOneByteOverTheCapIsRefused(): void
    {
        $cap = TinyCapResponse::MAX_RESPONSE_BODY_BYTES;
        $body = '"' . str_repeat('k', $cap - 1) . '"';

        $this->assertSame($cap + 1, strlen($body));

        $this->expectException(ResponseValidationError::class);
        $this->expectExceptionMessage('exceeds the 1024 byte limit');

        new TinyCapResponse($this->baseRequest, $this->makeResponse(200, 'application/json', $body), $this->serializer);
    }

    /**
     * The refusal must not quote the body back: it is either unbounded or already known to be untrustworthy.
     */
    public function testTheRefusalCarriesNoPartOfTheBody(): void
    {
        try {
            new GetWidgetKey(
                $this->baseRequest,
                $this->responseWithStream(new HostileStream()),
                $this->serializer
            );
            $this->fail('Expected ResponseValidationError was not thrown.');
        } catch (ResponseValidationError $e) {
            $this->assertSame('', $e->getResponseBody());
            $this->assertStringNotContainsString('xxxx', $e->getMessage());
            $this->assertNotSame('', $e->getUrl(), 'The URL is still worth reporting.');
        }
    }

    /**
     * Some transports hand back a body that cannot be rewound. Reading one used to throw out of the response
     * constructor, turning an unbuffered response into a fatal rather than a parse.
     */
    public function testANonSeekableBodyIsReadRatherThanFatal(): void
    {
        $stream = new HostileStream(null, failOnRead: false, seekable: false);

        $this->expectException(ResponseValidationError::class);
        $this->expectExceptionMessage('exceeds the');

        new GetWidgetKey($this->baseRequest, $this->responseWithStream($stream), $this->serializer);
    }

    // -------------------------------------------------------------------------
    // Deserializer selection
    // -------------------------------------------------------------------------

    /**
     * The response body is parsed only by the serializer the client negotiated. Choosing one from the response's own
     * Content-Type handed the far side the choice of parser: a JSON client that had sent `Accept: application/json`
     * would still msgpack-decode a body labelled `application/msgpack`, and msgpack_unpack() constructs whatever class
     * the payload names and runs its magic methods - remote code execution reachable from a spoofed or MITM'd host.
     *
     * @requires extension msgpack
     */
    public function testJsonClientDoesNotDeserializeAMsgpackLabelledBody(): void
    {
        /* A msgpack body that would parse cleanly into the shape this response wants. The JSON client must refuse it
           anyway: what is under test is that the unnegotiated parser is never reached, not that the payload is bad. */
        $packed = msgpack_pack(['flags' => ['FLAG_ONE' => []]]);

        $this->expectException(ResponseValidationError::class);
        $this->expectExceptionMessage('Invalid response data type: array expected');

        new GetUserSettings($this->baseRequest, $this->makeResponse(200, 'application/msgpack', $packed), $this->serializer);
    }

    /**
     * The second layer, for a deployment that does negotiate msgpack: even then a payload naming a class must not
     * construct it. __wakeup() runs inside msgpack_unpack(), so the counter - not the returned value - is the proof.
     *
     * @requires extension msgpack
     */
    public function testNegotiatedMsgpackStillNeverConstructsObjectsFromTheBody(): void
    {
        $packed = msgpack_pack(['flags' => new DeserializationGadget()]);

        DeserializationGadget::reset();

        try {
            new GetUserSettings($this->baseRequest, $this->makeResponse(200, 'application/msgpack', $packed), new MsgPack());
        } catch (ResponseValidationError) {
            // Failing closed on an object-bearing payload is the expected outcome.
        }

        $this->assertSame(0, DeserializationGadget::$constructed, 'A msgpack payload constructed an object from the wire.');
    }

    /**
     * The negotiated type still parses, parameters and casing included, or every real response would be refused.
     */
    public function testConfiguredSerializerParsesItsOwnContentTypeWithParameters(): void
    {
        $response = new GetUserSettings(
            $this->baseRequest,
            $this->makeResponse(200, 'Application/JSON; charset=utf-8', $this->json(['flags' => ['FLAG_ONE' => []]])),
            $this->serializer
        );

        $this->assertTrue($response->hasFlag('FLAG_ONE'));
    }

    /**
     * A body labelled as something the client never asked for is left as a raw string, so the response class rejects it
     * rather than the client trusting an unnegotiated parser.
     */
    public function testUnnegotiatedContentTypeIsNotParsed(): void
    {
        $this->expectException(ResponseValidationError::class);

        new GetUserSettings(
            $this->baseRequest,
            $this->makeResponse(200, 'text/html', $this->json(['flags' => ['FLAG_ONE' => []]])),
            $this->serializer
        );
    }

    // -------------------------------------------------------------------------
    // HTTP error status → exception mapping
    // -------------------------------------------------------------------------

    public function testStatus400ThrowsRequestValidationError(): void
    {
        $this->expectException(RequestValidationError::class);

        new GetWidgetKey(
            $this->baseRequest,
            $this->makeJsonResponse(400, '{"message":"Bad input"}', 'Bad Request'),
            $this->serializer
        );
    }

    public function testStatus401ThrowsAuthorizationError(): void
    {
        $this->expectException(AuthorizationError::class);

        new GetWidgetKey(
            $this->baseRequest,
            $this->makeJsonResponse(401, '{"message":"Unauthorized"}', 'Unauthorized'),
            $this->serializer
        );
    }

    public function testStatus403ThrowsForbidden(): void
    {
        $this->expectException(Forbidden::class);

        new GetWidgetKey(
            $this->baseRequest,
            $this->makeJsonResponse(403, '{"message":"Forbidden"}', 'Forbidden'),
            $this->serializer
        );
    }

    public function testStatus404ThrowsNotFound(): void
    {
        $this->expectException(NotFound::class);

        new GetWidgetKey(
            $this->baseRequest,
            $this->makeJsonResponse(404, '{"message":"Not found"}', 'Not Found'),
            $this->serializer
        );
    }

    public function testStatus405ThrowsMethodNotAllowed(): void
    {
        $this->expectException(MethodNotAllowed::class);

        new GetWidgetKey(
            $this->baseRequest,
            $this->makeJsonResponse(405, '{"message":"Method not allowed"}', 'Method Not Allowed'),
            $this->serializer
        );
    }

    public function testStatus409ThrowsConflict(): void
    {
        $this->expectException(Conflict::class);

        new GetWidgetKey(
            $this->baseRequest,
            $this->makeJsonResponse(409, '{"message":"Conflict"}', 'Conflict'),
            $this->serializer
        );
    }

    public function testStatus500ThrowsServiceUnavailable(): void
    {
        $this->expectException(ServiceUnavailable::class);

        new GetWidgetKey(
            $this->baseRequest,
            $this->makeJsonResponse(500, '{"message":"Internal Server Error"}', 'Internal Server Error'),
            $this->serializer
        );
    }

    public function testStatus503ThrowsServiceUnavailable(): void
    {
        $this->expectException(ServiceUnavailable::class);

        new GetWidgetKey(
            $this->baseRequest,
            $this->makeJsonResponse(503, '{"message":"Service Unavailable"}', 'Service Unavailable'),
            $this->serializer
        );
    }

    /**
     * @throws JsonException
     */
    public function testErrorMessageIncludesValidationErrors(): void
    {
        $body = $this->json(['errors' => ['orderId' => 'is required', 'amount' => 'must be positive']]);

        $exception = null;

        try {
            new GetWidgetKey($this->baseRequest, $this->makeJsonResponse(400, $body, 'Bad Request'), $this->serializer);
        } catch (RequestValidationError $e) {
            $exception = $e;
        }

        $this->assertNotNull($exception);
        $this->assertStringContainsString('orderId', $exception->getMessage());
        $this->assertStringContainsString('amount', $exception->getMessage());
    }

    /**
     * The API decides the shape of an error body, so reading it must not depend on that shape.
     *
     * A per-field *list* of messages is the other common REST spelling of `errors`, and reading it through a closure
     * typed `string` raised an uncaught `TypeError` - not a `ResponseValidationError`, so neither this library nor a
     * plugin's error handling caught it, and a routine 400 became a fatal in checkout.
     *
     * @dataProvider awkwardErrorBodyProvider
     *
     * @throws JsonException
     */
    public function testErrorBodyOfAnyShapeStillYieldsAValidationError(mixed $errorBody, string $expectedFragment): void
    {
        $exception = null;

        try {
            new GetWidgetKey(
                $this->baseRequest,
                $this->makeJsonResponse(400, $this->json($errorBody), 'Bad Request'),
                $this->serializer
            );
        } catch (RequestValidationError $e) {
            $exception = $e;
        }

        $this->assertNotNull($exception, 'A 400 must always surface as RequestValidationError.');
        $this->assertStringContainsString($expectedFragment, $exception->getMessage());
        $this->assertStringNotContainsString('Array', $exception->getMessage(), 'An array was interpolated verbatim.');
    }

    /** @return array<string, array{mixed, string}> */
    public static function awkwardErrorBodyProvider(): array
    {
        return [
            'per-field list of messages' => [
                ['errors' => ['cart' => ['too big', 'also bad']]],
                'cart: too big, also bad',
            ],
            'nested list' => [
                ['errors' => ['cart' => [['deeply', 'nested']]]],
                'cart: deeply, nested',
            ],
            'numeric value' => [
                ['errors' => ['amount' => 1000]],
                'amount: 1000',
            ],
            'boolean value' => [
                ['errors' => ['simulation' => false]],
                'simulation: false',
            ],
            'null value' => [
                ['errors' => ['term' => null]],
                'term: NULL',
            ],
            'errors is a bare string' => [
                ['errors' => 'everything is wrong'],
                'everything is wrong',
            ],
            'message is a list' => [
                ['message' => ['first', 'second']],
                'first, second',
            ],
            'top-level field with a list value' => [
                ['cart' => ['too big', 'also bad']],
                'cart: too big, also bad',
            ],
        ];
    }

    // -------------------------------------------------------------------------
    // IsShopAccountActive
    // -------------------------------------------------------------------------

    public function testIsShopAccountActiveParsesTrue(): void
    {
        $response = new IsShopAccountActive(
            $this->baseRequest,
            $this->makeJsonResponse(200, 'true'),
            $this->serializer
        );

        $this->assertTrue($response->isActive);
    }

    public function testIsShopAccountActiveParsesFalse(): void
    {
        $response = new IsShopAccountActive(
            $this->baseRequest,
            $this->makeJsonResponse(200, 'false'),
            $this->serializer
        );

        $this->assertFalse($response->isActive);
    }

    public function testIsShopAccountActiveWrongTypeThrowsResponseValidationError(): void
    {
        $this->expectException(ResponseValidationError::class);

        new IsShopAccountActive(
            $this->baseRequest,
            $this->makeJsonResponse(200, '"yes"'),   // string instead of boolean
            $this->serializer
        );
    }

    // -------------------------------------------------------------------------
    // GetWidgetKey
    // -------------------------------------------------------------------------

    public function testGetWidgetKeyParsesStringBody(): void
    {
        $response = new GetWidgetKey(
            $this->baseRequest,
            $this->makeJsonResponse(200, '"widget-key-abc123"'),
            $this->serializer
        );

        $this->assertSame('widget-key-abc123', $response->widgetKey);
    }

    public function testGetWidgetKeyWrongTypeThrowsResponseValidationError(): void
    {
        $this->expectException(ResponseValidationError::class);

        new GetWidgetKey(
            $this->baseRequest,
            $this->makeJsonResponse(200, '{"key":"value"}'),  // array instead of string
            $this->serializer
        );
    }

    // -------------------------------------------------------------------------
    // GetProductTypes
    // -------------------------------------------------------------------------

    /**
     * @throws JsonException
     */
    public function testGetProductTypesParsesKnownEnums(): void
    {
        $body = $this->json([
            'INSTALLMENTS_ZERO_PERCENT' => ['Raty zero procent', 'Raty 0%'],
            'PAY_LATER' => ['Zapłać później', 'Kup teraz, zapłać później'],
        ]);

        $response = new GetProductTypes($this->baseRequest, $this->makeJsonResponse(200, $body), $this->serializer);

        $this->assertCount(2, $response->productTypes);
        $this->assertSame(LoanType::INSTALLMENTS_ZERO_PERCENT, $response->productTypes[0]);
        $this->assertSame(LoanType::PAY_LATER, $response->productTypes[1]);
        $this->assertSame('Raty zero procent', $response->productTypesWithNames['INSTALLMENTS_ZERO_PERCENT']);
        $this->assertSame('Raty 0%', $response->productTypesWithPublicNames['INSTALLMENTS_ZERO_PERCENT']);
    }

    /**
     * @throws JsonException
     */
    public function testGetProductTypesHandlesUnknownTypeWithFlyweight(): void
    {
        $body = $this->json(['FUTURE_PRODUCT' => ['Future', 'Future']]);

        $response = new GetProductTypes($this->baseRequest, $this->makeJsonResponse(200, $body), $this->serializer);

        $this->assertCount(1, $response->productTypes);
        $this->assertFalse($response->productTypes[0]->isKnown());
        $this->assertSame('FUTURE_PRODUCT', $response->productTypes[0]->getValue());
    }

    // -------------------------------------------------------------------------
    // GetWidgetTypes
    // -------------------------------------------------------------------------

    /**
     * @throws JsonException
     */
    public function testGetWidgetTypesParsesKnownEnum(): void
    {
        $body = $this->json(['standard' => 'Standard', 'classic' => 'Classic']);

        $response = new GetWidgetTypes($this->baseRequest, $this->makeJsonResponse(200, $body), $this->serializer);

        $this->assertCount(2, $response->widgetTypes);
        $this->assertSame(WidgetType::STANDARD, $response->widgetTypes[0]);
        $this->assertSame(WidgetType::CLASSIC, $response->widgetTypes[1]);
    }

    /**
     * @throws JsonException
     */
    public function testGetWidgetTypesHandlesUnknownType(): void
    {
        $body = $this->json(['future_widget' => 'Future Widget']);

        $response = new GetWidgetTypes($this->baseRequest, $this->makeJsonResponse(200, $body), $this->serializer);

        $this->assertFalse($response->widgetTypes[0]->isKnown());
        $this->assertSame('future_widget', $response->widgetTypes[0]->getValue());
    }

    // -------------------------------------------------------------------------
    // GetUserSettings
    // -------------------------------------------------------------------------

    /**
     * @throws JsonException
     */
    public function testGetUserSettingsParsesFlagsKeyedByName(): void
    {
        $body = $this->json(['flags' => ['FLAG_ONE' => [], 'FLAG_TWO' => ['maxAmount' => 50000]]]);

        $response = new GetUserSettings($this->baseRequest, $this->makeJsonResponse(200, $body), $this->serializer);

        $this->assertSame(['FLAG_ONE' => [], 'FLAG_TWO' => ['maxAmount' => 50000]], $response->flags);
        $this->assertTrue($response->hasFlag('FLAG_TWO'));
        $this->assertFalse($response->hasFlag('FLAG_THREE'));
        $this->assertSame(['maxAmount' => 50000], $response->getFlagAttributes('FLAG_TWO'));
    }

    /**
     * Flag values of any JSON type pass through untouched, so a changed flag shape never drops the other flags.
     *
     * @throws JsonException
     */
    public function testGetUserSettingsAcceptsFlagValuesOfAnyType(): void
    {
        $flags = ['FLAG_ONE' => true, 'FLAG_TWO' => 'text', 'FLAG_THREE' => ['promo' => ['amount' => 5]]];

        $response = new GetUserSettings($this->baseRequest, $this->makeJsonResponse(200, $this->json(['flags' => $flags])), $this->serializer);

        $this->assertSame($flags, $response->flags);
        $this->assertTrue($response->hasFlag('FLAG_ONE'));
        $this->assertSame([], $response->getFlagAttributes('FLAG_ONE'));
        $this->assertSame(['promo' => ['amount' => 5]], $response->getFlagAttributes('FLAG_THREE'));
    }

    // -------------------------------------------------------------------------
    // CreateOrder
    // -------------------------------------------------------------------------

    /**
     * @throws JsonException
     */
    public function testCreateOrderParsesAllFields(): void
    {
        $body = $this->json([
            'status' => 'WAITING_FOR_PAYMENT',
            'externalId' => 'order-123',
            'applicationUrl' => 'https://app.comfino.pl/apply/abc',
        ]);

        $response = new CreateOrder($this->baseRequest, $this->makeJsonResponse(201, $body), $this->serializer);

        $this->assertSame('WAITING_FOR_PAYMENT', $response->status);
        $this->assertSame('order-123', $response->externalId);
        $this->assertSame('https://app.comfino.pl/apply/abc', $response->applicationUrl);
    }

    /**
     * @throws JsonException
     */
    public function testCreateOrderMissingFieldThrowsResponseValidationError(): void
    {
        $this->expectException(ResponseValidationError::class);

        $body = $this->json(['status' => 'CREATED', 'externalId' => 'order-1']); // missing applicationUrl

        new CreateOrder($this->baseRequest, $this->makeJsonResponse(201, $body), $this->serializer);
    }

    // -------------------------------------------------------------------------
    // GetFinancialProducts
    // -------------------------------------------------------------------------

    /**
     * @throws JsonException
     */
    public function testGetFinancialProductsParsesProducts(): void
    {
        $body = $this->json([[
            'name' => 'Raty 0%',
            'type' => 'INSTALLMENTS_ZERO_PERCENT',
            'creditorName' => 'Test Bank',
            'description' => '',
            'icon' => 'https://example.com/icon.png',
            'instalmentAmount' => 5000,
            'toPay' => 150000,
            'loanTerm' => 30,
            'rrso' => 0.0,
            'loanParameters' => [[
                'instalmentAmount' => 5000,
                'toPay' => 150000,
                'loanTerm' => 30,
                'rrso' => 0.0,
            ]],
        ]]);

        $response = new GetFinancialProducts(
            $this->baseRequest,
            $this->makeJsonResponse(200, $body),
            $this->serializer
        );

        $this->assertCount(1, $response->financialProducts);

        $product = $response->financialProducts[0];

        $this->assertSame('Raty 0%', $product->name);
        $this->assertSame(LoanType::INSTALLMENTS_ZERO_PERCENT, $product->type);
        $this->assertSame(5000, $product->instalmentAmount);
        $this->assertSame(150000, $product->toPay);
        $this->assertSame(30, $product->loanTerm);
        $this->assertCount(1, $product->loanParameters);
    }

    public function testGetFinancialProductsHandlesEmptyList(): void
    {
        $response = new GetFinancialProducts(
            $this->baseRequest,
            $this->makeJsonResponse(200, '[]'),
            $this->serializer
        );

        $this->assertCount(0, $response->financialProducts);
    }

    /**
     * @throws JsonException
     */
    public function testGetFinancialProductsHandlesUnknownLoanType(): void
    {
        $body = $this->json([[
            'name' => 'Future',
            'type' => 'FUTURE_TYPE',
            'creditorName' => 'Bank',
            'description' => '',
            'icon' => '',
            'instalmentAmount' => 1000,
            'toPay' => 10000,
            'loanTerm' => 10,
            'rrso' => 0.0,
            'loanParameters' => [],
        ]]);

        $response = new GetFinancialProducts(
            $this->baseRequest,
            $this->makeJsonResponse(200, $body),
            $this->serializer
        );

        $this->assertFalse($response->financialProducts[0]->type->isKnown());
        $this->assertSame('FUTURE_TYPE', $response->financialProducts[0]->type->getValue());
    }

    /**
     * @throws JsonException
     */
    public function testGetFinancialProductsMissingRequiredFieldThrowsResponseValidationError(): void
    {
        $this->expectException(ResponseValidationError::class);

        $body = $this->json([[
            'name' => 'Product',
            'type' => 'PAY_LATER',
            // missing: icon, instalmentAmount, toPay, loanTerm, loanParameters
        ]]);

        new GetFinancialProducts($this->baseRequest, $this->makeJsonResponse(200, $body), $this->serializer);
    }

    // -------------------------------------------------------------------------
    // Non-JSON response body (no Content-Type header)
    // -------------------------------------------------------------------------

    public function testNonJsonContentTypeSkipsDeserialization(): void
    {
        // A response without Content-Type: application/json should not attempt JSON parsing.
        $psrResponse = $this->factory->createResponse(200)
            ->withBody($this->factory->createStream(''));

        // Base response with no processResponseBody content - should not throw.
        $response = new Base(
            $this->baseRequest,
            $psrResponse,
            $this->serializer
        );

        $this->assertEmpty($response->getHeaders());
    }

    // -------------------------------------------------------------------------
    // Response headers extraction
    // -------------------------------------------------------------------------

    public function testGetHeadersCapturesResponseHeaders(): void
    {
        $psrResponse = $this->factory->createResponse(200)
            ->withHeader('X-Custom', 'test-value')
            ->withHeader('Content-Type', 'application/json')
            ->withBody($this->factory->createStream('true'));

        $response = new IsShopAccountActive($this->baseRequest, $psrResponse, $this->serializer);

        $this->assertTrue($response->hasHeader('X-Custom'));
        $this->assertSame('test-value', $response->getHeader('X-Custom'));
    }

    public function testGetHeaderIsCaseInsensitive(): void
    {
        $psrResponse = $this->factory->createResponse(200)
            ->withHeader('X-Custom-Header', 'hello')
            ->withHeader('Content-Type', 'application/json')
            ->withBody($this->factory->createStream('true'));

        $response = new IsShopAccountActive($this->baseRequest, $psrResponse, $this->serializer);

        $this->assertSame('hello', $response->getHeader('x-custom-header'));
        $this->assertSame('hello', $response->getHeader('X-CUSTOM-HEADER'));
    }

    public function testGetHeaderReturnsDefaultWhenMissing(): void
    {
        $psrResponse = $this->factory->createResponse(200)
            ->withHeader('Content-Type', 'application/json')
            ->withBody($this->factory->createStream('true'));

        $response = new IsShopAccountActive($this->baseRequest, $psrResponse, $this->serializer);

        $this->assertNull($response->getHeader('X-Missing'));
        $this->assertSame('default', $response->getHeader('X-Missing', 'default'));
    }
}
