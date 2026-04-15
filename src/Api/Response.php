<?php

/**
 * Comfino PHP API client
 *
 * Backend routines for communication with the Comfino payment gateway REST API.
 *
 * @package Comfino\Api
 * @author Artur Kozubski <akozubski@comperia.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-api-client
 */

declare(strict_types=1);

namespace Comfino\Api;

use Comfino\Api\Exception\AccessDenied;
use Comfino\Api\Exception\AuthorizationError;
use Comfino\Api\Exception\Conflict;
use Comfino\Api\Exception\Forbidden;
use Comfino\Api\Exception\MethodNotAllowed;
use Comfino\Api\Exception\NotFound;
use Comfino\Api\Exception\RequestValidationError;
use Comfino\Api\Exception\ResponseValidationError;
use Comfino\Api\Exception\ServiceUnavailable;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * Abstract base class for Comfino API responses.
 */
abstract class Response
{
    /** @var Request Comfino API client request object associated with this response */
    protected Request $request;
    /** @var ResponseInterface|null PSR-7 compatible HTTP response object */
    protected ?ResponseInterface $response;
    /** @var SerializerInterface Serializer/deserializer object for requests and responses body */
    protected SerializerInterface $serializer;
    /** @var Throwable|null Exception object in case of validation or communication error */
    protected ?Throwable $exception;
    /** @var string[] Extracted HTTP response headers */
    protected array $headers = [];

    /**
     * Returns response HTTP headers as an associative array ['headerName' => 'headerValue'].
     *
     * @return string[] Response HTTP headers
     */
    final public function getHeaders(): array
    {
        return $this->headers;
    }

