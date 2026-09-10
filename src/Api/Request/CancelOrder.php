<?php

/**
 * ComfinoPay PHP API client
 *
 * Backend routines for communication with the ComfinoPay payment gateway REST API.
 *
 * @package Comfino\Api\Request
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-api-client
 */

declare(strict_types=1);

namespace Comfino\Api\Request;

use Comfino\Api\Request;

/**
 * Loan application cancellation request.
 */
class CancelOrder extends Request
{
    /**
     * @param string $orderId Order ID to cancel (shop order ID sent as external ID in the order creation request)
     */
    public function __construct(string $orderId)
    {
        $this->setRequestMethod('PUT');
        $this->setApiEndpointPath(sprintf('orders/%s/cancel', $orderId));
    }

    /**
     * Safe to replay, and the API enforces it rather than merely tolerating it.
     *
     * A cancellation is a state transition, and the transition handler returns the order untouched when it is already
     * at the target status, or already in a terminal reject status. So a replay produces no second status notification
     * and no second "canceled by shop" email - it is not just free of duplicates, it is free of duplicate *effects*,
     * which is the part a retry actually depends on.
     *
     * Note that this is a `PUT`, and idempotency is what `PUT` promises; the guard above is the API keeping that
     * promise.
     */
    public function isIdempotent(): bool
    {
        return true;
    }

    /** @inheritDoc */
    protected function prepareRequestBody(): ?array
    {
        return null;
    }
}
