<?php

/**
 * ComfinoPay PHP API client
 *
 * Backend routines for communication with the ComfinoPay payment gateway REST API.
 *
 * @package Comfino\Api
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-api-client
 */

declare(strict_types=1);

namespace Comfino\Api\Exception;

use Comfino\Api\HttpErrorDetailsInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Throwable;

/**
 * The ComfinoPay API answered 429 Too Many Requests.
 *
 * A transient condition, so it is a {@see RuntimeException} like {@see ServiceUnavailable}, not a
 * {@see RequestValidationError}: a `catch (RequestValidationError)` that means "the API refused this data" no longer
 * catches it (changed in 4.0.0). Not the same as {@see RateLimitExceeded}, which is raised by this client's own outbound
 * limiter. The retry policy does not yet honor {@see getRetryAfter()}; hosts that want to can read it.
 */
class TooManyRequests extends RuntimeException implements HttpErrorDetailsInterface
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
     * @param int|null $retryAfter Seconds to wait, from the `Retry-After` response header
     */
    public function __construct(
        string $message = '',
        int $code = 0,
        ?Throwable $previous = null,
        private string $url = '',
        private string $requestBody = '',
        private string $responseBody = '',
        private readonly string|int|float|bool|array|null $deserializedResponseBody = null,
        private readonly ?ResponseInterface $response = null,
        private readonly ?int $retryAfter = null
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

    public function getResponse(): ?ResponseInterface
    {
        return $this->response;
    }

    /**
     * Seconds from the `Retry-After` header (delta-seconds or HTTP date), or null when it is absent or unparseable.
     */
    public function getRetryAfter(): ?int
    {
        return $this->retryAfter;
    }

    public function getStatusCode(): int
    {
        return 429;
    }
}
