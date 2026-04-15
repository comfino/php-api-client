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

/**
 * Interface for HTTP error exceptions.
 */
interface HttpErrorExceptionInterface
{
    /**
     * Returns the URL of the request that resulted in the error.
     */
    public function getUrl(): string;

    /**
     * Returns the request body that resulted in the error.
     */
    public function getRequestBody(): string;

    /**
     * Sets the request body that resulted in the error.
     */
    public function setRequestBody(string $requestBody): void;

    /**
     * Returns the response body that resulted in the error.
     */
    public function getResponseBody(): string;

    /**
     * Sets the response body that resulted in the error.
     */
    public function setResponseBody(string $responseBody): void;

    /**
     * Returns the HTTP status code of the error response.
     */
    public function getStatusCode(): int;
}
