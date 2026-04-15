<?php

/**
 * Comfino PHP API client
 *
 * Backend routines for communication with the Comfino payment gateway REST API.
 *
 * @package Comfino\Tests\Unit\Api\Request
 * @author Artur Kozubski <akozubski@comperia.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-api-client
 */

declare(strict_types=1);

namespace Comfino\Tests\Unit\Api\Request;

use Comfino\Api\Dto\Payment\LoanQueryCriteria;
use Comfino\Api\Dto\Plugin\ShopPluginError;
use Comfino\Api\Exception\RequestValidationError;
use Comfino\Api\Request;
use Comfino\Api\Request\CancelOrder;
use Comfino\Api\Request\CreateOrder;
use Comfino\Api\Request\GetFinancialProducts;
use Comfino\Api\Request\GetOrder;
use Comfino\Api\Request\GetProductTypes;
use Comfino\Api\Request\GetWidgetKey;
use Comfino\Api\Request\GetWidgetTypes;
use Comfino\Api\Request\IsShopAccountActive;
use Comfino\Api\Request\NotifyAbandonedCart;
use Comfino\Api\Request\NotifyShopPluginRemoval;
use Comfino\Api\Request\ReportShopPluginError;
use Comfino\Api\Serializer\Json;
use Comfino\Enum\LoanType;
use Comfino\Enum\ProductListType;
use Comfino\Shop\Order\Cart\CartItemInterface;
use Comfino\Shop\Order\Cart\ProductInterface;
use Comfino\Shop\Order\CartInterface;
use Comfino\Shop\Order\CustomerInterface;
use Comfino\Shop\Order\LoanParametersInterface;
use Comfino\Shop\Order\OrderInterface;
use JsonException;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

final class RequestBuildingTest extends TestCase
{
    private const API_BASE_URL = 'https://api-ecommerce.craty.pl';
    private const API_VERSION = 1;
    private const HASH_KEY = 'test-hash-key-1234'; // >= 16 characters

    private Psr17Factory $factory;
    private Json $serializer;

