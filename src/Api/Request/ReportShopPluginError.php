<?php

/**
 * Comfino PHP API client
 *
 * Backend routines for communication with the Comfino payment gateway REST API.
 *
 * @package Comfino\Api\Request
 * @author Artur Kozubski <akozubski@comperia.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-api-client
 */

declare(strict_types=1);

namespace Comfino\Api\Request;

use Comfino\Api\Dto\Plugin\ShopPluginError;
use Comfino\Api\Exception\RequestValidationError;
use Comfino\Api\Request;
use Comfino\Auth\ExceptionSanitizer;

/**
 * Shop payment plugin error reporting request.
 */
class ReportShopPluginError extends Request
{
    /** Minimum acceptable length for the HMAC key. */
    private const MIN_HASH_KEY_LENGTH = 16;

    /** Maximum length of the error_message field (characters). */
    private const MAX_ERROR_MESSAGE_LENGTH = 2000;

    /** Maximum length of the api_request_url field (characters). */
    private const MAX_URL_LENGTH = 2048;

    /** Maximum size of api_request / api_response fields (bytes, ~16 KB). */
    private const MAX_API_PAYLOAD_LENGTH = 16384;

    /** Maximum size of the stack_trace field (bytes, ~8 KB). */
    private const MAX_STACK_TRACE_LENGTH = 8192;

    /** Maximum number of entries accepted from the environment array. */
    private const MAX_ENVIRONMENT_ENTRIES = 50;

    /** Maximum length of a single environment value (characters). */
    private const MAX_ENVIRONMENT_VALUE_LENGTH = 200;

    /**
     * Key patterns treated as sensitive inside the environment array.
     * Matching keys are replaced with '[REDACTED]'.
     */
    private const SENSITIVE_ENV_PATTERNS = [
        '/password/i',
        '/passwd/i',
        '/pwd/i',
        '/secret/i',
        '/token/i',
        '/api[_\-]?key/i',
        '/auth/i',
        '/credential/i',
        '/private/i',
        '/cert/i',
        '/salt/i',
        '/hash/i',
    ];

    public function __construct(private readonly ShopPluginError $shopPluginError, private readonly string $hashKey)
    {
        $this->setRequestMethod('POST');
        $this->setApiEndpointPath('log-plugin-error');
    }

    protected function prepareRequestBody(): ?array
    {
        $errorDetailsArray = [
            'host' => $this->shopPluginError->host,
            'platform' => $this->shopPluginError->platform,
            'environment' => self::sanitizeEnvironment($this->shopPluginError->environment),
            'error_code' => $this->shopPluginError->errorCode,
            'error_message' => self::filterPaths(
                self::truncate($this->shopPluginError->errorMessage, self::MAX_ERROR_MESSAGE_LENGTH)
            ),
            'api_request_url' => $this->shopPluginError->apiRequestUrl !== null
                ? self::truncate($this->shopPluginError->apiRequestUrl, self::MAX_URL_LENGTH)
                : null,
            'api_request' => $this->shopPluginError->apiRequest !== null
                ? self::truncate(
                    self::sanitizeJsonPayload($this->shopPluginError->apiRequest),
                    self::MAX_API_PAYLOAD_LENGTH
                )
                : null,
            'api_response' => $this->shopPluginError->apiResponse !== null
                ? self::truncate(
                    self::sanitizeJsonPayload($this->shopPluginError->apiResponse),
                    self::MAX_API_PAYLOAD_LENGTH
                )
                : null,
            'stack_trace' => $this->shopPluginError->stackTrace !== null
                ? self::filterStackTracePaths(
                    self::truncate($this->shopPluginError->stackTrace, self::MAX_STACK_TRACE_LENGTH)
                )
                : null,
        ];

        if (strlen($this->hashKey) < self::MIN_HASH_KEY_LENGTH) {
            throw new RequestValidationError(
                sprintf('Hash key must be at least %d characters long.', self::MIN_HASH_KEY_LENGTH)
            );
        }

        if (($errorDetails = gzcompress($this->serializer->serialize($errorDetailsArray), 4)) === false) {
            throw new RequestValidationError('Error report preparation failed.');
        }

        $encodedErrorDetails = base64_encode($errorDetails);
        $timestamp = time();

        return [
            'error_details' => $encodedErrorDetails,
            'timestamp' => $timestamp,
            'hash' => hash_hmac('sha3-256', $encodedErrorDetails . $timestamp, $this->hashKey),
        ];
    }

    /**
     * Truncates a string to $maxLength, appending '...' when cut.
     */
    private static function truncate(string $value, int $maxLength): string
    {
        if (strlen($value) <= $maxLength) {
            return $value;
        }

        return substr($value, 0, $maxLength - 3) . '...';
    }

    /**
     * Sanitizes a JSON payload by redacting PII fields.
     * Falls back to the original string when the payload is not valid JSON.
     */
    private static function sanitizeJsonPayload(string $payload): string
    {
        try {
            return ExceptionSanitizer::sanitizeBody($payload);
        } catch (\JsonException) {
            return $payload;
        }
    }

    /**
     * Removes sensitive keys, coerces values to strings, and limits the size of
     * the environment array before it is included in the report.
     *
     * @param array<array-key, mixed> $environment
     *
     * @return array<string, string>
     */
    private static function sanitizeEnvironment(array $environment): array
    {
        $sanitized = [];

        foreach ($environment as $key => $value) {
            if (count($sanitized) >= self::MAX_ENVIRONMENT_ENTRIES) {
                break;
            }

            if (!is_string($key)) {
                continue;
            }

            foreach (self::SENSITIVE_ENV_PATTERNS as $pattern) {
                if (preg_match($pattern, $key)) {
                    $sanitized[$key] = '[REDACTED]';

                    continue 2;
                }
            }

            if (is_array($value) || is_object($value)) {
                $sanitized[$key] = '[COMPLEX]';
            } else {
                $sanitized[$key] = self::truncate((string) $value, self::MAX_ENVIRONMENT_VALUE_LENGTH);
            }
        }

        return $sanitized;
    }

    /**
     * Strips absolute filesystem paths from PHP stack trace frames, keeping only the base filename.
     *
     * Before: #0 /var/www/html/shop/plugins/comfino/src/Foo.php(42): Bar->baz()
     * After: #0 Foo.php(42): Bar->baz()
     */
    private static function filterStackTracePaths(string $stackTrace): string
    {
        return preg_replace_callback(
            '/^(#\d+\s+)([^(]+)(\(\d+\))/mu',
            static function (array $matches): string {
                $path = rtrim($matches[2]);

                if (str_contains($path, '/') || str_contains($path, '\\')) {
                    return $matches[1] . basename(str_replace('\\', '/', $path)) . $matches[3];
                }

                return $matches[0];
            },
            $stackTrace
        ) ?? $stackTrace;
    }

    /**
     * Strips absolute filesystem paths from arbitrary text (e.g., error messages), keeping only the base filename.
     *
     * Before: Error E_PARSE in /var/www/html/shop/plugins/comfino/src/Foo.php:42
     * After: Error E_PARSE in Foo.php:42
     *
     * URL path segments (preceded by a word char, ':', or '/') are left intact.
     */
    private static function filterPaths(string $text): string
    {
        return preg_replace('~(?<![:/\w])(?:/[^/\s:()]+)+/([^/\s:()]+)~u', '$1', $text) ?? $text;
    }
}
