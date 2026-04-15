<?php

/**
 * Comfino PHP API client
 *
 * Backend routines for communication with the Comfino payment gateway REST API.
 *
 * @package Comfino\Tests\Unit\Api\Response
 * @author Artur Kozubski <akozubski@comperia.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
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
use Comfino\Api\Response\Base;
use Comfino\Api\Response\CreateOrder;
use Comfino\Api\Response\GetFinancialProducts;
use Comfino\Api\Response\GetOrder;
use Comfino\Api\Response\GetProductTypes;
use Comfino\Api\Response\GetWidgetKey;
use Comfino\Api\Response\GetWidgetTypes;
use Comfino\Api\Response\IsShopAccountActive;
use Comfino\Api\Serializer\Json;
use Comfino\Enum\LoanType;
use Comfino\Enum\WidgetType;
use DateTime;
use JsonException;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

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
            'INSTALLMENTS_ZERO_PERCENT' => 'Raty 0%',
            'PAY_LATER' => 'Kup teraz, zapłać później',
        ]);

        $response = new GetProductTypes($this->baseRequest, $this->makeJsonResponse(200, $body), $this->serializer);

        $this->assertCount(2, $response->productTypes);
        $this->assertSame(LoanType::INSTALLMENTS_ZERO_PERCENT, $response->productTypes[0]);
        $this->assertSame(LoanType::PAY_LATER, $response->productTypes[1]);
        $this->assertSame('Raty 0%', $response->productTypesWithNames['INSTALLMENTS_ZERO_PERCENT']);
    }

    /**
     * @throws JsonException
     */
    public function testGetProductTypesHandlesUnknownTypeWithFlyweight(): void
    {
        $body = $this->json(['FUTURE_PRODUCT' => 'Future']);

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
    // GetOrder
    // -------------------------------------------------------------------------

    /**
     * @param array<string, string>|null $address
     *
     * @throws JsonException
     */
    private function makeGetOrderBody(?array $address = null): string
    {
        return $this->json([
            'orderId' => 'order-abc',
            'status' => 'WAITING_FOR_PAYMENT',
            'createdAt' => '2026-01-15T10:30:00+00:00',
            'applicationUrl' => 'https://app.comfino.pl/apply/xyz',
            'notifyUrl' => 'https://shop.test/notify',
            'returnUrl' => 'https://shop.test/return',
            'loanParameters' => [
                'amount' => 150000,
                'maxAmount' => 200000,
                'term' => 30,
                'type' => 'INSTALLMENTS_ZERO_PERCENT',
                'allowedProductTypes' => null,
            ],
            'cart' => [
                'totalAmount' => 150000,
                'deliveryCost' => 0,
                'category' => null,
                'products' => [[
                    'name' => 'Test product',
                    'price' => 150000,
                    'quantity' => 1,
                    'externalId' => 'PROD-1',
                    'photoUrl' => '',
                    'ean' => '',
                    'category' => '',
                ]],
            ],
            'customer' => [
                'firstName' => 'Jan',
                'lastName' => 'Kowalski',
                'email' => 'jan@example.com',
                'phoneNumber' => '500000000',
                'ip' => '127.0.0.1',
                'taxId' => null,
                'regular' => false,
                'logged' => false,
                'address' => $address,
            ],
        ]);
    }

    /**
     * @throws JsonException
     */
    public function testGetOrderParsesResponseWithoutAddress(): void
    {
        $response = new GetOrder(
            $this->baseRequest,
            $this->makeJsonResponse(200, $this->makeGetOrderBody()),
            $this->serializer
        );

        $this->assertSame('order-abc', $response->orderId);
        $this->assertSame('WAITING_FOR_PAYMENT', $response->status);
        $this->assertInstanceOf(DateTime::class, $response->createdAt);
        $this->assertSame(LoanType::INSTALLMENTS_ZERO_PERCENT, $response->loanParameters->type);
        $this->assertSame(150000, $response->loanParameters->amount);
        $this->assertCount(1, $response->cart->products);
        $this->assertSame('Jan', $response->customer->firstName);
        $this->assertNull($response->customer->address);
    }

    /**
     * @throws JsonException
     */
    public function testGetOrderParsesResponseWithAddress(): void
    {
        $address = [
            'street' => 'Main St',
            'buildingNumber' => '10',
            'apartmentNumber' => '2A',
            'postalCode' => '00-001',
            'city' => 'Warsaw',
            'countryCode' => 'PL',
        ];

        $response = new GetOrder(
            $this->baseRequest,
            $this->makeJsonResponse(200, $this->makeGetOrderBody($address)),
            $this->serializer
        );

        $this->assertNotNull($response->customer->address);
        $this->assertSame('Main St', $response->customer->address->street);
        $this->assertSame('Warsaw', $response->customer->address->city);
        $this->assertSame('PL', $response->customer->address->countryCode);
    }

    /**
     * @throws JsonException
     */
    public function testGetOrderMissingTopLevelFieldThrowsResponseValidationError(): void
    {
        $this->expectException(ResponseValidationError::class);

        $body = $this->json([
            'orderId' => 'order-abc',
            'status' => 'CREATED',
            // missing: createdAt, applicationUrl, etc.
        ]);

        new GetOrder($this->baseRequest, $this->makeJsonResponse(200, $body), $this->serializer);
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
