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

use Comfino\Api\Dto\Plugin\ShopPluginError;
use Comfino\Api\Exception\ConnectionTimeout;
use Comfino\Api\Exception\RetryExhaustedException;
use Comfino\Api\Request\NotifyAbandonedCart;
use Comfino\Api\Request\NotifyShopPluginRemoval;
use Comfino\Api\Request\ReportShopPluginError;
use Comfino\Api\Response\Base as BaseApiResponse;
use Comfino\Api\Retry\RetryExecutor;
use Comfino\Api\Retry\TimeoutAwareClientInterface;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface as HttpClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Throwable;

/**
 * Comfino API client with optional retry support.
 *
 * Extends {@see AbstractClient} with three fire-and-forget notification methods ({@see sendLoggedError()},
 * {@see notifyPluginRemoval()}, {@see notifyAbandonedCart()}) and overrides {@see sendRequest()} to wrap every HTTP
 * call in a {@see RetryExecutor} when one is supplied.
 *
 * On retry the client optionally escalates connection/transfer timeouts for HTTP adapters that implement
 * {@see TimeoutAwareClientInterface} (e.g. the Magento HTTP client adapter). When all retry attempts are exhausted,
 * {@see RetryExhaustedException} is converted to {@see ConnectionTimeout} so that all platform plugins can handle it
 * uniformly without being aware of the retry internals.
 *
 * If no {@see RetryExecutor} is provided, requests are forwarded directly to the parent implementation with no retry
 * or timeout escalation.
 */
final class Client extends AbstractClient
{
    public const CLIENT_VERSION = '2.0.0';

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
     * @param ShopPluginError $shopPluginError The error object to send
     *
     * @return bool True if the error was successfully sent, false otherwise
     */
    public function sendLoggedError(ShopPluginError $shopPluginError): bool
    {
        try {
            $request = new ReportShopPluginError($shopPluginError, $this->getUserAgent());

            new BaseApiResponse(
                $request,
                // API version 2 is used for logged errors.
                parent::sendRequest($request->setSerializer($this->serializer), 2),
                $this->serializer
            );
        } catch (Throwable) {
            return false;
        }

        return true;
    }

    /**
     * Notifies the API that a shop payment plugin has been removed.
     *
     * @return bool True if the removal notification was successfully sent, false otherwise
     */
    public function notifyPluginRemoval(): bool
    {
        try {
            parent::sendRequest((new NotifyShopPluginRemoval())->setSerializer($this->serializer));
        } catch (Throwable) {
            return false;
        }

        return true;
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
        try {
            parent::sendRequest((new NotifyAbandonedCart($type))->setSerializer($this->serializer));
        } catch (Throwable) {
            return false;
        }

        return true;
    }

    /**
     * Sends a request to the API using the configured HTTP client with retry logic.
     *
     * When a RetryExecutor is configured:
     * - Passes an onRetry callback that applies exponential backoff timeouts to the underlying HTTP client before each
     *   retry attempt, provided the client implements TimeoutAwareClientInterface (e.g., MagentoHttpClientAdapter).
     * - Converts RetryExhaustedException to ConnectionTimeout for backward compatibility with existing error handling
     *   code in all platform plugins.
     *
     * @throws Throwable
     * @throws ClientExceptionInterface
     * @throws ConnectionTimeout When all retry attempts are exhausted due to timeouts
     */
    protected function sendRequest(Request $request, ?int $apiVersion = null): ResponseInterface
    {
        if ($this->retryExecutor === null) {
            return parent::sendRequest($request, $apiVersion);
        }

        try {
            return $this->retryExecutor->execute(
                fn () => parent::sendRequest($request, $apiVersion),
                function (int $attempt, Throwable $exception): void {
                    /* If the HTTP transport supports dynamic timeout updates, escalate the timeouts before the next
                       attempt using the retry policy schedule. Without this the adapter would keep the initial timeout
                       on every retry. */
                    if ($this->httpClient instanceof TimeoutAwareClientInterface) {
                        $policy = $this->retryExecutor->getRetryPolicy();

                        $this->httpClient->updateTimeouts(
                            $policy->getConnectionTimeout($attempt + 1),
                            $policy->getTransferTimeout($attempt + 1)
                        );
                    }
                }
            );
        } catch (RetryExhaustedException $e) {
            /* Convert to ConnectionTimeout so all platform plugins (PrestaShop, WooCommerce, Magento) can handle it
               uniformly without knowing about RetryExhaustedException. */
            throw new ConnectionTimeout(
                $e->getMessage(),
                $e->getCode(),
                $e->getOriginalError(),
                $e->getAttemptCount(),
                $e->getLastTimeoutConfig()->connectionTimeout ?? $this->retryExecutor->getRetryPolicy()
                    ->getBaseConnectionTimeout(),
                $e->getLastTimeoutConfig()->transferTimeout ?? $this->retryExecutor->getRetryPolicy()
                    ->getBaseTransferTimeout(),
                $e->getRequestUri() ?? $request->getRequestUri() ?? '',
                $e->getRequestBody() ?? $request->getRequestBody() ?? ''
            );
        }
    }
}
