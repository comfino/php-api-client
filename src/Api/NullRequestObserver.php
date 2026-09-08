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

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * Request observer that records nothing. The default, so the client never has to null-check.
 */
final class NullRequestObserver implements RequestObserverInterface
{
    /** @inheritDoc */
    public function onRequest(ApiContext $context, RequestInterface $request, int $attempt): void
    {
    }

    /** @inheritDoc */
    public function onResponse(
        ApiContext $context,
        RequestInterface $request,
        ResponseInterface $response,
        float $durationMs
    ): void {
    }

    /** @inheritDoc */
    public function onFailure(ApiContext $context, RequestInterface $request, Throwable $error, int $attempt): void
    {
    }
}
