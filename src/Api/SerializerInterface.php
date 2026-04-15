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
use Comfino\Api\Exception\ResponseValidationError;

/**
 * Request/response serializer interface.
 */
interface SerializerInterface
{
    /**
     * Serializes request data structure.
     *
     * @param mixed $requestData Request data structure to serialize
     *
     * @return string Serialized request data
     *
     * @throws RequestValidationError
     */
    public function serialize(mixed $requestData): string;

    /**
     * Unserializes serialized response string.
     *
     * @param string $responseBody Encoded response body to unserialize
     *
     * @return mixed Unserialized response data
     *
     * @throws ResponseValidationError
     */
    public function unserialize(string $responseBody): mixed;
}
