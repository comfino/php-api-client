<?php

/**
 * Comfino PHP API client
 *
 * Backend routines for communication with the Comfino payment gateway REST API.
 *
 * @package Comfino\Shop\Order
 * @author Artur Kozubski <akozubski@comperia.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-api-client
 */

declare(strict_types=1);

namespace Comfino\Shop\Order;

use Comfino\Shop\Order\Cart\CartItemInterface;

/**
 * Interface for shop cart.
 */
interface CartInterface
{
    /**
     * Returns the items in the shop cart.
     *
     * @return CartItemInterface[] Items in the shop cart
     */
    public function getItems(): array;

    /**
     * Returns the total amount of the shop cart.
     *
     * @return int Total amount of the shop cart
     */
    public function getTotalAmount(): int;

    /**
     * Returns the delivery cost of the shop cart.
     *
     * @return int|null Delivery cost of the shop cart
     */
    public function getDeliveryCost(): ?int;

    /**
     * Returns the delivery net cost of the shop cart.
     *
     * @return int|null Delivery net cost of the shop cart
     */
    public function getDeliveryNetCost(): ?int;

    /**
     * Returns the delivery cost tax rate of the shop cart.
     *
     * @return int|null Delivery cost tax rate of the shop cart
     */
    public function getDeliveryCostTaxRate(): ?int;

    /**
     * Returns the delivery cost tax value of the shop cart.
     *
     * @return int|null Delivery cost tax value of the shop cart
     */
    public function getDeliveryCostTaxValue(): ?int;

    /**
     * Returns the category of the shop cart.
     *
     * @return string|null Category of the shop cart
     */
    public function getCategory(): ?string;
}
