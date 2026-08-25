<?php

/**
 * Comfino PHP API client
 *
 * Backend routines for communication with the Comfino payment gateway REST API.
 *
 * @package Comfino\Tests\Unit\Api\Dto\Plugin
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-api-client
 */

declare(strict_types=1);

namespace Comfino\Tests\Unit\Api\Dto\Plugin;

use Comfino\Api\Dto\Plugin\ErrorCategory;
use Comfino\Api\Dto\Plugin\ErrorSeverity;
use Comfino\Api\Dto\Plugin\OperationContext;
use Comfino\Api\Dto\Plugin\ShopPluginError;
use Comfino\Api\Serializer\Json;
use PHPUnit\Framework\TestCase;

final class ShopPluginErrorTest extends TestCase
{
    public function testQueuePayloadRoundTripPreservesAllFields(): void
    {
        $serializer = new Json();

        $error = new ShopPluginError(
            'shop.test',
            'Magento',
            '1.2.3',
            '2.4.7',
            '8.1.27',
            ErrorCategory::ExceptionTypeError,
            ErrorSeverity::Critical,
            OperationContext::ApiCommunication,
            'TypeError',
            'Boom',
            ['caller' => 'Foo::bar@Foo.php:42'],
            'v1/financial-products',
            'https://api.comfino.pl/v1/financial-products?foo=bar',
            '{"req":1}',
            '{"resp":2}',
            "#0 Foo.php(42): Bar->baz()",
            1729683272
        );

        $restored = ShopPluginError::fromQueuePayload($error->toQueuePayload($serializer), $serializer);

        $this->assertSame('shop.test', $restored->host);
        $this->assertSame('Magento', $restored->platform);
        $this->assertSame('1.2.3', $restored->pluginVersion);
        $this->assertSame('2.4.7', $restored->platformVersion);
        $this->assertSame('8.1.27', $restored->phpVersion);
        $this->assertSame(ErrorCategory::ExceptionTypeError, $restored->category);
        $this->assertSame(ErrorSeverity::Critical, $restored->severity);
        $this->assertSame(OperationContext::ApiCommunication, $restored->context);
        $this->assertSame('TypeError', $restored->errorCode);
        $this->assertSame('Boom', $restored->errorMessage);
        $this->assertSame(['caller' => 'Foo::bar@Foo.php:42'], $restored->environment);
        $this->assertSame('v1/financial-products', $restored->apiEndpoint);
        $this->assertSame('https://api.comfino.pl/v1/financial-products?foo=bar', $restored->apiRequestUrl);
        $this->assertSame('{"req":1}', $restored->apiRequest);
        $this->assertSame('{"resp":2}', $restored->apiResponse);
        $this->assertSame('#0 Foo.php(42): Bar->baz()', $restored->stackTrace);
        $this->assertSame(1729683272, $restored->occurredAt);
    }

    public function testQueuePayloadRoundTripCoercesNullsToEmptyAndBack(): void
    {
        $serializer = new Json();

        $error = new ShopPluginError(
            'shop.test',
            'WooCommerce',
            '1.0.0',
            '6.5',
            '8.2.0',
            ErrorCategory::Other,
            ErrorSeverity::Error,
            OperationContext::Unknown,
            'E001',
            'err'
        );

        $payload = $error->toQueuePayload($serializer);

        // Nullable fields are stored as empty strings, keeping the payload scalar-only.
        $this->assertSame('', $payload['apiEndpoint']);
        $this->assertSame('', $payload['apiRequestUrl']);
        $this->assertSame('', $payload['apiRequest']);
        $this->assertSame('', $payload['apiResponse']);
        $this->assertSame('', $payload['stackTrace']);
        $this->assertSame('', $payload['occurredAt']);

        $restored = ShopPluginError::fromQueuePayload($payload, $serializer);

        $this->assertNull($restored->apiEndpoint);
        $this->assertNull($restored->apiRequestUrl);
        $this->assertNull($restored->apiRequest);
        $this->assertNull($restored->apiResponse);
        $this->assertNull($restored->stackTrace);
        $this->assertNull($restored->occurredAt);
    }

    public function testFromQueuePayloadFallsBackToSafeEnumDefaultsForUnknownValues(): void
    {
        $restored = ShopPluginError::fromQueuePayload([
            'host' => 'shop.test',
            'platform' => 'WooCommerce',
            'category' => 'totally_unknown',
            'severity' => 'totally_unknown',
            'context' => 'totally_unknown',
            'errorCode' => 'E001',
            'errorMessage' => 'err',
        ]);

        $this->assertSame(ErrorCategory::Other, $restored->category);
        $this->assertSame(ErrorSeverity::Error, $restored->severity);
        $this->assertSame(OperationContext::Unknown, $restored->context);
    }
}
