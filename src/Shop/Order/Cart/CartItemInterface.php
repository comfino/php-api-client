<?php

/**
 * Comfino PHP API client
 *
 * Backend routines for communication with the Comfino payment gateway REST API.
 *
 * @package Comfino\Shop\Order\Cart
 * @author Artur Kozubski <akozubski@comperia.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-api-client
 */

declare(strict_types=1);

namespace Comfino\Shop\Order\Cart;

/**
 * Interface for shop cart item.
 */
interface CartItemInterface
{
    /**
     * Returns the product in the shop cart.
     *
     * @return ProductInterface Product in the shop cart
     */
    public function getProduct(): ProductInterface;

    /**
     * Returns the quantity of the product in the shop cart.
     *
     * @return int Quantity of the product in the shop cart
     */
    public function getQuantity(): int;
}
