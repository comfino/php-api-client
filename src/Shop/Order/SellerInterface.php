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

/**
 * Seller interface representing a seller associated with an order.
 */
interface SellerInterface
{
    /**
     * Returns the seller tax ID.
     *
     * @return string|null Seller tax ID
     */
    public function getTaxId(): ?string;
}
