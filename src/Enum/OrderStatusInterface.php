<?php

/**
 * ComfinoPay PHP API client
 *
 * Backend routines for communication with the ComfinoPay payment gateway REST API.
 *
 * @package Comfino\Enum
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-api-client
 */

declare(strict_types=1);

namespace Comfino\Enum;

/**
 * Common interface for order status values, covering both SDK-defined enum cases and statuses the
 * ComfinoPay API has introduced that are not yet in this SDK version.
 *
 * {@see OrderStatus} is the known-cases backed enum supporting exhaustive match; {@see UnknownOrderStatus} is the
 * forward-compatible flyweight for unrecognised values. Narrow with `instanceof OrderStatus` before matching.
 */
interface OrderStatusInterface
{
    /**
     * Raw string value as received from the ComfinoPay API.
     */
    public function getValue(): string;

    /**
     * Returns true when this status is defined in the current SDK version. False means the ComfinoPay API returned a
     * status not yet added to {@see OrderStatus}.
     */
    public function isKnown(): bool;
}
