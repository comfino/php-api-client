<?php

/**
 * ComfinoPay PHP API client
 *
 * Backend routines for communication with the ComfinoPay payment gateway REST API.
 *
 * @package Comfino\Tests\Unit\Api\Serializer
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-api-client
 */

declare(strict_types=1);

namespace Comfino\Tests\Unit\Api\Serializer;

use Comfino\Api\Exception\RequestValidationError;
use Comfino\Api\Exception\ResponseValidationError;
use Comfino\Api\Serializer\Json;
use PHPUnit\Framework\TestCase;

final class JsonSerializerTest extends TestCase
{
    private Json $serializer;

    protected function setUp(): void
    {
        $this->serializer = new Json();
    }

    public function testGetContentTypeReturnsApplicationJson(): void
    {
        $this->assertSame('application/json', $this->serializer->getContentType());
    }

    // -------------------------------------------------------------------------
    // serialize
    // -------------------------------------------------------------------------

    public function testSerializeArrayToJson(): void
    {
        $this->assertSame('{"key":"value","num":42}', $this->serializer->serialize(['key' => 'value', 'num' => 42]));
    }

    public function testSerializePreservesZeroFraction(): void
    {
        // JSON_PRESERVE_ZERO_FRACTION: floats like 1.0 must not become 1
        $result = $this->serializer->serialize(['rrso' => 0.0, 'rate' => 1.0]);

        $this->assertStringContainsString('"rrso":0.0', $result);
        $this->assertStringContainsString('"rate":1.0', $result);
    }

    public function testSerializeThrowsRequestValidationErrorOnUnencodable(): void
    {
        $this->expectException(RequestValidationError::class);

        // NAN is not JSON-encodable with JSON_THROW_ON_ERROR.
        $this->serializer->serialize(['value' => NAN]);
    }

    // -------------------------------------------------------------------------
    // unserialize
    // -------------------------------------------------------------------------

    public function testUnserializeValidJsonToArray(): void
    {
        $result = $this->serializer->unserialize('{"status":"CREATED","amount":1000}');

        $this->assertIsArray($result);
        $this->assertSame('CREATED', $result['status']);
        $this->assertSame(1000, $result['amount']);
    }

    public function testUnserializeJsonBooleanToPhpBool(): void
    {
        $this->assertTrue($this->serializer->unserialize('true'));
        $this->assertFalse($this->serializer->unserialize('false'));
    }

    public function testUnserializeInvalidJsonThrowsResponseValidationError(): void
    {
        $this->expectException(ResponseValidationError::class);

        $this->serializer->unserialize('{not valid json}');
    }
}
