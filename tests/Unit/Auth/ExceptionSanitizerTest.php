<?php

/**
 * ComfinoPay PHP API client
 *
 * Backend routines for communication with the ComfinoPay payment gateway REST API.
 *
 * @package Comfino\Tests\Unit\Auth
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-api-client
 */

declare(strict_types=1);

namespace Comfino\Tests\Unit\Auth;

use Comfino\Auth\ExceptionSanitizer;
use JsonException;
use PHPUnit\Framework\TestCase;

final class ExceptionSanitizerTest extends TestCase
{
    /**
     * @throws JsonException
     */
    public function testRedactsPiiFieldsInJson(): void
    {
        $body = '{"firstName":"John","lastName":"Doe","email":"john@example.com","amount":1000}';
        $sanitized = ExceptionSanitizer::sanitizeBody($body);
        $data = json_decode($sanitized, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('[REDACTED]', $data['firstName']);
        $this->assertSame('[REDACTED]', $data['lastName']);
        $this->assertSame('[REDACTED]', $data['email']);
        $this->assertSame(1000, $data['amount']);
    }

    /**
     * @throws JsonException
     */
    public function testRedactsAllPiiFields(): void
    {
        $body = json_encode([
            'firstName' => 'John',
            'lastName' => 'Doe',
            'email' => 'john@example.com',
            'phone' => '123456789',
            'taxId' => '1234567890',
            'street' => 'Main St',
            'buildingNumber' => '1',
            'apartmentNumber' => '2A',
            'postalCode' => '00-000',
            'city' => 'Warsaw',
        ], JSON_THROW_ON_ERROR);

        $sanitized = ExceptionSanitizer::sanitizeBody($body);
        $data = json_decode($sanitized, true, 512, JSON_THROW_ON_ERROR);
        $fields = [
            'firstName', 'lastName', 'email', 'phone', 'taxId', 'street',
            'buildingNumber', 'apartmentNumber', 'postalCode', 'city',
        ];

        foreach ($fields as $field) {
            $this->assertSame('[REDACTED]', $data[$field], "Field '$field' should be redacted.");
        }
    }

    /**
     * The redaction list only works if it names the fields the library actually sends. `phone` was listed while every
     * request the library builds sends `phoneNumber`, so phone numbers passed straight through a step that looked like
     * it covered them - including in the error reports sent to ComfinoPay's own logging endpoint.
     *
     * @dataProvider wireFieldProvider
     *
     * @throws JsonException
     */
    public function testRedactsTheFieldNamesTheLibraryActuallySends(string $field, mixed $value): void
    {
        $sanitized = ExceptionSanitizer::sanitizeBody(json_encode([$field => $value], JSON_THROW_ON_ERROR));
        $data = json_decode($sanitized, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('[REDACTED]', $data[$field], "Field '$field' reaches the wire and must be redacted.");
    }

    /** @return array<string, array{string, mixed}> */
    public static function wireFieldProvider(): array
    {
        return [
            // CreateOrder customer block
            'phoneNumber' => ['phoneNumber', '500000000'],
            'ip' => ['ip', '203.0.113.7'],
            // CreateOrder payment details
            'accountNumber' => ['accountNumber', 'PL61109010140000071219812874'],
            'transferTitle' => ['transferTitle', 'Order 123 Jan Kowalski'],
            // UserRegistration contact details
            'contactName' => ['contactName', 'Jan Kowalski'],
            'contactEmail' => ['contactEmail', 'jan@example.com'],
            'contactPhone' => ['contactPhone', '500000000'],
        ];
    }

    // -------------------------------------------------------------------------
    // sanitizeExceptionBody - the fail-closed variant
    // -------------------------------------------------------------------------

    /**
     * @throws JsonException
     */
    public function testExceptionBodyRedactsPiiAndKeepsTheRest(): void
    {
        $body = json_encode([
            'orderId' => 'order-123',
            'customer' => ['firstName' => 'Jan', 'phoneNumber' => '500000000'],
        ], JSON_THROW_ON_ERROR);

        $sanitized = ExceptionSanitizer::sanitizeExceptionBody($body);

        $this->assertStringNotContainsString('Jan', $sanitized);
        $this->assertStringNotContainsString('500000000', $sanitized);
        $this->assertStringContainsString('order-123', $sanitized);
    }

    /**
     * Where {@see ExceptionSanitizer::sanitizeBody()} stays lenient so an upstream HTML error page can be reported
     * verbatim, the exception variant fails closed: a msgpack-serialized order request is not JSON, and "return it
     * unchanged" there means handing an unredacted customer record to the shop's log file.
     */
    public function testExceptionBodyFailsClosedOnANonJsonBody(): void
    {
        $binary = "\x82\xa9firstName\xa3Jan";

        $sanitized = ExceptionSanitizer::sanitizeExceptionBody($binary);

        $this->assertStringNotContainsString('Jan', $sanitized);
        $this->assertSame('[REDACTED: ' . strlen($binary) . ' bytes, not JSON]', $sanitized);
    }

    public function testExceptionBodyKeepsAnEmptyBodyEmpty(): void
    {
        $this->assertSame('', ExceptionSanitizer::sanitizeExceptionBody(''));
    }

    public function testExceptionBodyLeavesAScalarRootAlone(): void
    {
        // No field names to match, so nothing to redact and nothing to hide.
        $this->assertSame('true', ExceptionSanitizer::sanitizeExceptionBody('true'));
        $this->assertSame('"a-widget-key"', ExceptionSanitizer::sanitizeExceptionBody('"a-widget-key"'));
    }

    /**
     * @throws JsonException
     */
    public function testPreservesNonPiiFields(): void
    {
        $body = '{"orderId":"12345","amount":999,"status":"ACCEPTED","firstName":"John"}';
        $sanitized = ExceptionSanitizer::sanitizeBody($body);
        $data = json_decode($sanitized, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('12345', $data['orderId']);
        $this->assertSame(999, $data['amount']);
        $this->assertSame('ACCEPTED', $data['status']);
        $this->assertSame('[REDACTED]', $data['firstName']);
    }

    public function testHandlesInvalidJson(): void
    {
        $invalidJson = 'not valid json{';
        $result = ExceptionSanitizer::sanitizeBody($invalidJson);

        $this->assertSame($invalidJson, $result);
    }

    public function testHandlesEmptyJson(): void
    {
        $this->assertSame('[]', ExceptionSanitizer::sanitizeBody('{}'));
    }

    // -------------------------------------------------------------------------
    // Non-array JSON root values - must be returned unchanged
    // -------------------------------------------------------------------------

    /**
     * A JSON string at the root level (e.g., a plain-quoted value) is not an array and therefore cannot contain
     * PII fields - it is returned as-is.
     */
    public function testJsonStringRootIsReturnedUnchanged(): void
    {
        $json = '"just a string"';

        $this->assertSame($json, ExceptionSanitizer::sanitizeBody($json));
    }

    /**
     * A JSON number at the root level is returned as-is.
     */
    public function testJsonNumberRootIsReturnedUnchanged(): void
    {
        $json = '42';

        $this->assertSame($json, ExceptionSanitizer::sanitizeBody($json));
    }

    /**
     * A JSON boolean at the root level is returned as-is.
     */
    public function testJsonBooleanRootIsReturnedUnchanged(): void
    {
        $this->assertSame('true', ExceptionSanitizer::sanitizeBody('true'));
        $this->assertSame('false', ExceptionSanitizer::sanitizeBody('false'));
    }

    /**
     * A JSON null at the root level is returned as-is.
     */
    public function testJsonNullRootIsReturnedUnchanged(): void
    {
        $json = 'null';

        $this->assertSame($json, ExceptionSanitizer::sanitizeBody($json));
    }

    /**
     * @throws JsonException
     */
    public function testHandlesNestedPiiFields(): void
    {
        $body = json_encode([
            'customer' => [
                'firstName' => 'Jane',
                'email' => 'jane@example.com',
            ],
            'orderId' => 'X123',
        ], JSON_THROW_ON_ERROR);

        $sanitized = ExceptionSanitizer::sanitizeBody($body);
        $data = json_decode($sanitized, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('[REDACTED]', $data['customer']['firstName']);
        $this->assertSame('[REDACTED]', $data['customer']['email']);
        $this->assertSame('X123', $data['orderId']);
    }
}
