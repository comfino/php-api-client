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

use Psr\Http\Client\ClientInterface;

/**
 * Optional extension of PSR-18 ClientInterface for HTTP clients that support dynamic timeout reconfiguration between retry attempts, so an adapter wrapping a
 * platform-specific transport (e.g., Magento's cURL wrapper) can receive escalated timeouts from the retry executor's backoff. Without it, retries still happen
 * but share the initial request's timeout values.
 *
 * @deprecated Since 3.0.0. Implement {@see TimeoutConfigurableClientInterface} instead. `updateTimeouts()` mutates the
 *             transport in place and never restores it, so on a transport shared between tenants the escalation from
 *             one tenant's retry sequence stays applied to the next tenant's call. The client still honors this
 *             interface when the newer one is absent, so existing adapters keep working unchanged.
 */
interface TimeoutAwareClientInterface extends ClientInterface
{
    /**
     * Updates the connection and transfer timeouts applied to subsequent requests. Called by the retry executor before
     * each retry attempt with the escalated values; implementations must apply them to the underlying transport (e.g.,
     * CURLOPT_CONNECTTIMEOUT / CURLOPT_TIMEOUT) so they take effect on the next `sendRequest()` call.
     *
     * @param int $connectionTimeout Connection timeout in seconds (CURLOPT_CONNECTTIMEOUT equivalent)
     * @param int $transferTimeout Total request timeout in seconds (CURLOPT_TIMEOUT equivalent)
     */
    public function updateTimeouts(int $connectionTimeout, int $transferTimeout): void;
}
