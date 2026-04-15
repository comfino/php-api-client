<?php

/**
 * Comfino PHP API client
 *
 * Backend routines for communication with the Comfino payment gateway REST API.
 *
 * @package Comfino\Api\Response
 * @author Artur Kozubski <akozubski@comperia.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-api-client
 */

declare(strict_types=1);

namespace Comfino\Api\Response;

use Comfino\Api\Exception\ConnectionTimeout;
use Comfino\Api\Exception\RequestValidationError;
use Comfino\Api\HttpErrorExceptionInterface;
use Comfino\Api\Request;
use Comfino\Api\SerializerInterface;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * Response from the API for the order validation request.
 */
class ValidateOrder extends Base
{
    /** @var string Unique track ID associated with every API request. */
    public readonly string $trackId;
    /** @var bool Success flag. */
    public readonly bool $success;
    /** @var int HTTP status code. */
    public readonly int $httpStatusCode;
    /** @var string[] List of validation errors as pairs [fieldName => errorMessage]. */
    public readonly array $errors;
    /** @var bool Low level network error. */
    public readonly bool $isNetworkError;
    /** @var int Error code. */
    public readonly int $errorCode;
    /** @var bool Connection timeout error. */
    public readonly bool $isTimeout;
    /** @var int Connection trial counter. */
    public readonly int $connectAttemptIdx;
    /** @var int Last connection timeout in seconds. */
    public readonly int $connectionTimeout;
    /** @var int Last transfer timeout in seconds. */
    public readonly int $transferTimeout;

    public function __construct(
        Request $request,
        ?ResponseInterface $response,
        SerializerInterface $serializer,
        ?Throwable $exception = null
    ) {
        parent::__construct($request, $response, $serializer, $exception);

        $this->trackId = ($this->headers['Comfino-Track-Id'] ?? '');
        $this->success = ($exception === null);

        $httpStatusCode = $response?->getStatusCode() ?? 0;
        $errors = [];
        $isNetworkError = false;
        $errorCode = 0;
        $isTimeout = false;
        $connectAttemptIdx = 0;
        $connectionTimeout = 0;
        $transferTimeout = 0;

        if ($exception !== null) {
            if ($exception instanceof HttpErrorExceptionInterface) {
                // Handle HTTP error exception.
                $httpStatusCode = $exception->getStatusCode();

                if ($exception instanceof RequestValidationError) {
                    if (is_array($deserializedResponseBody = $exception->getDeserializedResponseBody())) {
                        if (isset($deserializedResponseBody['errors'])) {
                            $errors = $deserializedResponseBody['errors'];
                        } elseif (isset($deserializedResponseBody['message'])) {
                            $errors = [$deserializedResponseBody['message']];
                        } elseif ($exception->getCode() >= 400) {
                            $errors = $deserializedResponseBody;
                        } else {
                            $errors = [$exception->getMessage()];
                        }
                    } else {
                        $errors = [$exception->getMessage()];
                    }
                }

                if ($exception instanceof ConnectionTimeout) {
                    // Handle connection timeout exception.
                    $isTimeout = true;
                    $connectAttemptIdx = $exception->getConnectAttemptIdx();
                    $connectionTimeout = $exception->getConnectionTimeout();
                    $transferTimeout = $exception->getTransferTimeout();
                }
            } elseif ($exception instanceof ClientExceptionInterface) {
                // Handle client exception - network error.
                $errors = [$exception->getMessage()];
                $errorCode = $exception->getCode();

                if ($exception instanceof NetworkExceptionInterface) {
                    $isNetworkError = true;
                }
            } else {
                $errors = [$exception->getMessage()];
                $errorCode = $exception->getCode();
            }
        }

        $this->httpStatusCode = $httpStatusCode;
        $this->errors = $errors;
        $this->isNetworkError = $isNetworkError;
        $this->errorCode = $errorCode;
        $this->isTimeout = $isTimeout;
        $this->connectAttemptIdx = $connectAttemptIdx;
        $this->connectionTimeout = $connectionTimeout;
        $this->transferTimeout = $transferTimeout;
    }
}
