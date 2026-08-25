<?php

/**
 * Comfino PHP API client
 *
 * Backend routines for communication with the Comfino payment gateway REST API.
 *
 * @package Comfino\Api\Exception
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-api-client
 */

declare(strict_types=1);

namespace Comfino\Api\Exception;

use Comfino\Api\HttpErrorExceptionInterface;
use RuntimeException;
use Throwable;

/**
 * Raised when the outbound rate limiter rejects a call and the call site chose {@see \Comfino\Api\OnLimit::Queue}.
 *
 * Distinct from {@see ServiceUnavailable} because it means something different operationally: nothing is wrong with
 * the far side, this process simply declined to spend the quota now. Carries the limiter's retry-after hint so the host
 * can schedule the call rather than guess.
 */
class RateLimitExceeded extends RuntimeException implements HttpErrorExceptionInterface
{
    /**
     * @param string $message Exception message
     * @param int $retryAfterMs Delay after which capacity is expected to be available, in milliseconds
     * @param Throwable|null $previous Previous exception
     * @param string $url Request URL
     * @param string $requestBody Request body
     * @param string $responseBody Response body
     */
    public function __construct(
        string $message = 'Outbound rate limit exceeded.',
        private readonly int $retryAfterMs = 0,
        ?Throwable $previous = null,
        private string $url = '',
        private string $requestBody = '',
        private string $responseBody = ''
    ) {
        parent::__construct($message, 429, $previous);
    }

    /**
     * Returns the delay after which capacity is expected to be available, in milliseconds.
     */
    public function getRetryAfterMs(): int
    {
        return $this->retryAfterMs;
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

    public function getStatusCode(): int
    {
        return 429;
    }
}
