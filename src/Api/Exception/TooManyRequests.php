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

use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * The ComfinoPay API answered 429 Too Many Requests.
 *
 * Extends {@see RequestValidationError} so an existing `catch (RequestValidationError)` keeps working. Not the same as
 * {@see RateLimitExceeded}, which is raised by this client's own outbound limiter. The retry policy does not yet honor
 * {@see getRetryAfter()}; hosts that want to can read it.
 */
class TooManyRequests extends RequestValidationError
{
    /**
     * @param int|null $retryAfter Seconds to wait, from the `Retry-After` response header
     */
    public function __construct(
        string $message = '',
        int $code = 0,
        ?Throwable $previous = null,
        string $url = '',
        string $requestBody = '',
        string $responseBody = '',
        string|int|float|bool|array|null $deserializedResponseBody = null,
        ?ResponseInterface $response = null,
        private readonly ?int $retryAfter = null
    ) {
        parent::__construct($message, $code, $previous, $url, $requestBody, $responseBody, $deserializedResponseBody, $response);
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
