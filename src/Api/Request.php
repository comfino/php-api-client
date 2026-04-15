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

use Comfino\Api\Exception\RequestValidationError;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * API request abstraction.
 */
abstract class Request
{
    /** @var SerializerInterface Serializer/deserializer object for requests and responses body */
    protected SerializerInterface $serializer;
    /** @var string HTTP method */
    protected string $method;
    /** @var string API endpoint path */
    protected string $apiEndpointPath;
    /** @var array<string, string>|null HTTP request headers */
    protected ?array $requestHeaders = null;
    /** @var array<string, string>|null HTTP request query parameters */
    protected ?array $requestParams = null;
    /** @var string|null HTTP request URI */
    protected ?string $requestUri = null;
    /** @var string|null Request body serialized to JSON */
    protected ?string $requestBody = null;

    /**
     * Sets serializer used for request body serialization.
     */
    final public function setSerializer(SerializerInterface $serializer): self
    {
        $this->serializer = $serializer;

        return $this;
    }

    /**
     * Returns PSR-7 compatible HTTP request object.
     *
     * @param RequestFactoryInterface $requestFactory Request factory for creating PSR-7 request objects
     * @param StreamFactoryInterface $streamFactory Stream factory for creating PSR-7 stream objects
     * @param string $apiBaseUrl API base URL
     * @param int $apiVersion API version number
     *
     * @return RequestInterface PSR-7 compatible HTTP request object
     *
     * @throws RequestValidationError 1. Invalid request data: HTTP method undefined.
     *                                2. Invalid request data: API endpoint path undefined.
     */
    final public function getPsrRequest(
        RequestFactoryInterface $requestFactory,
        StreamFactoryInterface $streamFactory,
        string $apiBaseUrl,
        int $apiVersion
    ): RequestInterface {
        $this->requestUri = $this->getApiEndpointUri($apiBaseUrl, $apiVersion);

        if (empty($this->method)) {
            throw new RequestValidationError(
                'Invalid request data: HTTP method undefined.',
                0,
                null,
                $this->requestUri
            );
        }
        if (empty($this->apiEndpointPath)) {
            throw new RequestValidationError(
                'Invalid request data: API endpoint path undefined.',
                0,
                null,
                $this->requestUri
            );
        }

        $request = $requestFactory->createRequest($this->method, $this->requestUri);

        if (!empty($requestHeaders = $this->getRequestHeaders())) {
            foreach ($requestHeaders as $headerName => $headerValue) {
                $request = $request->withHeader($headerName, $headerValue);
            }
        }

        try {
            $this->requestBody = $this->serializeRequestBody();
        } catch (RequestValidationError $e) {
            $e->setUrl($this->requestUri);

            throw $e;
        }

        return $this->requestBody !== null
            ? $request->withBody($streamFactory->createStream($this->requestBody))
            : $request;
    }

    /**
     * Returns request URI with query parameters.
     *
     * @return string|null Request URI with query parameters
     */
    final public function getRequestUri(): ?string
    {
        return $this->requestUri;
    }

    /**
     * Returns request body serialized to JSON.
     *
     * @return string|null Request body serialized to JSON
     */
    final public function getRequestBody(): ?string
    {
        return $this->requestBody;
    }

    /**
     * Returns request body serialized to JSON.
     *
     * @return string Request body serialized to JSON
     *
     * @throws RequestValidationError
     */
    public function __toString(): string
    {
        return ($serializedBody = $this->serializeRequestBody()) !== null ? $serializedBody : '';
    }

    /**
     * Sets HTTP method.
     *
     * @param string $method HTTP method
     *
     * @return void
     */
    final protected function setRequestMethod(string $method): void
    {
        $this->method = strtoupper(trim($method));
    }

    /**
     * Sets API endpoint path.
     *
     * @param string $apiEndpointPath API endpoint path
     *
     * @return void
     */
    final protected function setApiEndpointPath(string $apiEndpointPath): void
    {
        $this->apiEndpointPath = trim($apiEndpointPath, " /\n\r\t\v\0");
    }

    /**
     * Sets HTTP request headers.
     *
     * @param string[] $requestHeaders HTTP request headers
     *
     * @return void
     */
    final protected function setRequestHeaders(array $requestHeaders): void
    {
        $this->requestHeaders = $requestHeaders;
    }

    /**
     * Sets request parameters.
     *
     * @param array<string, string|int|float|null> $requestParams Request parameters
     *
     * @return void
     */
    final protected function setRequestParams(array $requestParams): void
    {
        $this->requestParams = array_map(
            static function (string|int|float|null $requestParam): string {
                if ($requestParam === null) {
                    return '';
                }

                return (string) $requestParam;
            },
            $requestParams
        );
    }

    /**
     * Serializes request body to JSON.
     *
     * @return string|null Request body serialized to JSON
     *
     * @throws RequestValidationError
     */
    protected function serializeRequestBody(): ?string
    {
        return ($body = $this->prepareRequestBody()) !== null ? $this->serializer->serialize($body) : null;
    }

    /**
     * Returns API endpoint URI.
     *
     * @param string $apiBaseUrl API base URL
     * @param int $apiVersion API version
     *
     * @return string API endpoint URI with query parameters
     */
    protected function getApiEndpointUri(string $apiBaseUrl, int $apiVersion): string
    {
        $uri = implode('/', [trim($apiBaseUrl, " /\n\r\t\v\0"), "v$apiVersion", $this->apiEndpointPath]);

        if (!empty($this->requestParams)) {
            $uri .= ('?' . http_build_query($this->requestParams));
        }

        return $uri;
    }

    /**
     * Returns HTTP request headers.
     *
     * @return array<string, string>|null HTTP request headers
     */
    final protected function getRequestHeaders(): ?array
    {
        return $this->requestHeaders;
    }

    /**
     * Converts an API request object to the array which is ready for serialization.
     *
     * @return array<string, mixed>|null
     */
    abstract protected function prepareRequestBody(): ?array;
}
