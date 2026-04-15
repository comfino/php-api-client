<?php

/**
 * Comfino PHP API client
 *
 * Backend routines for communication with the Comfino payment gateway REST API.
 *
 * @package Comfino\Tests\Unit\Auth
 * @author Artur Kozubski <akozubski@comperia.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
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