    protected function setUp(): void
    {
        $this->factory = new Psr17Factory();
        $this->serializer = new Json();
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function build(Request $request, int $apiVersion = self::API_VERSION): RequestInterface
    {
        return $request->setSerializer($this->serializer)
            ->getPsrRequest($this->factory, $this->factory, self::API_BASE_URL, $apiVersion);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws JsonException
     */
    private function decodeBody(RequestInterface $request): array
    {
        return json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * Decode a ReportShopPluginError request body into the original error_details array.
     *
     * @return array<string, mixed>
     *
     * @throws JsonException
     */
    private function decodeReportBody(RequestInterface $request): array
    {
        $outer = $this->decodeBody($request);
        $raw = gzuncompress(base64_decode($outer['error_details']));

        return json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    }

    private function createMockOrder(string $orderId = 'order-1'): OrderInterface
    {
        $product = $this->createMock(ProductInterface::class);
        $product->method('getName')->willReturn('Product');
        $product->method('getPrice')->willReturn(100000);
        $product->method('getId')->willReturn('P1');
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
        $cart->method('getTotalAmount')->willReturn(100000);
        $cart->method('getDeliveryCost')->willReturn(0);
        $cart->method('getDeliveryNetCost')->willReturn(null);
        $cart->method('getDeliveryCostTaxRate')->willReturn(null);
        $cart->method('getDeliveryCostTaxValue')->willReturn(null);
        $cart->method('getCategory')->willReturn(null);

        $loanParams = $this->createMock(LoanParametersInterface::class);
        $loanParams->method('getAmount')->willReturn(100000);
        $loanParams->method('getTerm')->willReturn(null);
        $loanParams->method('getType')->willReturn(null);
        $loanParams->method('getAllowedProductTypes')->willReturn(null);

        $customer = $this->createMock(CustomerInterface::class);
        $customer->method('getFirstName')->willReturn('Anna');
        $customer->method('getLastName')->willReturn('Nowak');
        $customer->method('getEmail')->willReturn('anna@example.com');
        $customer->method('getPhoneNumber')->willReturn('600000000');
        $customer->method('getTaxId')->willReturn(null);
        $customer->method('getIp')->willReturn('10.0.0.1');
        $customer->method('isRegular')->willReturn(false);
        $customer->method('isLogged')->willReturn(false);
        $customer->method('getAddress')->willReturn(null);

        $order = $this->createMock(OrderInterface::class);
        $order->method('getId')->willReturn($orderId);
        $order->method('getNotifyUrl')->willReturn('https://shop.test/notify');
        $order->method('getReturnUrl')->willReturn('https://shop.test/return');
        $order->method('getLoanParameters')->willReturn($loanParams);
        $order->method('getCart')->willReturn($cart);
        $order->method('getCustomer')->willReturn($customer);
        $order->method('getSeller')->willReturn(null);
        $order->method('getAccountNumber')->willReturn(null);
        $order->method('getTransferTitle')->willReturn(null);

        return $order;
    }

    // -------------------------------------------------------------------------
    // GetWidgetKey
    // -------------------------------------------------------------------------

    public function testGetWidgetKeyUsesGetMethodAndCorrectPath(): void
    {
        $psrRequest = $this->build(new GetWidgetKey());

        $this->assertSame('GET', $psrRequest->getMethod());
        $this->assertStringContainsString('/v1/widget-key', (string) $psrRequest->getUri());
        $this->assertEmpty((string) $psrRequest->getBody());
    }

    // -------------------------------------------------------------------------
    // GetWidgetTypes
    // -------------------------------------------------------------------------

    public function testGetWidgetTypesUsesGetMethodAndCorrectPath(): void
    {
        $psrRequest = $this->build(new GetWidgetTypes());

        $this->assertSame('GET', $psrRequest->getMethod());
        $this->assertStringContainsString('/v1/widget/widget-types', (string) $psrRequest->getUri());
        $this->assertEmpty((string) $psrRequest->getBody());
    }

    // -------------------------------------------------------------------------
    // IsShopAccountActive
    // -------------------------------------------------------------------------

    public function testIsShopAccountActiveUsesGetMethodAndCorrectPath(): void
    {
        $psrRequest = $this->build(new IsShopAccountActive(null, null));

        $this->assertSame('GET', $psrRequest->getMethod());
        $this->assertStringContainsString('/v1/user/is-active', (string) $psrRequest->getUri());
    }

    public function testIsShopAccountActiveWithNoOptionalHeadersAddsNone(): void
    {
        $psrRequest = $this->build(new IsShopAccountActive(null, null));

        $this->assertFalse($psrRequest->hasHeader('Comfino-Cache-Invalidate-Url'));
        $this->assertFalse($psrRequest->hasHeader('Comfino-Configuration-Url'));
    }

    public function testIsShopAccountActiveWithCacheInvalidateUrl(): void
    {
        $psrRequest = $this->build(new IsShopAccountActive('https://shop.test/cache', null));

        $this->assertSame('https://shop.test/cache', $psrRequest->getHeaderLine('Comfino-Cache-Invalidate-Url'));
        $this->assertFalse($psrRequest->hasHeader('Comfino-Configuration-Url'));
    }

    public function testIsShopAccountActiveWithBothOptionalHeaders(): void
    {
        $psrRequest = $this->build(
            new IsShopAccountActive('https://shop.test/cache', 'https://shop.test/config')
        );

        $this->assertSame('https://shop.test/cache', $psrRequest->getHeaderLine('Comfino-Cache-Invalidate-Url'));
        $this->assertSame('https://shop.test/config', $psrRequest->getHeaderLine('Comfino-Configuration-Url'));
    }

    // -------------------------------------------------------------------------
    // GetProductTypes
    // -------------------------------------------------------------------------

    public function testGetProductTypesIncludesWidgetListType(): void
    {
        $psrRequest = $this->build(new GetProductTypes(ProductListType::WIDGET));

        $this->assertSame('GET', $psrRequest->getMethod());
        $this->assertStringContainsString('/v1/product-types', (string) $psrRequest->getUri());
        $this->assertStringContainsString('listType=widget', (string) $psrRequest->getUri());
    }

    public function testGetProductTypesIncludesPaywallListType(): void
    {
        $psrRequest = $this->build(new GetProductTypes(ProductListType::PAYWALL));

        $this->assertStringContainsString('listType=paywall', (string) $psrRequest->getUri());
    }

    // -------------------------------------------------------------------------
    // GetFinancialProducts
    // -------------------------------------------------------------------------

    public function testGetFinancialProductsWithLoanAmountOnly(): void
    {
        $psrRequest = $this->build(new GetFinancialProducts(new LoanQueryCriteria(loanAmount: 150000)));

        $this->assertSame('GET', $psrRequest->getMethod());
        $this->assertStringContainsString('/v1/financial-products', (string) $psrRequest->getUri());
        $this->assertStringContainsString('loanAmount=150000', (string) $psrRequest->getUri());
        $this->assertStringNotContainsString('loanTypeSelected', (string) $psrRequest->getUri());
        $this->assertStringNotContainsString('loanTerm', (string) $psrRequest->getUri());
    }

    public function testGetFinancialProductsWithAllParams(): void
    {
        $psrRequest = $this->build(new GetFinancialProducts(new LoanQueryCriteria(
            loanAmount: 200000,
            loanTerm: 24,
            loanType: LoanType::PAY_LATER,
            taxId: '9876543210'
        )));

        $uri = (string) $psrRequest->getUri();

        $this->assertStringContainsString('loanAmount=200000', $uri);
        $this->assertStringContainsString('loanTerm=24', $uri);
        $this->assertStringContainsString('loanTypeSelected=PAY_LATER', $uri);
        $this->assertStringContainsString('taxId=9876543210', $uri);
        $this->assertEmpty((string) $psrRequest->getBody());
    }

    // -------------------------------------------------------------------------
    // GetOrder
    // -------------------------------------------------------------------------

    public function testGetOrderUsesGetMethodWithOrderIdInPath(): void
    {
        $psrRequest = $this->build(new GetOrder('ORD-999'));

        $this->assertSame('GET', $psrRequest->getMethod());
        $this->assertStringContainsString('/v1/orders/ORD-999', (string) $psrRequest->getUri());
        $this->assertStringNotContainsString('cancel', (string) $psrRequest->getUri());
    }

    // -------------------------------------------------------------------------
    // CancelOrder
    // -------------------------------------------------------------------------

    public function testCancelOrderUsesPutMethodWithCancelSuffix(): void
    {
        $psrRequest = $this->build(new CancelOrder('ORD-42'));

        $this->assertSame('PUT', $psrRequest->getMethod());
        $this->assertStringContainsString('/v1/orders/ORD-42/cancel', (string) $psrRequest->getUri());
    }

    // -------------------------------------------------------------------------
    // NotifyAbandonedCart
    // -------------------------------------------------------------------------

    /**
     * @throws JsonException
     */
    public function testNotifyAbandonedCartUsesPostWithTypeInBody(): void
    {
        $psrRequest = $this->build(new NotifyAbandonedCart('CART_ABANDONED'));

        $this->assertSame('POST', $psrRequest->getMethod());
        $this->assertStringContainsString('/v1/abandoned_cart', (string) $psrRequest->getUri());

        $body = $this->decodeBody($psrRequest);
        $this->assertSame('CART_ABANDONED', $body['type']);
    }

    // -------------------------------------------------------------------------
    // NotifyShopPluginRemoval
    // -------------------------------------------------------------------------

    public function testNotifyShopPluginRemovalUsesPutToCorrectPath(): void
    {
        $psrRequest = $this->build(new NotifyShopPluginRemoval());

        $this->assertSame('PUT', $psrRequest->getMethod());
        $this->assertStringContainsString('/v1/log-plugin-remove', (string) $psrRequest->getUri());
    }

    // -------------------------------------------------------------------------
    // CreateOrder
    // -------------------------------------------------------------------------

    public function testCreateOrderUsesPostToOrdersPath(): void
    {
        $psrRequest = $this->build(new CreateOrder($this->createMockOrder(), 'api-key'));

        $this->assertSame('POST', $psrRequest->getMethod());
        $this->assertStringContainsString('/v1/orders', (string) $psrRequest->getUri());
        $this->assertStringNotContainsString('cancel', (string) $psrRequest->getUri());
    }

    /**
     * @throws JsonException
     */
    public function testCreateOrderBodyContainsExpectedStructure(): void
    {
        $psrRequest = $this->build(new CreateOrder($this->createMockOrder('order-99'), 'api-key'));
        $body = $this->decodeBody($psrRequest);

        $this->assertSame('order-99', $body['orderId']);
        $this->assertArrayHasKey('cart', $body);
        $this->assertArrayHasKey('customer', $body);
        $this->assertArrayHasKey('loanParameters', $body);
        $this->assertSame('https://shop.test/notify', $body['notifyUrl']);
        $this->assertSame('https://shop.test/return', $body['returnUrl']);
    }

    public function testCreateOrderSetsAllSignatureHeaders(): void
    {
        $psrRequest = $this->build(new CreateOrder($this->createMockOrder(), 'api-key'));

        $this->assertTrue($psrRequest->hasHeader('Comfino-Cart-Hash'));
        $this->assertTrue($psrRequest->hasHeader('Comfino-Customer-Hash'));
        $this->assertTrue($psrRequest->hasHeader('Comfino-Order-Signature'));
    }

    public function testCreateOrderSignatureIsCorrectSha3Hash(): void
    {
        $apiKey = 'my-secret-key';
        $psrRequest = $this->build(new CreateOrder($this->createMockOrder(), $apiKey));

        $cartHash = $psrRequest->getHeaderLine('Comfino-Cart-Hash');
        $customerHash = $psrRequest->getHeaderLine('Comfino-Customer-Hash');
        $expected = hash('sha3-256', $cartHash . $customerHash . $apiKey);

        $this->assertSame($expected, $psrRequest->getHeaderLine('Comfino-Order-Signature'));
    }

    public function testCreateOrderSignatureChangesWithApiKey(): void
    {
        $order = $this->createMockOrder();

        $psr1 = $this->build(new CreateOrder($order, 'key-a'));
        $psr2 = $this->build(new CreateOrder($order, 'key-b'));

        $this->assertNotSame(
            $psr1->getHeaderLine('Comfino-Order-Signature'),
            $psr2->getHeaderLine('Comfino-Order-Signature')
        );
    }

    public function testCreateOrderHashesAreSha3256HexStrings(): void
    {
        $psrRequest = $this->build(new CreateOrder($this->createMockOrder(), 'key'));

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $psrRequest->getHeaderLine('Comfino-Cart-Hash'));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $psrRequest->getHeaderLine('Comfino-Customer-Hash'));
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{64}$/',
            $psrRequest->getHeaderLine('Comfino-Order-Signature')
        );
    }

