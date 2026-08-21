<?php

/**
 * Comfino PHP API client
 *
 * Backend routines for communication with the Comfino payment gateway REST API.
 *
 * @package Comfino\Api
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-api-client
 */

declare(strict_types=1);

namespace Comfino\Api;

use Comfino\Api\Dto\Plugin\ShopEnvironmentReport;
use Comfino\Api\Dto\Plugin\ShopPluginError;
use Comfino\Api\Exception\ConnectionTimeout;
use Comfino\Api\Retry\RetryExecutor;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface as HttpClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Throwable;

/**
 * Single-tenant Comfino API client with optional retry support.
 *
 * The surface the four existing plugins are written against: one instance, one merchant, credentials held as mutable
 * state. Adds the four fire-and-forget notification methods to {@see AbstractClient} and supplies the
 * {@see RetryExecutor} that the {@see SharedClient} underneath sends every request through, so a spent retry budget
 * still surfaces as the {@see ConnectionTimeout} every plugin's error handling is written against.
 *
 * For a host serving many merchants from one process, construct a {@see SharedClient} instead and pass an
 * {@see ApiContext} per call - or {@see SharedClient::bind()} to get this surface back, one binding per tenant.
 */
class Client extends AbstractClient
{
    public const CLIENT_VERSION = '3.0.0';

    public function __construct(
        HttpClientInterface $httpClient,
        RequestFactoryInterface $requestFactory,
        StreamFactoryInterface $streamFactory,
        ?string $apiKey,
        int $apiVersion = 1,
        ?SerializerInterface $serializer = null,
        protected readonly ?RetryExecutor $retryExecutor = null
    ) {
        parent::__construct($httpClient, $requestFactory, $streamFactory, $apiKey, $apiVersion, $serializer);
    }

    /**
     * Sends a logged payment plugin error to the API.
     *
     * Throws on any delivery failure so the caller (or the outbound request queue) can classify
     * whether to retry, drop, or fall back. Fire-and-forget callers must wrap in try/catch.
     *
     * @throws ConnectionTimeout On network timeout
     * @throws HttpErrorExceptionInterface On HTTP 4xx/5xx responses
     * @throws ClientExceptionInterface On PSR-18 transport errors
     * @throws Throwable On any other transport-level failure not covered above
     */
    public function sendLoggedError(ShopPluginError $shopPluginError): void
    {
        $this->boundClient()->sendLoggedError($shopPluginError);
    }

    /**
     * Notifies the API that a shop payment plugin has been removed.
     *
     * @return bool True if the removal notification was successfully sent, false otherwise
     */
    public function notifyPluginRemoval(): bool
    {
        return $this->boundClient()->notifyPluginRemoval();
    }

    /**
     * Notifies the API that a shop abandoned cart has been detected.
     *
     * @param string $type Type of abandoned cart event
     *
     * @return bool True if the notification was successful, false otherwise
     */
    public function notifyAbandonedCart(string $type): bool
    {
        return $this->boundClient()->notifyAbandonedCart($type);
    }

    /**
     * Reports structured shop environment to the API server-to-server.
     *
     * Mirrors the fire-and-forget contract of {@see sendLoggedError()} / {@see notifyPluginRemoval()}: any transport,
     * validation, or server error is swallowed and surfaced as a `false` return so that paywall / widget functionality
     * is never blocked by environment reporting failures.
     *
     * @param ShopEnvironmentReport $report Structured environment report
     *
     * @return bool True if the report was successfully accepted, false otherwise
     */
    public function reportShopEnvironment(ShopEnvironmentReport $report): bool
    {
        return $this->boundClient()->reportShopEnvironment($report);
    }

    /**
     * Returns the retry executor supplied to the constructor.
     */
    protected function getRetryExecutor(): ?RetryExecutor
    {
        return $this->retryExecutor;
    }
}
