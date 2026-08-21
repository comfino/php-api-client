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

/**
 * PSR-18 transport that can produce a copy of itself configured with different timeouts.
 *
 * Replaces {@see TimeoutAwareClientInterface}, whose `updateTimeouts()` mutates the transport in place and never
 * restores it. In a single-tenant plugin that is invisible, because the process ends shortly after. In a shared,
 * long-lived process it is a cross-tenant bug: tenant A's escalation to a four-second connect budget stays applied,
 * and tenant B's next call - the shopper-facing one sized at one second - silently runs at tenant A's budget. Bounding
 * the escalated value, as the total transfer budget does, is not a fix for leaking it.
 *
 * A transport implementing this interface is never reconfigured; the retry loop derives a per-attempt instance:
 *
 *     $transport = $this->httpClient instanceof TimeoutConfigurableClientInterface
 *         ? $this->httpClient->withTimeouts($timeouts)
 *         : $this->httpClient;
 *
 * Implementations must return a new instance and leave the receiver untouched. Sharing the underlying connection pool
 * between the copies is not only allowed but wanted: the credential is a header, not a connection property, so one TLS
 * pool serving every tenant is both safe and the point.
 */
interface TimeoutConfigurableClientInterface extends ClientInterface
{
    /**
     * Returns a copy of this transport that applies the given timeouts to the requests it sends.
     *
     * @param TimeoutConfig $timeouts Connection and transfer timeouts for the next request
     *
     * @return static A new instance; the receiver must be left unchanged
     */
    public function withTimeouts(TimeoutConfig $timeouts): static;
}
