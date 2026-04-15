<?php

/**
 * Comfino PHP API client
 *
 * Backend routines for communication with the Comfino payment gateway REST API.
 *
 * @package Comfino\Auth
 * @author Artur Kozubski <akozubski@comperia.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
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
    private const PII_FIELDS = [
        'firstName', 'lastName', 'email', 'phone', 'taxId',
        'street', 'buildingNumber', 'apartmentNumber', 'postalCode', 'city',
    ];

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
