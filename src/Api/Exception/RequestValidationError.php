<?php

/**
 * ComfinoPay PHP API client
 *
 * Backend routines for communication with the ComfinoPay payment gateway REST API.
 *
 * @package Comfino\Api\Exception
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-api-client
 */

declare(strict_types=1);

namespace Comfino\Api\Exception;

use Comfino\Api\HttpErrorDetailsInterface;
use LogicException;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * Exception thrown when a request validation error occurs.
 */
class RequestValidationError extends LogicException implements HttpErrorDetailsInterface
{
    use HttpErrorDetailsTrait;

    /**
     * @param string $message Exception message
     * @param int $code Exception code
     * @param Throwable|null $previous Previous exception
     * @param string $url Request URL
     * @param string $requestBody Request body
     * @param string $responseBody Response body
     * @param string|int|float|bool|array<string, mixed>|null $deserializedResponseBody Deserialized response body
     * @param ResponseInterface|null $response PSR-7 compatible HTTP response object
     */
    public function __construct(
        string $message = '',
        int $code = 0,
        ?Throwable $previous = null,
        private string $url = '',
        private string $requestBody = '',
        private string $responseBody = '',
        private string|int|float|bool|array|null $deserializedResponseBody = null,
        private ?ResponseInterface $response = null
    ) {
        parent::__construct($message, $code, $previous);
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    public function setUrl(string $url): void
    {
        $this->url = $url;
    }

    public function getRequestBody(): string
    {
        return $this->requestBody;
    }

    public function setRequestBody(string $requestBody): void
    {
        $this->requestBody = $requestBody;
    }

    public function getResponseBody(): string
    {
        return $this->responseBody;
    }

    public function setResponseBody(string $responseBody): void
    {
        $this->responseBody = $responseBody;
    }

    /** @return string|int|float|bool|array<string, mixed>|null */
    public function getDeserializedResponseBody(): string|int|float|bool|array|null
    {
        return $this->deserializedResponseBody;
    }

    /** @param string|int|float|bool|array<string, mixed>|null $deserializedResponseBody */
    public function setDeserializedResponseBody(string|int|float|bool|array|null $deserializedResponseBody): void
    {
        $this->deserializedResponseBody = $deserializedResponseBody;
    }

    /**
     * Field-level validation errors the API reported, normalized to field name => list of messages.
     *
     * The stable input for customer-facing messages: build a fixed, translated message per field instead of rendering
     * {@see getMessage()}, which quotes the API text and is meant for logs. A scalar becomes a one-element list and nested
     * lists are flattened. Errors that name no field (`{"errors": ["a"]}`) are listed under the empty field name. Empty
     * when the body carried no `errors` map.
     *
     * @return array<string, list<string>>
     */
    public function getFieldErrors(): array
    {
        $errors = is_array($this->deserializedResponseBody) ? ($this->deserializedResponseBody['errors'] ?? null) : null;

        if (!is_array($errors)) {
            return [];
        }

        $fieldErrors = [];

        foreach ($errors as $field => $messages) {
            $fieldName = is_string($field) ? $field : '';
            $fieldErrors[$fieldName] = array_merge($fieldErrors[$fieldName] ?? [], self::flattenMessages($messages));
        }

        return $fieldErrors;
    }

    public function getResponse(): ?ResponseInterface
    {
        return $this->response;
    }

    public function setResponse(ResponseInterface $response): void
    {
        $this->response = $response;
    }

    public function getStatusCode(): int
    {
        return 400;
    }

    /**
     * @return list<string>
     */
    private static function flattenMessages(mixed $messages): array
    {
        if (is_array($messages)) {
            return array_merge([], ...array_map(static fn (mixed $item): array => self::flattenMessages($item), array_values($messages)));
        }

        if (is_string($messages)) {
            return [$messages];
        }

        if ($messages === null || is_bool($messages) || is_int($messages) || is_float($messages)) {
            return [var_export($messages, true)];
        }

        return [get_debug_type($messages)];
    }
}
