<?php

/**
 * Comfino PHP API client
 *
 * Backend routines for communication with the Comfino payment gateway REST API.
 *
 * @package Comfino\Api\Retry
 * @author Artur Kozubski <akozubski@comperia.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-api-client
 */

declare(strict_types=1);

namespace Comfino\Api\Retry;

use Psr\Http\Client\ClientInterface;

/**
 * Optional extension of PSR-18 ClientInterface for HTTP clients that support dynamic timeout reconfiguration between
 * retry attempts.
 *
 * HTTP client adapters that wrap platform-specific transports (e.g., Magento's cURL wrapper) implement this interface
 * to allow the Comfino SDK retry executor to apply exponential backoff timeout escalation at the transport layer.
 *
 * Usage in Client::sendRequest():
 *
 *   $retryExecutor->execute(
 *       fn () => parent::sendRequest($request, $apiVersion),
 *       function (int $attempt, \Throwable $error): void {
 *           if ($this->httpClient instanceof TimeoutAwareClientInterface) {
 *               $policy = $this->retryExecutor->getRetryPolicy();
 *               $this->httpClient->updateTimeouts(
 *                   $policy->getConnectionTimeout($attempt + 1),
 *                   $policy->getTransferTimeout($attempt + 1)
 *               );
 *           }
 *       }
 *   );
 *
 * Without this interface the retry executor still works, but all retry attempts share the same timeout values as the
 * initial request. Implementing this interface enables the full exponential backoff experience on transient failures.
 */
interface TimeoutAwareClientInterface extends ClientInterface
{
    /**
     * Updates the connection and transfer timeouts applied to subsequent requests.
     *
     * Called by the retry executor's onRetry callback before each retry attempt with the escalated timeout values
     * calculated by the retry policy (ExponentialBackoffRetryPolicy doubles both timeouts on each attempt, capped at
     * MAX_CONNECTION_TIMEOUT / MAX_TRANSFER_TIMEOUT).
     *
     * Implementations must apply the new values to the underlying transport
     * (e.g., CURLOPT_CONNECTTIMEOUT / CURLOPT_TIMEOUT) so they take effect on the very next sendRequest() call.
     *
     * @param int $connectionTimeout Connection timeout in seconds (CURLOPT_CONNECTTIMEOUT equivalent)
     * @param int $transferTimeout Total request timeout in seconds (CURLOPT_TIMEOUT equivalent)
     */
    public function updateTimeouts(int $connectionTimeout, int $transferTimeout): void;
}
