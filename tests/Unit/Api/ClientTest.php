<?php

/**
 * Comfino PHP API client
 *
 * Backend routines for communication with the Comfino payment gateway REST API.
 *
 * @package Comfino\Tests\Unit\Api
 * @author Artur Kozubski <akozubski@comperia.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-api-client
 */

declare(strict_types=1);

namespace Comfino\Tests\Unit\Api;

use Comfino\Api\AbstractClient;
use Comfino\Api\Client;
use Comfino\Api\Dto\Payment\LoanQueryCriteria;
use Comfino\Api\Dto\Plugin\ShopPluginError;
use Comfino\Api\Exception\AuthorizationError;
use Comfino\Api\Exception\Forbidden;
use Comfino\Api\Exception\RequestValidationError;
use Comfino\Api\Exception\ServiceUnavailable;
use Comfino\Api\Serializer\Json as JsonSerializer;
use Comfino\Enum\LoanType;
use Comfino\Enum\LoanTypeInterface;
use Comfino\Enum\ProductListType;
use Comfino\Enum\WidgetTypeInterface;
use Comfino\Shop\Order\Cart\CartItemInterface;
use Comfino\Shop\Order\Cart\ProductInterface;
use Comfino\Shop\Order\CartInterface;
use Comfino\Shop\Order\CustomerInterface;
use Comfino\Shop\Order\LoanParametersInterface;
use Comfino\Shop\Order\OrderInterface;
use Http\Mock\Client as MockHttpClient;
use InvalidArgumentException;
use JsonException;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class ClientTest extends TestCase
{
    private const API_KEY = 'test-api-key-unit';
    private const SANDBOX_BASE_URL = 'https://api-ecommerce.craty.pl';

    private MockHttpClient $mockHttpClient;
    private Psr17Factory $psr17Factory;
    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->psr17Factory = new Psr17Factory();
        $this->mockHttpClient = new MockHttpClient($this->psr17Factory);

        $this->client = new Client(
            $this->mockHttpClient,
            $this->psr17Factory,
            $this->psr17Factory,
            self::API_KEY
        );

        $this->client->enableSandboxMode();
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function createJsonResponse(int $statusCode, string $body, string $reasonPhrase = ''): ResponseInterface
    {
        $response = $this->psr17Factory->createResponse($statusCode, $reasonPhrase)
            ->withHeader('Content-Type', 'application/json');

        return $response->withBody($this->psr17Factory->createStream($body));
    }

    private function getLastRequest(): RequestInterface
    {
        return $this->mockHttpClient->getLastRequest();
    }

    private function createMockOrder(
        string $orderId = 'order-123',
        int $loanAmount = 150000,
        ?LoanTypeInterface $loanType = null,
        int $cartTotal = 150000,
        ?int $deliveryCost = 0
    ): OrderInterface {
        $product = $this->createMock(ProductInterface::class);
        $product->method('getName')->willReturn('Test product');
        $product->method('getPrice')->willReturn($cartTotal);
        $product->method('getId')->willReturn('PROD-1');
        $product->method('getPhotoUrl')->willReturn(null);
        $product->method('getEan')->willReturn(null);
        $product->method('getCategory')->willReturn(null);
        $product->method('getNetPrice')->willReturn(null);
        $product->method('getTaxRate')->willReturn(null);
        $product->method('getTaxValue')->willReturn(null);
        $product->method('getCategoryIds')->willReturn(null);

        $cartItem = $this->createMock(CartItemInterface::class);
        $cartItem->method('getProduct')->willReturn($product);
        $cartItem->method('getQuantity')->willReturn(1);

        $cart = $this->createMock(CartInterface::class);
        $cart->method('getItems')->willReturn([$cartItem]);
        $cart->method('getTotalAmount')->willReturn($cartTotal);
        $cart->method('getDeliveryCost')->willReturn($deliveryCost);
        $cart->method('getDeliveryNetCost')->willReturn(null);
        $cart->method('getDeliveryCostTaxRate')->willReturn(null);
        $cart->method('getDeliveryCostTaxValue')->willReturn(null);
        $cart->method('getCategory')->willReturn(null);

        $loanParams = $this->createMock(LoanParametersInterface::class);
        $loanParams->method('getAmount')->willReturn($loanAmount);
        $loanParams->method('getTerm')->willReturn(null);
        $loanParams->method('getType')->willReturn($loanType?->getValue());
        $loanParams->method('getAllowedProductTypes')->willReturn(null);

        $customer = $this->createMock(CustomerInterface::class);
        $customer->method('getFirstName')->willReturn('Jan');
        $customer->method('getLastName')->willReturn('Kowalski');
        $customer->method('getEmail')->willReturn('jan.kowalski@example.com');
        $customer->method('getPhoneNumber')->willReturn('500000000');
        $customer->method('getTaxId')->willReturn(null);
        $customer->method('getIp')->willReturn('127.0.0.1');
        $customer->method('isRegular')->willReturn(false);
        $customer->method('isLogged')->willReturn(false);
        $customer->method('getAddress')->willReturn(null);

        $order = $this->createMock(OrderInterface::class);
        $order->method('getId')->willReturn($orderId);
        $order->method('getNotifyUrl')->willReturn('https://example-shop.test/notify');
        $order->method('getReturnUrl')->willReturn('https://example-shop.test/return');
        $order->method('getLoanParameters')->willReturn($loanParams);
        $order->method('getCart')->willReturn($cart);
        $order->method('getCustomer')->willReturn($customer);
        $order->method('getSeller')->willReturn(null);
        $order->method('getAccountNumber')->willReturn(null);
        $order->method('getTransferTitle')->willReturn(null);

        return $order;
    }

    private function createMockCart(int $totalAmount = 100000, int $deliveryCost = 0): CartInterface
    {
        $product = $this->createMock(ProductInterface::class);
        $product->method('getName')->willReturn('Cart product');
        $product->method('getPrice')->willReturn($totalAmount - $deliveryCost);
        $product->method('getId')->willReturn('PROD-X');
        $product->method('getPhotoUrl')->willReturn(null);
        $product->method('getEan')->willReturn(null);
        $product->method('getCategory')->willReturn(null);
        $product->method('getNetPrice')->willReturn(null);
        $product->method('getTaxRate')->willReturn(null);
        $product->method('getTaxValue')->willReturn(null);
        $product->method('getCategoryIds')->willReturn(null);

        $cartItem = $this->createMock(CartItemInterface::class);
        $cartItem->method('getProduct')->willReturn($product);
        $cartItem->method('getQuantity')->willReturn(1);

        $cart = $this->createMock(CartInterface::class);
        $cart->method('getItems')->willReturn([$cartItem]);
        $cart->method('getTotalAmount')->willReturn($totalAmount);
        $cart->method('getDeliveryCost')->willReturn($deliveryCost);
        $cart->method('getDeliveryNetCost')->willReturn(null);
        $cart->method('getDeliveryCostTaxRate')->willReturn(null);
        $cart->method('getDeliveryCostTaxValue')->willReturn(null);
        $cart->method('getCategory')->willReturn(null);

        return $cart;
    }

    // -------------------------------------------------------------------------
    // isShopAccountActive
    // -------------------------------------------------------------------------

    /**
     * @throws ClientExceptionInterface
     */
    public function testIsShopAccountActiveReturnsTrue(): void
    {
        $this->mockHttpClient->addResponse($this->createJsonResponse(200, 'true'));

        $result = $this->client->isShopAccountActive();

        $this->assertTrue($result);

        $request = $this->getLastRequest();

        $this->assertSame('GET', $request->getMethod());
        $this->assertStringContainsString('/v1/user/is-active', (string) $request->getUri());
        $this->assertSame(self::API_KEY, $request->getHeaderLine('Api-Key'));
    }

    /**
     * @throws ClientExceptionInterface
     */
    public function testIsShopAccountActiveReturnsFalse(): void
    {
        $this->mockHttpClient->addResponse($this->createJsonResponse(200, 'false'));

        $this->assertFalse($this->client->isShopAccountActive());
    }

    /**
     * @throws ClientExceptionInterface
     */
    public function testIsShopAccountActiveWithOptionalHeaders(): void
    {
        $this->mockHttpClient->addResponse($this->createJsonResponse(200, 'true'));

        $this->client->isShopAccountActive(
            'https://example-shop.test/cache-invalidate',
            'https://example-shop.test/config'
        );

        $request = $this->getLastRequest();

        $this->assertSame(
            'https://example-shop.test/cache-invalidate',
            $request->getHeaderLine('Comfino-Cache-Invalidate-Url')
        );
        $this->assertSame('https://example-shop.test/config', $request->getHeaderLine('Comfino-Configuration-Url'));
    }

    /**
     * @throws ClientExceptionInterface
     */
    public function testIsShopAccountActiveUsesCorrectBaseUrl(): void
    {
        $this->mockHttpClient->addResponse($this->createJsonResponse(200, 'true'));

        $this->client->isShopAccountActive();

        $this->assertStringStartsWith(self::SANDBOX_BASE_URL, (string) $this->getLastRequest()->getUri());
    }

    // -------------------------------------------------------------------------
    // getWidgetKey
    // -------------------------------------------------------------------------

    /**
     * @throws ClientExceptionInterface
     */
    public function testGetWidgetKeyReturnsString(): void
    {
        $this->mockHttpClient->addResponse($this->createJsonResponse(200, '"widget-key-abc123"'));

        $widgetKey = $this->client->getWidgetKey();

        $this->assertSame('widget-key-abc123', $widgetKey);

        $request = $this->getLastRequest();

        $this->assertSame('GET', $request->getMethod());
        $this->assertStringContainsString('/v1/widget-key', (string) $request->getUri());
    }

    // -------------------------------------------------------------------------
    // getProductTypes
    // -------------------------------------------------------------------------

    /**
     * @throws ClientExceptionInterface
     * @throws JsonException
     */
    public function testGetProductTypesReturnsKnownEnums(): void
    {
        $responseBody = json_encode(
            [
                'INSTALLMENTS_ZERO_PERCENT' => 'Raty 0%',
                'CONVENIENT_INSTALLMENTS' => 'Raty',
                'PAY_LATER' => 'Kup teraz, zapłać później',
            ],
            JSON_THROW_ON_ERROR
        );

        $this->mockHttpClient->addResponse($this->createJsonResponse(200, $responseBody));

        $response = $this->client->getProductTypes(ProductListType::WIDGET);

        $this->assertCount(3, $response->productTypes);

        foreach ($response->productTypes as $productType) {
            $this->assertInstanceOf(LoanTypeInterface::class, $productType);
            $this->assertTrue($productType->isKnown());
        }

        $this->assertSame(LoanType::INSTALLMENTS_ZERO_PERCENT, $response->productTypes[0]);
        $this->assertSame(LoanType::PAY_LATER, $response->productTypes[2]);

        $request = $this->getLastRequest();

        $this->assertSame('GET', $request->getMethod());
        $this->assertStringContainsString('/v1/product-types', (string) $request->getUri());
        $this->assertStringContainsString('listType=widget', (string) $request->getUri());
    }

    /**
     * @throws ClientExceptionInterface
     * @throws JsonException
     */
    public function testGetProductTypesIncludesProductTypesWithNames(): void
    {
        $responseBody = json_encode(['PAY_LATER' => 'Kup teraz, zapłać później'], JSON_THROW_ON_ERROR);

        $this->mockHttpClient->addResponse($this->createJsonResponse(200, $responseBody));

        $response = $this->client->getProductTypes(ProductListType::PAYWALL);

        $this->assertArrayHasKey('PAY_LATER', $response->productTypesWithNames);
        $this->assertSame('Kup teraz, zapłać później', $response->productTypesWithNames['PAY_LATER']);
    }

    // -------------------------------------------------------------------------
    // getFinancialProducts
    // -------------------------------------------------------------------------

    /**
     * @throws ClientExceptionInterface
     * @throws JsonException
     */
    public function testGetFinancialProductsReturnsParsedProducts(): void
    {
        $responseBody = json_encode(
            [
                [
                    'name' => 'Raty 0%',
                    'type' => 'INSTALLMENTS_ZERO_PERCENT',
                    'creditorName' => 'Test Bank',
                    'description' => '',
                    'icon' => 'https://example.com/icon.png',
                    'instalmentAmount' => 5000,
                    'toPay' => 150000,
                    'loanTerm' => 30,
                    'rrso' => 0.0,
                    'representativeExample' => '',
                    'remarks' => '',
                    'loanParameters' => [
                        [
                            'instalmentAmount' => 5000,
                            'toPay' => 150000,
                            'loanTerm' => 30,
                            'rrso' => 0.0,
                        ],
                    ],
                ],
            ],
            JSON_THROW_ON_ERROR
        );

        $this->mockHttpClient->addResponse($this->createJsonResponse(200, $responseBody));

        $response = $this->client->getFinancialProducts(new LoanQueryCriteria(loanAmount: 150000));

        $this->assertCount(1, $response->financialProducts);

        $product = $response->financialProducts[0];

        $this->assertSame('Raty 0%', $product->name);
        $this->assertSame(LoanType::INSTALLMENTS_ZERO_PERCENT, $product->type);
        $this->assertSame(5000, $product->instalmentAmount);
        $this->assertSame(150000, $product->toPay);
        $this->assertSame(30, $product->loanTerm);
        $this->assertCount(1, $product->loanParameters);

        $request = $this->getLastRequest();

        $this->assertSame('GET', $request->getMethod());
        $this->assertStringContainsString('/v1/financial-products', (string) $request->getUri());
        $this->assertStringContainsString('loanAmount=150000', (string) $request->getUri());
    }

    /**
     * @throws ClientExceptionInterface
     */
    public function testGetFinancialProductsWithLoanTypeSendsQueryParam(): void
    {
        $this->mockHttpClient->addResponse($this->createJsonResponse(200, '[]'));

        $this->client->getFinancialProducts(new LoanQueryCriteria(
            loanAmount: 100000,
            loanType: LoanType::PAY_LATER
        ));

        $this->assertStringContainsString('loanTypeSelected=PAY_LATER', (string) $this->getLastRequest()->getUri());
    }

    /**
     * @throws ClientExceptionInterface
     * @throws JsonException
     */
    public function testGetFinancialProductsHandlesUnknownLoanType(): void
    {
        $responseBody = json_encode(
            [
                [
                    'name' => 'Future product',
                    'type' => 'FUTURE_PRODUCT_TYPE',
                    'creditorName' => 'Test Bank',
                    'description' => '',
                    'icon' => '',
                    'instalmentAmount' => 1000,
                    'toPay' => 10000,
                    'loanTerm' => 10,
                    'rrso' => 0.0,
                    'loanParameters' => [],
                ],
            ],
            JSON_THROW_ON_ERROR
        );

        $this->mockHttpClient->addResponse($this->createJsonResponse(200, $responseBody));

        $response = $this->client->getFinancialProducts(new LoanQueryCriteria(loanAmount: 10000));

        $this->assertCount(1, $response->financialProducts);

        $type = $response->financialProducts[0]->type;

        $this->assertInstanceOf(LoanTypeInterface::class, $type);
        $this->assertFalse($type->isKnown());
        $this->assertSame('FUTURE_PRODUCT_TYPE', $type->getValue());
    }

    // -------------------------------------------------------------------------
    // createOrder
    // -------------------------------------------------------------------------

    /**
     * @throws ClientExceptionInterface
     * @throws JsonException
     */
    public function testCreateOrderReturnsResponse(): void
    {
        $responseBody = json_encode(
            [
                'status' => 'WAITING_FOR_PAYMENT',
                'externalId' => 'order-123',
                'applicationUrl' => 'https://app.comfino.pl/apply/abc123',
            ],
            JSON_THROW_ON_ERROR
        );

        $this->mockHttpClient->addResponse($this->createJsonResponse(201, $responseBody));

        $order = $this->createMockOrder();
        $response = $this->client->createOrder($order);

        $this->assertSame('WAITING_FOR_PAYMENT', $response->status);
        $this->assertSame('order-123', $response->externalId);
        $this->assertSame('https://app.comfino.pl/apply/abc123', $response->applicationUrl);

        $request = $this->getLastRequest();

        $this->assertSame('POST', $request->getMethod());
        $this->assertStringContainsString('/v1/orders', (string) $request->getUri());
    }

    /**
     * @throws ClientExceptionInterface
     * @throws JsonException
     */
    public function testCreateOrderSendsRequiredSignatureHeaders(): void
    {
        $responseBody = json_encode(
            [
                'status' => 'CREATED',
                'externalId' => 'order-456',
                'applicationUrl' => 'https://app.comfino.pl/apply/def456',
            ],
            JSON_THROW_ON_ERROR
        );

        $this->mockHttpClient->addResponse($this->createJsonResponse(201, $responseBody));

        $this->client->createOrder($this->createMockOrder());

        $request = $this->getLastRequest();

        $this->assertTrue($request->hasHeader('Comfino-Cart-Hash'), 'Comfino-Cart-Hash header must be set');
        $this->assertTrue($request->hasHeader('Comfino-Customer-Hash'), 'Comfino-Customer-Hash header must be set');
        $this->assertTrue($request->hasHeader('Comfino-Order-Signature'), 'Comfino-Order-Signature header must be set');

        // Verify signature is a non-empty SHA3-256 hex string (64 chars).
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $request->getHeaderLine('Comfino-Order-Signature'));
    }

    /**
     * @throws ClientExceptionInterface
     * @throws JsonException
     */
    public function testCreateOrderSendsApiKeyHeader(): void
    {
        $this->mockHttpClient->addResponse($this->createJsonResponse(
            201,
            json_encode(
                [
                    'status' => 'CREATED',
                    'externalId' => 'order-789',
                    'applicationUrl' => 'https://app.comfino.pl/apply/xyz',
                ],
                JSON_THROW_ON_ERROR
            )
        ));

        $this->client->createOrder($this->createMockOrder());

        $this->assertSame(self::API_KEY, $this->getLastRequest()->getHeaderLine('Api-Key'));
    }

    /**
     * @throws ClientExceptionInterface
     * @throws JsonException
     */
    public function testCreateOrderSendsJsonBody(): void
    {
        $this->mockHttpClient->addResponse($this->createJsonResponse(
            201,
            json_encode(
                [
                    'status' => 'CREATED',
                    'externalId' => 'order-1',
                    'applicationUrl' => 'https://app.comfino.pl/apply/1',
                ],
                JSON_THROW_ON_ERROR
            )
        ));

        $this->client->createOrder($this->createMockOrder('order-1', 150000));

        $request = $this->getLastRequest();

        $this->assertSame('application/json', $request->getHeaderLine('Content-Type'));

        $body = json_decode((string)$request->getBody(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('order-1', $body['orderId']);
        $this->assertArrayHasKey('cart', $body);
        $this->assertArrayHasKey('customer', $body);
        $this->assertArrayHasKey('loanParameters', $body);
    }

    // -------------------------------------------------------------------------
    // cancelOrder
    // -------------------------------------------------------------------------

    /**
     * @throws ClientExceptionInterface
     */
    public function testCancelOrderSendsPutRequest(): void
    {
        $this->mockHttpClient->addResponse(
            $this->psr17Factory->createResponse(200)->withHeader('Content-Type', 'application/json')
        );

        $this->client->cancelOrder('order-123');

        $request = $this->getLastRequest();

        $this->assertSame('PUT', $request->getMethod());
        $this->assertStringContainsString('/v1/orders/order-123/cancel', (string) $request->getUri());
    }

    // -------------------------------------------------------------------------
    // getOrder
    // -------------------------------------------------------------------------

    /**
     * @throws ClientExceptionInterface
     * @throws JsonException
     */
    public function testGetOrderReturnsOrderDetails(): void
    {
        $responseBody = json_encode(
            [
                'orderId' => 'order-123',
                'status' => 'WAITING_FOR_PAYMENT',
                'createdAt' => '2026-01-15T10:30:00+00:00',
                'applicationUrl' => 'https://app.comfino.pl/apply/abc',
                'notifyUrl' => 'https://example-shop.test/notify',
                'returnUrl' => 'https://example-shop.test/return',
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
                    'products' => [
                        [
                            'name' => 'Test product',
                            'price' => 150000,
                            'quantity' => 1,
                            'externalId' => 'PROD-1',
                            'photoUrl' => '',
                            'ean' => '',
                            'category' => '',
                        ],
                    ],
                ],
                'customer' => [
                    'firstName' => 'Jan',
                    'lastName' => 'Kowalski',
                    'email' => 'jan.kowalski@example.com',
                    'phoneNumber' => '500000000',
                    'ip' => '127.0.0.1',
                    'taxId' => null,
                    'regular' => false,
                    'logged' => false,
                    'address' => null,
                ],
            ],
            JSON_THROW_ON_ERROR
        );

        $this->mockHttpClient->addResponse($this->createJsonResponse(200, $responseBody));

        $response = $this->client->getOrder('order-123');

        $this->assertSame('order-123', $response->orderId);
        $this->assertSame('WAITING_FOR_PAYMENT', $response->status);
        $this->assertSame(150000, $response->loanParameters->amount);
        $this->assertSame(LoanType::INSTALLMENTS_ZERO_PERCENT, $response->loanParameters->type);
        $this->assertCount(1, $response->cart->products);
        $this->assertSame('Jan', $response->customer->firstName);

        $request = $this->getLastRequest();

        $this->assertSame('GET', $request->getMethod());
        $this->assertStringContainsString('/v1/orders/order-123', (string) $request->getUri());
    }

    // -------------------------------------------------------------------------
    // Request headers
    // -------------------------------------------------------------------------

    /**
     * @throws ClientExceptionInterface
     */
    public function testRequestAlwaysIncludesContentTypeAndLanguage(): void
    {
        $this->mockHttpClient->addResponse($this->createJsonResponse(200, 'true'));

        $this->client->isShopAccountActive();

        $request = $this->getLastRequest();

        $this->assertSame('application/json', $request->getHeaderLine('Content-Type'));
        $this->assertNotEmpty($request->getHeaderLine('Api-Language'));
        $this->assertNotEmpty($request->getHeaderLine('User-Agent'));
    }

    /**
     * @throws ClientExceptionInterface
     */
    public function testCustomUserAgentIsIncludedInRequest(): void
    {
        $this->mockHttpClient->addResponse($this->createJsonResponse(200, 'true'));

        $this->client->setCustomUserAgent('my-shop/1.0.0');
        $this->client->isShopAccountActive();

        $this->assertSame('my-shop/1.0.0', $this->getLastRequest()->getHeaderLine('User-Agent'));
    }

    /**
     * @throws ClientExceptionInterface
     */
    public function testCustomHeaderIsIncludedInRequest(): void
    {
        $this->mockHttpClient->addResponse($this->createJsonResponse(200, 'true'));

        $this->client->addCustomHeader('X-Shop-Platform', 'MyShop');
        $this->client->isShopAccountActive();

        $this->assertSame('MyShop', $this->getLastRequest()->getHeaderLine('X-Shop-Platform'));
    }

    /**
     * @throws ClientExceptionInterface
     */
    public function testRequestWithoutApiKeyOmitsApiKeyHeader(): void
    {
        $clientWithoutKey = new Client($this->mockHttpClient, $this->psr17Factory, $this->psr17Factory, null);
        $clientWithoutKey->enableSandboxMode();

        $this->mockHttpClient->addResponse($this->createJsonResponse(200, 'false'));

        $clientWithoutKey->isShopAccountActive();

        $this->assertEmpty($this->getLastRequest()->getHeaderLine('Api-Key'));
    }

    // -------------------------------------------------------------------------
    // Error handling
    // -------------------------------------------------------------------------

    /**
     * @throws ClientExceptionInterface
     */
    public function testUnauthorizedResponseThrowsAuthorizationError(): void
    {
        $this->mockHttpClient->addResponse(
            $this->createJsonResponse(401, '{"message":"Unauthorized"}', 'Unauthorized')
        );

        $this->expectException(AuthorizationError::class);

        $this->client->isShopAccountActive();
    }

    /**
     * @throws ClientExceptionInterface
     */
    public function testForbiddenResponseThrowsForbidden(): void
    {
        $this->mockHttpClient->addResponse(
            $this->createJsonResponse(403, '{"message":"Forbidden"}', 'Forbidden')
        );

        $this->expectException(Forbidden::class);

        $this->client->isShopAccountActive();
    }

    /**
     * @throws ClientExceptionInterface
     */
    public function testBadRequestResponseThrowsRequestValidationError(): void
    {
        $this->mockHttpClient->addResponse(
            $this->createJsonResponse(400, '{"errors":{"orderId":"Order ID is required"}}', 'Bad Request')
        );

        $this->expectException(RequestValidationError::class);

        $this->client->createOrder($this->createMockOrder());
    }

    /**
     * @throws ClientExceptionInterface
     */
    public function testServerErrorResponseThrowsServiceUnavailable(): void
    {
        $this->mockHttpClient->addResponse(
            $this->createJsonResponse(500, '{"message":"Internal Server Error"}', 'Internal Server Error')
        );

        $this->expectException(ServiceUnavailable::class);

        $this->client->isShopAccountActive();
    }

    // -------------------------------------------------------------------------
    // Sandbox / production URL selection
    // -------------------------------------------------------------------------

    /**
     * @throws ClientExceptionInterface
     */
    public function testProductionModeUsesProductionBaseUrl(): void
    {
        $this->client->disableSandboxMode();

        $this->mockHttpClient->addResponse($this->createJsonResponse(200, 'true'));

        $this->client->isShopAccountActive();

        $this->assertStringStartsWith(
            AbstractClient::PRODUCTION_API_BASE_URL,
            (string) $this->getLastRequest()->getUri()
        );
    }

    /**
     * @throws ClientExceptionInterface
     */
    public function testCustomApiBaseUrlOverridesDefault(): void
    {
        $customUrl = 'https://my-mock-api.internal';

        $this->client->setCustomApiBaseUrl($customUrl);

        $this->mockHttpClient->addResponse($this->createJsonResponse(200, 'true'));

        $this->client->isShopAccountActive();

        $this->assertStringStartsWith($customUrl, (string) $this->getLastRequest()->getUri());
    }

    // -------------------------------------------------------------------------
    // Client metadata
    // -------------------------------------------------------------------------

    public function testGetVersionReturnsClientVersion(): void
    {
        $this->assertSame(Client::CLIENT_VERSION, $this->client->getVersion());
    }

    public function testGetApiKeyReturnsConfiguredKey(): void
    {
        $this->assertSame(self::API_KEY, $this->client->getApiKey());
    }

    public function testSetApiKeyUpdatesApiKey(): void
    {
        $this->client->setApiKey('new-key');

        $this->assertSame('new-key', $this->client->getApiKey());
    }

    // -------------------------------------------------------------------------
    // getWidgetTypes
    // -------------------------------------------------------------------------

    /**
     * @throws ClientExceptionInterface
     * @throws JsonException
     */
    public function testGetWidgetTypesReturnsParsedTypes(): void
    {
        $responseBody = json_encode(['standard' => 'Standard', 'classic' => 'Classic'], JSON_THROW_ON_ERROR);

        $this->mockHttpClient->addResponse($this->createJsonResponse(200, $responseBody));

        $response = $this->client->getWidgetTypes();

        $this->assertCount(2, $response->widgetTypes);

        foreach ($response->widgetTypes as $widgetType) {
            $this->assertInstanceOf(WidgetTypeInterface::class, $widgetType);
            $this->assertTrue($widgetType->isKnown());
        }

        $this->assertSame('Standard', $response->widgetTypesWithNames['standard']);

        $request = $this->getLastRequest();

        $this->assertSame('GET', $request->getMethod());
        $this->assertStringContainsString('/v1/widget/widget-types', (string) $request->getUri());
    }

    // -------------------------------------------------------------------------
    // getFinancialProductDetails
    // -------------------------------------------------------------------------

    /**
     * @throws ClientExceptionInterface
     * @throws JsonException
     */
    public function testGetFinancialProductDetailsReturnsParsedProducts(): void
    {
        $responseBody = json_encode(
            [
                [
                    'name' => 'Raty 0%',
                    'type' => 'INSTALLMENTS_ZERO_PERCENT',
                    'creditorName' => 'Test Bank',
                    'description' => '',
                    'icon' => 'https://example.com/icon.png',
                    'instalmentAmount' => 5000,
                    'toPay' => 100000,
                    'loanTerm' => 20,
                    'rrso' => 0.0,
                    'loanParameters' => [],
                ],
            ],
            JSON_THROW_ON_ERROR
        );

        $this->mockHttpClient->addResponse($this->createJsonResponse(200, $responseBody));

        $response = $this->client->getFinancialProductDetails(
            new LoanQueryCriteria(loanAmount: 100000),
            $this->createMockCart()
        );

        $this->assertCount(1, $response->financialProducts);
        $this->assertSame('Raty 0%', $response->financialProducts[0]->name);
        $this->assertSame(LoanType::INSTALLMENTS_ZERO_PERCENT, $response->financialProducts[0]->type);

        $request = $this->getLastRequest();

        $this->assertSame('POST', $request->getMethod());
        $this->assertStringContainsString('/v1/financial-products', (string) $request->getUri());
    }

    // -------------------------------------------------------------------------
    // validateOrder
    // -------------------------------------------------------------------------

    /**
     * @throws ClientExceptionInterface
     * @throws JsonException
     */
    public function testValidateOrderReturnsSuccessOnValidData(): void
    {
        $this->mockHttpClient->addResponse($this->createJsonResponse(200, '{}'));

        $response = $this->client->validateOrder($this->createMockOrder());

        $this->assertTrue($response->success);
        $this->assertSame(200, $response->httpStatusCode);
        $this->assertEmpty($response->errors);
    }

    /**
     * @throws ClientExceptionInterface
     */
    public function testValidateOrderReturnsFailureOnValidationError(): void
    {
        $this->mockHttpClient->addResponse(
            $this->createJsonResponse(400, '{"errors":{"orderId":"is required"}}', 'Bad Request')
        );

        $response = $this->client->validateOrder($this->createMockOrder());

        $this->assertFalse($response->success);
        $this->assertSame(400, $response->httpStatusCode);
        $this->assertNotEmpty($response->errors);
    }

    // -------------------------------------------------------------------------
    // sendLoggedError
    // -------------------------------------------------------------------------

    public function testSendLoggedErrorReturnsTrueOnSuccess(): void
    {
        $this->mockHttpClient->addResponse(
            $this->psr17Factory->createResponse(200)->withHeader('Content-Type', 'application/json')
        );

        $this->assertTrue($this->client->sendLoggedError(
            new ShopPluginError('shop.test', 'WooCommerce', [], 'E001', 'Something failed')
        ));
    }

    public function testSendLoggedErrorReturnsFalseOnNetworkFailure(): void
    {
        $this->mockHttpClient->addException(new \RuntimeException('Network error'));

        $this->assertFalse($this->client->sendLoggedError(
            new ShopPluginError('shop.test', 'WooCommerce', [], 'E001', 'Something failed')
        ));
    }

    // -------------------------------------------------------------------------
    // notifyPluginRemoval
    // -------------------------------------------------------------------------

    public function testNotifyPluginRemovalReturnsTrueOnSuccess(): void
    {
        $this->mockHttpClient->addResponse(
            $this->psr17Factory->createResponse(200)->withHeader('Content-Type', 'application/json')
        );

        $this->assertTrue($this->client->notifyPluginRemoval());
    }

    public function testNotifyPluginRemovalReturnsFalseOnNetworkFailure(): void
    {
        $this->mockHttpClient->addException(new \RuntimeException('Network error'));

        $this->assertFalse($this->client->notifyPluginRemoval());
    }

    // -------------------------------------------------------------------------
    // notifyAbandonedCart
    // -------------------------------------------------------------------------

    public function testNotifyAbandonedCartReturnsTrueOnSuccess(): void
    {
        $this->mockHttpClient->addResponse(
            $this->psr17Factory->createResponse(200)->withHeader('Content-Type', 'application/json')
        );

        $this->assertTrue($this->client->notifyAbandonedCart('CART_ABANDONED'));
    }

    public function testNotifyAbandonedCartReturnsFalseOnNetworkFailure(): void
    {
        $this->mockHttpClient->addException(new \RuntimeException('Network error'));

        $this->assertFalse($this->client->notifyAbandonedCart('CART_ABANDONED'));
    }

    // -------------------------------------------------------------------------
    // API language / currency / version
    // -------------------------------------------------------------------------

    public function testDefaultApiLanguageIsPl(): void
    {
        $this->assertSame('pl', $this->client->getApiLanguage());
    }

    /**
     * @throws ClientExceptionInterface
     */
    public function testSetApiLanguageChangesLanguageHeader(): void
    {
        $this->mockHttpClient->addResponse($this->createJsonResponse(200, 'true'));

        $this->client->setApiLanguage('en');
        $this->client->isShopAccountActive();

        $this->assertSame('en', $this->getLastRequest()->getHeaderLine('Api-Language'));
        $this->assertSame('en', $this->client->getApiLanguage());
    }

    public function testDefaultApiCurrencyIsPln(): void
    {
        $this->assertSame('PLN', $this->client->getApiCurrency());
    }

    /**
     * @throws ClientExceptionInterface
     */
    public function testSetApiCurrencyChangesCurrencyHeader(): void
    {
        $this->mockHttpClient->addResponse($this->createJsonResponse(200, 'true'));

        $this->client->setApiCurrency('EUR');
        $this->client->isShopAccountActive();

        $this->assertSame('EUR', $this->getLastRequest()->getHeaderLine('Api-Currency'));
        $this->assertSame('EUR', $this->client->getApiCurrency());
    }

    /**
     * @throws ClientExceptionInterface
     */
    public function testSetApiVersionUsesNewVersionInPath(): void
    {
        $this->mockHttpClient->addResponse($this->createJsonResponse(200, 'true'));

        $this->client->setApiVersion(2);
        $this->client->isShopAccountActive();

        $this->assertStringContainsString('/v2/user/is-active', (string) $this->getLastRequest()->getUri());
    }

    // -------------------------------------------------------------------------
    // HTTP client / serializer replacement
    // -------------------------------------------------------------------------

    /**
     * @throws ClientExceptionInterface
     */
    public function testSetHttpClientReplacesHttpClient(): void
    {
        $newMockClient = new MockHttpClient($this->psr17Factory);
        $newMockClient->addResponse($this->createJsonResponse(200, 'true'));

        $this->client->setHttpClient($newMockClient);

        $this->assertTrue($this->client->isShopAccountActive());
    }

    /**
     * @throws ClientExceptionInterface
     */
    public function testSetSerializerIsUsedForSubsequentRequests(): void
    {
        $this->mockHttpClient->addResponse($this->createJsonResponse(200, 'true'));

        $this->client->setSerializer(new JsonSerializer());

        $this->assertTrue($this->client->isShopAccountActive());
    }

    // -------------------------------------------------------------------------
    // getRequest / setClientHostname
    // -------------------------------------------------------------------------

    public function testGetRequestReturnsNullBeforeFirstCall(): void
    {
        $this->assertNull($this->client->getRequest());
    }

    /**
     * @throws ClientExceptionInterface
     */
    public function testGetRequestReturnsLastRequestObjectAfterApiCall(): void
    {
        $this->mockHttpClient->addResponse($this->createJsonResponse(200, 'true'));

        $this->client->isShopAccountActive();

        $this->assertNotNull($this->client->getRequest());
    }

    /**
     * @throws ClientExceptionInterface
     */
    public function testSetClientHostnameIsReflectedInTrackIdHeader(): void
    {
        $this->mockHttpClient->addResponse($this->createJsonResponse(200, 'true'));

        $this->client->setClientHostname('my-shop.example.com');
        $this->client->isShopAccountActive();

        $this->assertStringStartsWith(
            'my-shop.example.com-',
            $this->getLastRequest()->getHeaderLine('Comfino-Track-Id')
        );
    }

    // -------------------------------------------------------------------------
    // addCustomHeader - invalid input
    // -------------------------------------------------------------------------

    public function testAddCustomHeaderThrowsOnInvalidHeaderName(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->client->addCustomHeader('Invalid Header Name', 'value'); // space is not allowed
    }

    public function testAddCustomHeaderThrowsOnHeaderValueInjection(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->client->addCustomHeader('X-Custom', "value\r\nX-Injected: bad");
    }
}
