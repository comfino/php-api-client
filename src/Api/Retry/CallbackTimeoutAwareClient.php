<?php

/**
 * Comfino PHP API client
 *
 * Backend routines for communication with the Comfino payment gateway REST API.
 *
 * @package Comfino\Api\Retry
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-api-client
 */

declare(strict_types=1);

namespace Comfino\Api\Retry;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Adapter that turns "transport built from a timeout config" into a {@see TimeoutConfigurableClientInterface}.
 *
 * Exists for transports that take their timeouts as construction or per-request options rather than through a setter -
 * Symfony's `Psr18Client` over an `HttpClientInterface` being the case that matters here. Such a client is not
 * timeout-aware in the old sense, so every escalated timeout this library computed was dropped on the floor, and the
 * retry policy was decorative wherever it was used. The host supplies one closure and the timeouts reach the wire:
 *
 *     new CallbackTimeoutAwareClient(
 *         fn (TimeoutConfig $t): ClientInterface => new Psr18Client(
 *             $httpClient->withOptions(['timeout' => $t->connectionTimeout, 'max_duration' => $t->transferTimeout])
 *         ),
 *         $defaultTimeouts
 *     );
 *
 * The closure should share the underlying connection pool across the instances it returns, so that a per-attempt
 * timeout does not cost a TLS handshake.
 */
final class CallbackTimeoutAwareClient implements TimeoutConfigurableClientInterface
{
    private ClientInterface $client;

    /**
     * @param callable $clientFactory fn (TimeoutConfig $timeouts): \Psr\Http\Client\ClientInterface
     * @param TimeoutConfig $timeouts Timeouts this instance sends with
     */
    public function __construct(
        private readonly mixed $clientFactory,
        private readonly TimeoutConfig $timeouts
    ) {
        $this->client = ($this->clientFactory)($this->timeouts);
    }

    /** @inheritDoc */
    public function withTimeouts(TimeoutConfig $timeouts): static
    {
        return $timeouts->equals($this->timeouts) ? $this : new static($this->clientFactory, $timeouts);
    }

    /** @inheritDoc */
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        return $this->client->sendRequest($request);
    }

    /**
     * Returns the timeouts this instance was built with.
     */
    public function getTimeouts(): TimeoutConfig
    {
        return $this->timeouts;
    }
}
