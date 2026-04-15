<?php

/**
 * Comfino PHP API client
 *
 * Backend routines for communication with the Comfino payment gateway REST API.
 *
 * @package Comfino\Api\Exception
 * @author Artur Kozubski <akozubski@comperia.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-api-client
 */

declare(strict_types=1);

namespace Comfino\Api\Exception;

use Comfino\Api\HttpErrorExceptionInterface;
use LogicException;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * Exception thrown when a request validation error occurs.
 */
class RequestValidationError extends LogicException implements HttpErrorExceptionInterface
{
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
}