    /**
     * Checks if the specified response HTTP header exists (case-insensitive).
     *
     * @param string $headerName Header name to check
     *
     * @return bool True if the header exists, false otherwise
     */
    final public function hasHeader(string $headerName): bool
    {
        if (isset($this->headers[$headerName])) {
            return true;
        }

        foreach ($this->headers as $responseHeaderName => $headerValue) {
            if (strcasecmp($responseHeaderName, $headerName) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Returns a specified response HTTP header (case-insensitive) or default value if it does not exist.
     *
     * @param string $headerName Header name to retrieve
     * @param string|null $defaultValue Default value to return if header is not found
     *
     * @return string|null Header value or default value if not found
     */
    final public function getHeader(string $headerName, ?string $defaultValue = null): ?string
    {
        if (isset($this->headers[$headerName])) {
            return $this->headers[$headerName];
        }

        foreach ($this->headers as $responseHeaderName => $headerValue) {
            if (strcasecmp($responseHeaderName, $headerName) === 0) {
                return $headerValue;
            }
        }

        return $defaultValue;
    }

    /**
     * Extracts API response data from input PSR-7 compatible HTTP response object.
     *
     * @return Response Comfino API client response object
     *
     * @throws RequestValidationError
     * @throws ResponseValidationError
     * @throws AuthorizationError
     * @throws AccessDenied
     * @throws ServiceUnavailable
     */
    final protected function initFromPsrResponse(): self
    {
        // Handle null response (network errors, connection failures, etc.).
        if ($this->response === null) {
            return $this;
        }

        $requestBody = ($this->request->getRequestBody() ?? '');

        $this->response->getBody()->rewind();
        $responseBody = $this->response->getBody()->getContents();

        $this->headers = [];

        foreach ($this->response->getHeaders() as $headerName => $headerValues) {
            $this->headers[$headerName] = end($headerValues);
        }

        if ($this->exception !== null) {
            // Exception already thrown - return without errors processing and exceptions throwing.
            return $this;
        }

        if (
            $this->response->hasHeader('Content-Type') &&
            str_contains($this->response->getHeader('Content-Type')[0], 'application/json')
        ) {
            try {
                $deserializedResponseBody = $this->deserializeResponseBody($responseBody, $this->serializer);
            } catch (ResponseValidationError $e) {
                $e->setUrl($this->request->getRequestUri());
                $e->setRequestBody($requestBody);
                $e->setResponseBody($responseBody);

                throw $e;
            }
        } else {
            $deserializedResponseBody = $responseBody;
        }

        $reasonPhrase = $this->response->getReasonPhrase();
        $statusCode = $this->response->getStatusCode();

        // 5xx status codes (guard re-checked intentionally to avoid exception loops when called after early return)
        if ($this->exception === null && $statusCode >= 500) { // @phpstan-ignore identical.alwaysTrue
            throw new ServiceUnavailable(
                "Comfino API service is unavailable: $reasonPhrase [$statusCode]",
                $statusCode,
                null,
                $this->request->getRequestUri(),
                $requestBody,
                $responseBody
            );
        }

        // 4xx status codes (guard re-checked intentionally to avoid exception loops when called after early return)
        if ($this->exception === null && $statusCode >= 400) { // @phpstan-ignore identical.alwaysTrue
            throw match ($statusCode) {
                400 => new RequestValidationError(
                    $this->getErrorMessage(
                        $statusCode,
                        $deserializedResponseBody,
                        "Invalid request data: $reasonPhrase [$statusCode]"
                    ),
                    $statusCode,
                    null,
                    $this->request->getRequestUri(),
                    $requestBody,
                    $responseBody,
                    $deserializedResponseBody,
                    $this->response
                ),
                401 => new AuthorizationError(
                    $this->getErrorMessage(
                        $statusCode,
                        $deserializedResponseBody,
                        "Invalid credentials: $reasonPhrase [$statusCode]"
                    ),
                    $statusCode,
                    null,
                    $this->request->getRequestUri(),
                    $requestBody
                ),
                403 => new Forbidden(
                    $this->getErrorMessage(
                        $statusCode,
                        $deserializedResponseBody,
                        "Access denied: $reasonPhrase [$statusCode]"
                    ),
                    $statusCode,
                    null,
                    $this->request->getRequestUri(),
                    $requestBody,
                    $responseBody
                ),
                404 => new NotFound(
                    $this->getErrorMessage(
                        $statusCode,
                        $deserializedResponseBody,
                        "Entity not found: $reasonPhrase [$statusCode]"
                    ),
                    $statusCode,
                    null,
                    $this->request->getRequestUri(),
                    $requestBody,
                    $responseBody
                ),
                405 => new MethodNotAllowed(
                    $this->getErrorMessage(
                        $statusCode,
                        $deserializedResponseBody,
                        "Method not allowed: $reasonPhrase [$statusCode]"
                    ),
                    $statusCode,
                    null,
                    $this->request->getRequestUri(),
                    $requestBody,
                    $responseBody
                ),
                409 => new Conflict(
                    $this->getErrorMessage(
                        $statusCode,
                        $deserializedResponseBody,
                        "Entity already exists: $reasonPhrase [$statusCode]"
                    ),
                    $statusCode,
                    null,
                    $this->request->getRequestUri(),
                    $requestBody,
                    $responseBody
                ),
                default => new RequestValidationError(
                    "Invalid request data: $reasonPhrase [$statusCode]",
                    $statusCode,
                    null,
                    $this->request->getRequestUri(),
                    $requestBody,
                    $responseBody,
                    $deserializedResponseBody,
                    $this->response
                )
            };
        }

        if (($errorMessage = $this->getErrorMessage($statusCode, $deserializedResponseBody)) !== null) {
            throw new RequestValidationError(
                $errorMessage,
                $statusCode,
                null,
                $this->request->getRequestUri(),
                $requestBody,
                $responseBody,
                $deserializedResponseBody,
                $this->response
            );
        }

        try {
            $this->processResponseBody($deserializedResponseBody);
        } catch (ResponseValidationError $e) {
            $e->setUrl($this->request->getRequestUri());
            $e->setRequestBody($requestBody);
            $e->setResponseBody($responseBody);

            throw $e;
        }

        return $this;
    }

    /**
     * Fills response object properties with data from the deserialized API response array.
     *
     * @param array<string, mixed>|string|bool|int|float|null $deserializedResponseBody
     *
     * @throws ResponseValidationError
     */
    abstract protected function processResponseBody(array|string|bool|int|float|null $deserializedResponseBody): void;

    /**
     * Deserializes API response body using the specified serializer.
     *
     * @return array<string, mixed>|string|bool|int|float|null
     *
     * @throws ResponseValidationError
     */
    private function deserializeResponseBody(
        string $responseBody,
        SerializerInterface $serializer
    ): array|string|bool|int|float|null {
        return !empty($responseBody) ? $serializer->unserialize($responseBody) : null;
    }

    /**
     * Retrieves an error message based on the provided status code and deserialized response body.
     *
     * @param int $statusCode The HTTP status code received from the API response
     * @param array<string, mixed>|string|bool|int|float|null $deserializedResponseBody The decoded response body from
     *                                                                                  the API response JSON string
     * @param string|null $defaultMessage The default error message to return if no specific errors are found in the
     *                                    response body
     *
     * @return string|null The constructed error message derived from the response body, or the default message if no
     *                     specific errors are identified
     */
    private function getErrorMessage(
        int $statusCode,
        array|string|bool|int|float|null $deserializedResponseBody,
        ?string $defaultMessage = null
    ): ?string {
        if (!is_array($deserializedResponseBody)) {
            return $defaultMessage;
        }

        $errorMessages = [];

        if (isset($deserializedResponseBody['errors'])) {
            $errorMessages = array_map(
                static fn (string $errorFieldName, string $errorMessage) => "$errorFieldName: $errorMessage",
                array_keys($deserializedResponseBody['errors']),
                array_values($deserializedResponseBody['errors'])
            );
        } elseif (isset($deserializedResponseBody['message'])) {
            $errorMessages = [$deserializedResponseBody['message']];
        } elseif ($statusCode >= 400) {
            foreach ($deserializedResponseBody as $errorFieldName => $errorMessage) {
                $errorMessages[] = "$errorFieldName: $errorMessage";
            }
        }

        return count($errorMessages) ? implode("\n", $errorMessages) : $defaultMessage;
    }
}