    // -------------------------------------------------------------------------
    // ReportShopPluginError
    // -------------------------------------------------------------------------

    public function testReportShopPluginErrorUsesPostToCorrectPathWithApiVersion2(): void
    {
        $error = new ShopPluginError('shop.test', 'WooCommerce', [], 'E001', 'Something failed');
        $psrRequest = $this->build(new ReportShopPluginError($error, self::HASH_KEY), 2);

        $this->assertSame('POST', $psrRequest->getMethod());
        $this->assertStringContainsString('/v2/log-plugin-error', (string) $psrRequest->getUri());
    }

    /**
     * @throws JsonException
     */
    public function testReportShopPluginErrorRedactsSensitiveEnvironmentKeys(): void
    {
        $env = [
            'APP_ENV' => 'production',
            'DB_PASSWORD' => 'secret123',
            'API_KEY' => 'abc',
            'auth_token' => 'xyz',
            'SHOP_VERSION' => '8.1',
        ];

        $error = new ShopPluginError('shop.test', 'PrestaShop', $env, 'E002', 'Error');
        $psrRequest = $this->build(new ReportShopPluginError($error, self::HASH_KEY));
        $details = $this->decodeReportBody($psrRequest);

        $this->assertSame('production', $details['environment']['APP_ENV']);
        $this->assertSame('[REDACTED]', $details['environment']['DB_PASSWORD']);
        $this->assertSame('[REDACTED]', $details['environment']['API_KEY']);
        $this->assertSame('[REDACTED]', $details['environment']['auth_token']);
        $this->assertSame('8.1', $details['environment']['SHOP_VERSION']);
    }

