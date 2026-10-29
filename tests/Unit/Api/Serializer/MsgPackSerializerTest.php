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
use Comfino\Api\Serializer\Factory;
use Comfino\Api\Serializer\MsgPack;
use Comfino\Tests\Unit\Api\Stub\DeserializationGadget;
use PHPUnit\Framework\TestCase;

/**
 * @requires extension msgpack
 */
final class MsgPackSerializerTest extends TestCase
{
    private MsgPack $serializer;

    protected function setUp(): void
    {
        $this->serializer = new MsgPack();
    }

    // -------------------------------------------------------------------------
    // Object-deserialization hardening
    // -------------------------------------------------------------------------

    /**
     * With msgpack.php_only on - the extension default - msgpack_unpack() is an object-deserialization sink on a par
     * with unserialize(): it constructs whatever class the payload names and runs its magic methods. Since those fire
     * during the unpack, no check on the return value is early enough, so the serializer turns the setting off for the
     * duration of the call. A payload carrying an object must therefore never construct one.
     */
    public function testUnserializeNeverInstantiatesObjectsFromThePayload(): void
    {
        $packed = msgpack_pack(['payload' => new DeserializationGadget()]);

        DeserializationGadget::reset();

        try {
            $unpacked = $this->serializer->unserialize($packed);

            $this->assertIsArray($unpacked);
            $this->assertSame([], array_filter($unpacked, 'is_object'));
        } catch (ResponseValidationError) {
            // Failing closed on an object-bearing payload is an equally acceptable outcome.
        }

        $this->assertSame(0, DeserializationGadget::$constructed, 'Gadget magic method ran during unserialize().');
    }

    public function testUnserializeRestoresThePhpOnlyIniSetting(): void
    {
        $before = ini_get('msgpack.php_only');

        $this->serializer->unserialize(msgpack_pack(['a' => 1]));

        $this->assertSame($before, ini_get('msgpack.php_only'));
    }

    public function testPhpOnlyIsRestoredEvenWhenUnserializeFails(): void
    {
        $before = ini_get('msgpack.php_only');

        try {
            $this->serializer->unserialize("\xc1");
        } catch (\Throwable) {
            // The malformed payload is the point; the ini restore is what is under test.
        }

        $this->assertSame($before, ini_get('msgpack.php_only'));
    }

    public function testGetContentTypeReturnsApplicationMsgpack(): void
    {
        $this->assertSame('application/msgpack', $this->serializer->getContentType());
    }

    // -------------------------------------------------------------------------
    // serialize / unserialize round-trip
    // -------------------------------------------------------------------------

    public function testRoundTripArray(): void
    {
        $data = ['key' => 'value', 'num' => 42];
        $packed = $this->serializer->serialize($data);

        $this->assertNotEmpty($packed);
        $this->assertSame($data, $this->serializer->unserialize($packed));
    }

    public function testRoundTripNestedArray(): void
    {
        $data = ['order' => ['id' => 'ORD-1', 'amount' => 1000], 'status' => 'CREATED'];
        $packed = $this->serializer->serialize($data);

        $this->assertSame($data, $this->serializer->unserialize($packed));
    }

    public function testRoundTripNull(): void
    {
        $this->assertNull($this->serializer->unserialize($this->serializer->serialize(null)));
    }

    public function testRoundTripBool(): void
    {
        $this->assertTrue($this->serializer->unserialize($this->serializer->serialize(true)));
        $this->assertFalse($this->serializer->unserialize($this->serializer->serialize(false)));
    }

    public function testRoundTripInteger(): void
    {
        $this->assertSame(12345, $this->serializer->unserialize($this->serializer->serialize(12345)));
    }

    public function testSerializedOutputIsBinary(): void
    {
        // MsgPack output is not valid UTF-8 text in the general case.
        $this->assertNotSame('{"key":"value"}', $this->serializer->serialize(['key' => 'value']));
    }

    // -------------------------------------------------------------------------
    // Error handling
    // -------------------------------------------------------------------------

    public function testSerializeThrowsRequestValidationErrorForUnsupportedType(): void
    {
        $this->expectException(RequestValidationError::class);

        /* Resources cannot be packed by ext-msgpack; it emits a warning which the serializer converts to
           RequestValidationError via set_error_handler. */
        $resource = tmpfile();

        try {
            $this->serializer->serialize($resource);
        } finally {
            if (is_resource($resource)) {
                fclose($resource);
            }
        }
    }

    public function testUnserializeThrowsResponseValidationErrorForReservedByte(): void
    {
        $this->expectException(ResponseValidationError::class);

        // 0xc1 is the never-used reserved byte in MessagePack — always a parse error.
        $this->serializer->unserialize("\xc1");
    }

    public function testUnserializeThrowsResponseValidationErrorForTruncatedData(): void
    {
        $this->expectException(ResponseValidationError::class);

        // A fixmap header declaring 2 entries but with no actual entries following it.
        $this->serializer->unserialize("\x82\xa3foo");
    }

    /**
     * A msgpack body is binary, so there are no field names to redact selectively and the sanitizer fails closed rather
     * than attaching bytes it cannot vouch for. The length survives, which is most of what a body is read for in triage,
     * and the untouched PSR-7 response is still on the exception for anyone who needs the actual bytes.
     */
    public function testUnserializeExceptionRedactsAnUnparseableResponseBody(): void
    {
        $invalidBody = "\xc1";

        try {
            $this->serializer->unserialize($invalidBody);
            $this->fail('Expected ResponseValidationError was not thrown.');
        } catch (ResponseValidationError $e) {
            $this->assertStringNotContainsString($invalidBody, $e->getResponseBody());
            $this->assertSame('[REDACTED: 1 bytes, not JSON]', $e->getResponseBody());
        }
    }

    // -------------------------------------------------------------------------
    // Factory integration
    // -------------------------------------------------------------------------

    public function testFactoryResolvesToMsgPackSerializerForItsContentType(): void
    {
        $this->assertInstanceOf(MsgPack::class, (new Factory())->createFromContentType('application/msgpack'));
    }

    public function testFactorySupportsApplicationMsgpack(): void
    {
        $this->assertTrue((new Factory())->supports('application/msgpack'));
    }
}
