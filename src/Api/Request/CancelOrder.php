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
     * Safe to replay: the transition handler returns the order untouched when it is already at the target status or a
     * terminal reject status, so a replay produces no second "canceled by shop" notification.
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