    /**
     * @throws JsonException
     */
    public function testReportShopPluginErrorStripsAbsolutePathsFromStackTrace(): void
    {
        $stackTrace = "#0 /var/www/html/shop/plugins/comfino/src/Foo.php(42): Bar->baz()\n"
            . "#1 /home/user/vendor/comfino/Baz.php(10): Qux->run()";

        $error = new ShopPluginError('shop.test', 'Magento', [], 'E003', 'trace', null, null, null, $stackTrace);
        $psrRequest = $this->build(new ReportShopPluginError($error, self::HASH_KEY));
        $details = $this->decodeReportBody($psrRequest);

        $this->assertStringContainsString('Foo.php(42)', $details['stack_trace']);
        $this->assertStringNotContainsString('/var/www/html', $details['stack_trace']);
        $this->assertStringContainsString('Baz.php(10)', $details['stack_trace']);
        $this->assertStringNotContainsString('/home/user', $details['stack_trace']);
    }

    /**
     * @throws JsonException
     */
    public function testReportShopPluginErrorStripsAbsolutePathsFromErrorMessage(): void
    {
        $error = new ShopPluginError(
            'shop.test',
            'WooCommerce',
            [],
            'E_PARSE',
            'Error E_PARSE in /var/www/html/shop/comfino/src/Foo.php:42'
        );
        $psrRequest = $this->build(new ReportShopPluginError($error, self::HASH_KEY));
        $details = $this->decodeReportBody($psrRequest);

        $this->assertStringContainsString('Foo.php:42', $details['error_message']);
        $this->assertStringNotContainsString('/var/www/html', $details['error_message']);
    }

