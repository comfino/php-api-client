<?php

/**
 * Comfino PHP API client
 *
 * Backend routines for communication with the Comfino payment gateway REST API.
 *
 * @package Comfino\Api\Serializer
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-api-client
 */

declare(strict_types=1);

namespace Comfino\Api\Serializer;

use Comfino\Api\Exception\RequestValidationError;
use Comfino\Api\Exception\ResponseValidationError;
use Comfino\Api\SerializerInterface;
use ErrorException;
use RuntimeException;
use Throwable;

/**
 * MessagePack serializer implementation for API requests and responses.
 *
 * Requires the ext-msgpack PHP extension.
 */
final class MsgPack implements SerializerInterface
{
    /**
     * @param array<string, mixed> $array
     */
    public static function __set_state(array $array): self
    {
        return new self();
    }

    public function __construct()
    {
        if (!extension_loaded('msgpack')) {
            throw new RuntimeException(
                'The msgpack PHP extension is required to use the MsgPack serializer. ' .
                'Install it via PECL: pecl install msgpack'
            );
        }
    }

    public function getContentType(): string
    {
        return 'application/msgpack';
    }

    /**
     * @throws RequestValidationError|ErrorException
     */
    public function serialize(mixed $requestData, int $flags = 0): string
    {
        set_error_handler(static function (int $errorNumber, string $errorMessage): never {
            throw new ErrorException($errorMessage, $errorNumber);
        });

        try {
            return msgpack_pack($requestData);
        } catch (Throwable $e) {
            throw new RequestValidationError("Invalid request data: {$e->getMessage()}", 0, $e);
        } finally {
            restore_error_handler();
        }
    }

    /**
     * @throws ResponseValidationError|ErrorException
     */
    public function unserialize(string $responseBody): mixed
    {
        set_error_handler(static function (int $errorNumber, string $errorMessage): never {
            throw new ErrorException($errorMessage, $errorNumber);
        });

        try {
            return msgpack_unpack($responseBody);
        } catch (Throwable $e) {
            throw new ResponseValidationError(
                "Invalid response data: {$e->getMessage()}",
                0,
                $e,
                responseBody: $responseBody
            );
        } finally {
            restore_error_handler();
        }
    }
}
