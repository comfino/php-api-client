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

enum OrderStatus: string implements OrderStatusInterface
{
    case CREATED = 'CREATED';
    case WAITING_FOR_FILLING = 'WAITING_FOR_FILLING';
    case WAITING_FOR_CONFIRMATION = 'WAITING_FOR_CONFIRMATION';
    case WAITING_FOR_PAYMENT = 'WAITING_FOR_PAYMENT';
    case ACCEPTED = 'ACCEPTED';
    case PAID = 'PAID';
    case REJECTED = 'REJECTED';
    case RESIGN = 'RESIGN';
    case CANCELLED_BY_SHOP = 'CANCELLED_BY_SHOP';
    case CANCELLED = 'CANCELLED';

    /**
     * Safe factory for deserializing order status values from Comfino API responses and webhooks.
     *
     * Unlike from(), this never throws a ValueError: if the API returns a status not yet defined in this SDK version,
     * it returns an {@see UnknownOrderStatus} flyweight so the webhook handler stays operational without requiring an
     * SDK upgrade.
     */
    public static function fromApiValue(string $value): OrderStatusInterface
    {
        return self::tryFrom($value) ?? UnknownOrderStatus::of($value);
    }

    /** @inheritDoc */
    public function getValue(): string
    {
        return $this->value;
    }

    /** @inheritDoc */
    public function isKnown(): bool
    {
        return true;
    }
}
