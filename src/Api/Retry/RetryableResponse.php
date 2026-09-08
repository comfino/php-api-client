<?php

/**
 * ComfinoPay PHP API client
 *
 * Backend routines for communication with the ComfinoPay payment gateway REST API.
 *
 * @package Comfino\Api\Retry
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-api-client
 */

declare(strict_types=1);

namespace Comfino\Api\Retry;

use Psr\Http\Message\ResponseInterface;
use RuntimeException;

/**
 * Internal carrier that lifts a retryable HTTP response into the retry loop.
 *
 * This library maps a status code onto a typed exception only when the `Response` object is built, which happens after
 * the transport call has already returned - so a 429 or a 503 would never reach the retry executor on its own. The
 * client wraps such a response in this exception inside the retried operation and unwraps it again once the retry
 * budget is spent, so the caller still sees the normal status-to-exception mapping rather than a retry artifact.
 *
 * Not part of the public API: never thrown out of a client method.
 *
 * @internal
 */
final class RetryableResponse extends RuntimeException
{
    public function __construct(private readonly ResponseInterface $response)
    {
        parent::__construct(sprintf('Retryable HTTP response received (status %d).', $response->getStatusCode()));
    }

    public function getResponse(): ResponseInterface
    {
        return $this->response;
    }
}
