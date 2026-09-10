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

namespace Comfino\Api;

use Comfino\Api\Exception\RequestValidationError;
use Comfino\Api\Exception\ResponseValidationError;

/**
 * Request/response serializer interface.
 */
interface SerializerInterface
{
    /**
     * Returns the MIME Content-Type produced and consumed by this serializer (e.g. 'application/json').
     */
    public function getContentType(): string;

    /**
     * Serializes request data structure.
     *
     * @param mixed $requestData Request data structure to serialize
     * @param int $flags Optional encoding flags merged with default flags (defaults to 0 - use defaults only).
     *
     * @return string Serialized request data
     *
     * @throws RequestValidationError
     */
    public function serialize(mixed $requestData, int $flags = 0): string;

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
