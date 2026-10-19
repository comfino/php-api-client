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

    private const ELLIPSIS = '…';

    /**
     * Credentials in free text, each replaced by the matching entry of the replacement list in {@see redactText()}: a `key=value`
     * pair (the key stays), a bearer token, and a 64-character hex string (a signature or a hashed secret).
     */
    private const SECRET_PATTERNS = [
        '/\b((?:api[-_ ]?key|token|secret|password|authorization|cr-signature|signature)["\']?\s*[=:]\s*["\']?(?:Bearer\s+)?)[^\s"\',;&]+/i',
        '/\bBearer\s+[A-Za-z0-9._~+\/\-]+=*/i',
        '/\b[0-9a-f]{64}\b/i',
    ];

    /** Placeholder for a body that could not be parsed, and therefore could not be selectively redacted. */
    private const UNPARSEABLE = '[REDACTED: %d bytes, not JSON]';

    /**
     * Returns the JSON field names this library treats as personal data, so other redaction layers (log processors)
     * hide exactly the same fields.
     *
     * @return list<string>
     */
    public static function personalDataFieldNames(): array
    {
        return self::PII_FIELDS;
    }

    /**
     * Masks e-mail addresses, phone numbers, IP addresses, credentials and signatures inside free text and leaves the rest intact.
     *
     * For exception messages and log lines, where {@see sanitizeExceptionBody()} would replace the whole text with a byte count. A phone
     * number is recognized when it starts with `+`, is written with spaces, dashes or parentheses (at least 9 digits), or is an
     * unseparated run of 11 to 15 digits; a bare 9 or 10 digit number cannot be told from an order ID and is left alone. Idempotent.
     *
     * @param string $text Free text, e.g. an exception message
     */
    public static function redactText(string $text): string
    {
        if ($text === '') {
            return $text;
        }

        $text = preg_replace(self::SECRET_PATTERNS, ['$1' . self::REDACTED, self::REDACTED, self::REDACTED], $text) ?? $text;
        $text = preg_replace('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', self::REDACTED, $text) ?? $text;
        $text = preg_replace_callback(
            '/(?<![\w.:])(?:\d{1,3}\.){3}\d{1,3}(?![\w.])|(?<![\w:])(?:[0-9a-f]{0,4}:){2,7}[0-9a-f]{0,4}(?![\w:])/i',
            static fn (array $m): string => filter_var($m[0], FILTER_VALIDATE_IP) !== false ? self::REDACTED : $m[0],
            $text
        ) ?? $text;

        return preg_replace_callback(
            '/(?<![\w.])(?:\+\d[\d ()\-]{7,}\d|\(?\d{2,4}\)?(?:[ \-]\d{2,4}){2,4}|\d{11,15})(?!\w)/',
            static fn (array $m): string => preg_match_all('/\d/', $m[0]) >= 9 ? self::REDACTED : $m[0],
            $text
        ) ?? $text;
    }

    /**
     * Cuts $text to at most $maxLength bytes on a UTF-8 character boundary, ending in an ellipsis when it was cut.
     *
     * @param string $text Text to shorten
     * @param int $maxLength Byte budget, ellipsis included
     */
    public static function truncate(string $text, int $maxLength): string
    {
        if ($maxLength <= 0) {
            return '';
        }

        if (strlen($text) <= $maxLength) {
            return $text;
        }

        $ellipsis = $maxLength >= strlen(self::ELLIPSIS) ? self::ELLIPSIS : '';
        $cut = substr($text, 0, $maxLength - strlen($ellipsis));

        // Drop the bytes of a character the cut split.
        while ($cut !== '' && preg_match('//u', $cut) !== 1) {
            $cut = substr($cut, 0, -1);
        }

        return $cut . $ellipsis;
    }

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
