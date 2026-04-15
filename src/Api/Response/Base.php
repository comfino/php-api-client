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

use Comfino\Api\Exception\AccessDenied;
use Comfino\Api\Exception\AuthorizationError;
use Comfino\Api\Exception\RequestValidationError;
use Comfino\Api\Exception\ResponseValidationError;
use Comfino\Api\Exception\ServiceUnavailable;
use Comfino\Api\Request;
use Comfino\Api\Response;
use Comfino\Api\SerializerInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * Base class for Comfino API responses.
 */
class Base extends Response
{
    /**
     * @param Request $request Comfino API client request object associated with this response
     * @param ResponseInterface|null $response PSR-7 compatible HTTP response object
     * @param SerializerInterface $serializer Serializer/deserializer object for requests and responses body
     * @param Throwable|null $exception Exception object in case of validation or communication error
     *
     * @throws RequestValidationError
     * @throws ResponseValidationError
     * @throws AuthorizationError
     * @throws AccessDenied
     * @throws ServiceUnavailable
     */
    public function __construct(
        Request $request,
        ?ResponseInterface $response,
        SerializerInterface $serializer,
        ?Throwable $exception = null
    ) {
        $this->request = $request;
        $this->response = $response;
        $this->serializer = $serializer;
        $this->exception = $exception;

        $this->initFromPsrResponse();
    }

    /** @inheritDoc */
    protected function processResponseBody(array|string|int|float|bool|null $deserializedResponseBody): void
    {
    }

    /**
     * Checks if the response body is of the expected type.
     *
     * @param array<string, mixed>|string|bool|null $deserializedResponseBody Deserialized response body
     * @param string $expectedType Expected data type of the response body
     * @param string|null $fieldName Name of the field being validated, if applicable
     *
     * @return void
     *
     * @throws ResponseValidationError
     */
    protected function checkResponseType(
        array|string|int|float|bool|null $deserializedResponseBody,
        string $expectedType,
        ?string $fieldName = null
    ): void {
        if (gettype($deserializedResponseBody) !== $expectedType) {
            if ($expectedType === 'double' && is_int($deserializedResponseBody)) {
                return;
            }

            if ($fieldName !== null) {
                throw new ResponseValidationError(
                    "Invalid response field \"$fieldName\" data type: $expectedType expected."
                );
            }

            throw new ResponseValidationError("Invalid response data type: $expectedType expected.");
        }
    }

    /**
     * Checks if the response body contains all expected keys.
     *
     * @param array<string, mixed> $deserializedResponseBody Response body deserialized to an array
     * @param string[] $expectedKeys Expected keys to be present in the response body
     *
     * @return void
     *
     * @throws ResponseValidationError
     */
    protected function checkResponseStructure(array $deserializedResponseBody, array $expectedKeys): void
    {
        if (count($responseKeysDiff = array_diff($expectedKeys, array_keys($deserializedResponseBody))) > 0) {
            throw new ResponseValidationError(
                'Invalid response data structure: missing fields: ' . implode(', ', $responseKeysDiff)
            );
        }
    }
}
