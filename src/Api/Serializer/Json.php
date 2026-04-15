<?php

/**
 * Comfino PHP API client
 *
 * Backend routines for communication with the Comfino payment gateway REST API.
 *
 * @package Comfino\Api\Serializer
 * @author Artur Kozubski <akozubski@comperia.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-api-client
 */

declare(strict_types=1);

namespace Comfino\Api\Serializer;

use Comfino\Api\Exception\RequestValidationError;
use Comfino\Api\Exception\ResponseValidationError;
use Comfino\Api\SerializerInterface;
use JsonException;

/**
 * JSON serializer implementation for API requests and responses.
 */
class Json implements SerializerInterface
{
    /**
     * Serializes request data structure to JSON format.
     *
     * @param mixed $requestData Request data structure to serialize
     *
     * @return string Serialized request data in JSON format
     *
     * @throws RequestValidationError
     */
    public function serialize(mixed $requestData): string
    {
        try {
            $serializedRequestBody = json_encode($requestData, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
        } catch (JsonException $e) {
            throw new RequestValidationError("Invalid request data: {$e->getMessage()}", 0, $e);
        }

        return $serializedRequestBody;
    }

    /**
     * Unserializes serialized response string from JSON format.
     *
     * @param string $responseBody Encoded response body to unserialize in JSON format
     *
     * @return mixed Unserialized response data
     *
     * @throws ResponseValidationError
     */
    public function unserialize(string $responseBody): mixed
    {
        try {
            $deserializedResponseBody = json_decode($responseBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new ResponseValidationError(
                "Invalid response data: {$e->getMessage()}",
                0,
                $e,
                responseBody: $responseBody
            );
        }

        return $deserializedResponseBody;
    }
}
