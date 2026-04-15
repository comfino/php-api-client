<?php

/**
 * Comfino PHP API client
 *
 * Backend routines for communication with the Comfino payment gateway REST API.
 *
 * @package Comfino\Api\Dto\Order
 * @author Artur Kozubski <akozubski@comperia.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-api-client
 */

declare(strict_types=1);

namespace Comfino\Api\Dto\Order;

use Comfino\Api\Dto\Order\Cart\CartItem;

/**
 * Represents a cart DTO for loan application.
 */
class Cart
{
    /**
     * @param int $totalAmount Total amount of the cart (loan application amount)
     * @param int $deliveryCost Delivery cost of the cart
     * @param string|null $category Category of the cart
     * @param CartItem[] $products Products in the cart
     */
    public function __construct(
        public readonly int $totalAmount,
        public readonly int $deliveryCost,
        public readonly ?string $category,
        public readonly array $products
    ) {
    }
}
