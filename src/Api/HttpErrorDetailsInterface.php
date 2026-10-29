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

/**
 * Diagnostic facts about an HTTP error response, for hosts that classify or report API errors.
 *
 * A separate interface, not more methods on {@see HttpErrorExceptionInterface}, so a host class implementing that one is not broken.
 */
interface HttpErrorDetailsInterface extends HttpErrorExceptionInterface
{
    /**
     * True when the error body was not in the negotiated format (an HTML error page from a proxy, a `text/plain` body,
     * a truncated or undecodable body), so the answer probably did not come from the ComfinoPay API itself.
     */
    public function hasNonNegotiatedBody(): bool;

    /**
     * True when the status is one of the request declared as "already in the requested state" (see {@see Request::getIdempotentFailureStatusCodes}),
     * e.g. a cancellation of an order that is already cancelled.
     */
    public function isIdempotentFailure(): bool;
}