    /**
     * @throws JsonException
     */
    public function testReportShopPluginErrorBodyContainsTimestampAndHash(): void
    {
        $error = new ShopPluginError('shop.test', 'WooCommerce', [], 'E001', 'err');
        $psrRequest = $this->build(new ReportShopPluginError($error, self::HASH_KEY));
        $outer = $this->decodeBody($psrRequest);

        $this->assertArrayHasKey('error_details', $outer);
        $this->assertArrayHasKey('timestamp', $outer);
        $this->assertArrayHasKey('hash', $outer);
        $this->assertIsInt($outer['timestamp']);
        $this->assertSame(64, strlen($outer['hash'])); // sha3-256 hex = 64 characters
    }

    // -------------------------------------------------------------------------
    // CartTrait - discount and correction items
    // -------------------------------------------------------------------------

    /**
     * When the sum of cart items + delivery exceeds the order total, a discount item is appended.
     *
     * @throws JsonException
     */
    public function testCreateOrderAddsDiscountItemWhenCartTotalExceedsOrderTotal(): void
    {
        // Product: 100 000, delivery: 5 000 → items + delivery = 105 000, but order total = 90 000.
        $product = $this->createMock(ProductInterface::class);
        $product->method('getName')->willReturn('Product');
        $product->method('getPrice')->willReturn(100000);
        $product->method('getId')->willReturn('P1');
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
        $cart->method('getTotalAmount')->willReturn(90000);
        $cart->method('getDeliveryCost')->willReturn(5000);
        $cart->method('getDeliveryNetCost')->willReturn(null);
        $cart->method('getDeliveryCostTaxRate')->willReturn(null);
        $cart->method('getDeliveryCostTaxValue')->willReturn(null);
        $cart->method('getCategory')->willReturn(null);

        $loanParams = $this->createMock(LoanParametersInterface::class);
        $loanParams->method('getAmount')->willReturn(90000);
        $loanParams->method('getTerm')->willReturn(null);
        $loanParams->method('getType')->willReturn(null);
        $loanParams->method('getAllowedProductTypes')->willReturn(null);

        $customer = $this->createMock(CustomerInterface::class);
        $customer->method('getFirstName')->willReturn('Anna');
        $customer->method('getLastName')->willReturn('Nowak');
        $customer->method('getEmail')->willReturn('anna@example.com');
        $customer->method('getPhoneNumber')->willReturn('600000000');
        $customer->method('getTaxId')->willReturn(null);
        $customer->method('getIp')->willReturn('10.0.0.1');
        $customer->method('isRegular')->willReturn(false);
        $customer->method('isLogged')->willReturn(false);
        $customer->method('getAddress')->willReturn(null);

        $order = $this->createMock(OrderInterface::class);
        $order->method('getId')->willReturn('order-discount');
        $order->method('getNotifyUrl')->willReturn('https://shop.test/notify');
        $order->method('getReturnUrl')->willReturn('https://shop.test/return');
        $order->method('getLoanParameters')->willReturn($loanParams);
        $order->method('getCart')->willReturn($cart);
        $order->method('getCustomer')->willReturn($customer);
        $order->method('getSeller')->willReturn(null);
        $order->method('getAccountNumber')->willReturn(null);
        $order->method('getTransferTitle')->willReturn(null);

        $psrRequest = $this->build(new CreateOrder($order, 'api-key'));
        $body = $this->decodeBody($psrRequest);

        $products = $body['cart']['products'];

        $this->assertCount(2, $products);

        $discountItem = end($products);

        $this->assertSame('Rabat', $discountItem['name']);
        $this->assertSame('DISCOUNT', $discountItem['category']);
        $this->assertSame(-15000, $discountItem['price']); // 90000 - (100000 + 5000)
    }

