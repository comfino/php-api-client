<?php

/**
 * ComfinoPay PHP API client
 *
 * Backend routines for communication with the ComfinoPay payment gateway REST API.
 *
 * @package Comfino\Auth
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-api-client
 */

declare(strict_types=1);

namespace Comfino\Auth;

use JsonException;

/**
 * Sanitizes sensitive information from API exceptions.
 */
final class ExceptionSanitizer
{
    private const REDACTED = '[REDACTED]';

    /**
     * Field names redacted wherever they appear, at any nesting depth.
     *
     * These are the names this library actually puts on the wire, which is the only thing that makes the list work: `phone`
     * was listed here while every request the library builds sends `phoneNumber` (see {@see \Comfino\Api\Request\CreateOrder}),
     * so customer phone numbers were passing through a redaction step that looked like it covered them. `phone` is kept anyway,
     * in case a host platform hands us a payload spelled that way.
     *
     * `ip` is personal data under GDPR; `accountNumber` is a bank account and `transferTitle` routinely carries the payer's
     * name. Product names and cart contents are deliberately *not* here - redacting them would leave a cart-mismatch error
     * report with nothing left to diagnose, and they are not personal data.
     */
    private const PII_FIELDS = [
        // Customer identity
        'firstName', 'lastName', 'email', 'phone', 'phoneNumber', 'taxId', 'ip',
        // Customer address
        'street', 'buildingNumber', 'apartmentNumber', 'postalCode', 'city',
        // Account registration contact details
        'contactName', 'contactEmail', 'contactPhone',
        // Payment details
        'accountNumber', 'transferTitle',
    ];

    /** Placeholder for a body that could not be parsed, and therefore could not be selectively redacted. */
    private const UNPARSEABLE = '[REDACTED: %d bytes, not JSON]';

    /**
     * Redacts sensitive information from the given JSON body.
     */
    public static function sanitizeBody(string $jsonBody): string
    {
        try {
            $data = json_decode($jsonBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $jsonBody;
        }

        if (!is_array($data)) {
            return $jsonBody;
        }

        try {
            return json_encode(self::redactArray($data), JSON_THROW_ON_ERROR);
        } catch (JsonException) { // @codeCoverageIgnore
            return $jsonBody; // @codeCoverageIgnore
        }
    }

    /**
     * Redacts a body that is about to be attached to an exception, failing closed when it cannot be parsed.
     *
     * @param string $body Raw request or response body
     *
     * @return string The body with PII redacted, or a placeholder when it is not parseable JSON
     */
    public static function sanitizeExceptionBody(string $body): string
    {
        if ($body === '') {
            return $body;
        }

        try {
            $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return sprintf(self::UNPARSEABLE, strlen($body));
        }

        if (!is_array($data)) {
            // A scalar root carries no field names to match, so there is nothing to redact and nothing to hide.
            return $body;
        }

        try {
            return json_encode(self::redactArray($data), JSON_THROW_ON_ERROR);
        } catch (JsonException) { // @codeCoverageIgnore
            return sprintf(self::UNPARSEABLE, strlen($body)); // @codeCoverageIgnore
        }
    }

    /**
     * Recursively redacts sensitive information from an array.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private static function redactArray(array $data): array
    {
        foreach ($data as $key => &$value) {
            if (in_array($key, self::PII_FIELDS, true)) {
                $value = self::REDACTED;
            } elseif (is_array($value)) {
                $value = self::redactArray($value);
            }
        }

        return $data;
    }
}
