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
use RuntimeException;
use Throwable;

/**
 * Connection timeout exception.
 */
class ConnectionTimeout extends RuntimeException implements HttpErrorExceptionInterface
{
    /**
     * @param string $message Exception message
     * @param int $code Exception code
     * @param Throwable|null $previous Previous exception
     * @param int $connectAttemptIdx Index of the connection attempt
     * @param int $connectionTimeout Connection timeout in seconds
     * @param int $transferTimeout Transfer timeout in seconds
     * @param string $url Request URL
     * @param string $requestBody Request body
     * @param string $responseBody Response body
     */
    public function __construct(
        string $message = '',
        int $code = 0,
        ?Throwable $previous = null,
        private readonly int $connectAttemptIdx = 1,
        private readonly int $connectionTimeout = 1,
        private readonly int $transferTimeout = 3,
        private string $url = '',
        private string $requestBody = '',
        private string $responseBody = ''
    ) {
        parent::__construct($message, $code, $previous);
    }

    public function getConnectAttemptIdx(): int
    {
        return $this->connectAttemptIdx;
    }

    public function getConnectionTimeout(): int
    {
        return $this->connectionTimeout;
    }

    public function getTransferTimeout(): int
    {
        return $this->transferTimeout;
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
        return 504;
    }
}