    /**
     * When the order total exceeds the sum of cart items plus delivery, a correction item is appended.
     *
     * @throws JsonException
     */
    public function testCreateOrderAddsCorrectionItemWhenOrderTotalExceedsCartTotal(): void
    {
        // Product: 100 000, no delivery → items total = 100 000, but order total = 120 000.
        $product = $this->createMock(ProductInterface::class);
        $product->method('getName')->willReturn('Product');
        $product->method('getPrice')->willReturn(100000);
        $product->method('getId')->willReturn('P1');
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
        $cart->method('getTotalAmount')->willReturn(120000);
        $cart->method('getDeliveryCost')->willReturn(0);
        $cart->method('getDeliveryNetCost')->willReturn(null);
        $cart->method('getDeliveryCostTaxRate')->willReturn(null);
        $cart->method('getDeliveryCostTaxValue')->willReturn(null);
        $cart->method('getCategory')->willReturn(null);

        $loanParams = $this->createMock(LoanParametersInterface::class);
        $loanParams->method('getAmount')->willReturn(120000);
        $loanParams->method('getTerm')->willReturn(null);
        $loanParams->method('getType')->willReturn(null);
        $loanParams->method('getAllowedProductTypes')->willReturn(null);

        $customer = $this->createMock(CustomerInterface::class);
        $customer->method('getFirstName')->willReturn('Anna');
        $customer->method('getLastName')->willReturn('Nowak');
        $customer->method('getEmail')->willReturn('anna@example.com');
        $customer->method('getPhoneNumber')->willReturn('600000000');
        $customer->method('getTaxId')->willReturn(null);
        $customer->method('getIp')->willReturn('10.0.0.1');
        $customer->method('isRegular')->willReturn(false);
        $customer->method('isLogged')->willReturn(false);
        $customer->method('getAddress')->willReturn(null);

        $order = $this->createMock(OrderInterface::class);
        $order->method('getId')->willReturn('order-correction');
        $order->method('getNotifyUrl')->willReturn('https://shop.test/notify');
        $order->method('getReturnUrl')->willReturn('https://shop.test/return');
        $order->method('getLoanParameters')->willReturn($loanParams);
        $order->method('getCart')->willReturn($cart);
        $order->method('getCustomer')->willReturn($customer);
        $order->method('getSeller')->willReturn(null);
        $order->method('getAccountNumber')->willReturn(null);
        $order->method('getTransferTitle')->willReturn(null);

        $psrRequest = $this->build(new CreateOrder($order, 'api-key'));
        $body = $this->decodeBody($psrRequest);

        $products = $body['cart']['products'];

        $this->assertCount(2, $products);

        $correctionItem = end($products);

        $this->assertSame('Korekta', $correctionItem['name']);
        $this->assertSame('ADDITIONAL_FEE', $correctionItem['category']);
        $this->assertSame(20000, $correctionItem['price']); // 120000 - 100000
    }

    /**
     * @throws RequestValidationError
     */
    public function testReportShopPluginErrorThrowsWhenHashKeyTooShort(): void
    {
        $this->expectException(RequestValidationError::class);

        $error = new ShopPluginError('shop.test', 'WooCommerce', [], 'E001', 'err');
        $request = new ReportShopPluginError($error, 'short');
        $request->setSerializer($this->serializer);
        $request->getPsrRequest($this->factory, $this->factory, self::API_BASE_URL, self::API_VERSION);
    }
}
