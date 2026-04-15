<?php

/**
 * Comfino PHP API client
 *
 * Backend routines for communication with the Comfino payment gateway REST API.
 *
 * @package Comfino\Enum
 * @author Artur Kozubski <akozubski@comperia.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-api-client
 */

declare(strict_types=1);

namespace Comfino\Enum;

/**
 * Common interface for order status values, covering both SDK-defined enum cases and statuses introduced by the
 * Comfino API that are not yet defined in this version of the SDK.
 *
 * Implementations:
 *  - {@see OrderStatus} - known statuses, backed PHP enum; supports exhaustive match
 *  - {@see UnknownOrderStatus} - forward-compatible flyweight for unrecognised API values
 *
 * Usage pattern in consuming code:
 *
 *   $status = OrderStatus::fromApiValue($rawString); // OrderStatusInterface
 *
 *   if ($status instanceof OrderStatus) {
 *       match($status) {
 *           OrderStatus::ACCEPTED => ...,
 *           OrderStatus::REJECTED => ...,
 *           default => ...,
 *       }
 *   } else {
 *       // New status from API - log or handle generically
 *       $raw = $status->getValue();
 *   }
 */
interface OrderStatusInterface
{
    /**
     * Raw string value as received from the Comfino API.
     */
    public function getValue(): string;

    /**
     * Returns true when this status is defined in the current SDK version.
     * False means the Comfino API returned a status not yet added to {@see OrderStatus}.
     */
    public function isKnown(): bool;
}
