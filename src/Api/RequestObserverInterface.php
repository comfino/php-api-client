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
 * Observation hook for the request lifecycle.
 *
 * Replaces the removed `getRequest()` / `getResponse()` getters, which returned whatever the *previous* caller sent under concurrent tenants.
 * A host that wants per-tenant latency or error metrics implements this instead, with the tenant already available in the context.
 */
interface RequestObserverInterface
{
    /**
     * Called immediately before a request leaves for the transport.
     *
     * @param ApiContext $context Context the call is made in
     * @param RequestInterface $request The PSR-7 request about to be sent
     * @param int $attempt Attempt number, counting from 1
     */
    public function onRequest(ApiContext $context, RequestInterface $request, int $attempt): void;

    /**
     * Called after a response comes back, whatever its status code.
     *
     * @param ApiContext $context Context the call was made in
     * @param RequestInterface $request The request that was sent
     * @param ResponseInterface $response The response received
     * @param float $durationMs Wall-clock duration of the attempt, in milliseconds
     */
    public function onResponse(
        ApiContext $context,
        RequestInterface $request,
        ResponseInterface $response,
        float $durationMs
    ): void;

    /**
     * Called when an attempt fails without producing a response.
     *
     * @param ApiContext $context Context the call was made in
     * @param RequestInterface $request The request that was sent
     * @param Throwable $error The failure
     * @param int $attempt Attempt number, counting from 1
     */
    public function onFailure(ApiContext $context, RequestInterface $request, Throwable $error, int $attempt): void;
}
