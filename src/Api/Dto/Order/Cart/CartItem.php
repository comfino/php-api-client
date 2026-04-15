<?php

/**
 * Comfino PHP API client
 *
 * Backend routines for communication with the Comfino payment gateway REST API.
 *
 * @package Comfino\Api\Dto\Order\Cart
 * @author Artur Kozubski <akozubski@comperia.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-api-client
 */

declare(strict_types=1);

namespace Comfino\Api\Dto\Order\Cart;

/**
 * Cart item DTO for loan application (cart product).
 */
class CartItem
{
    /**
     * @param string $name Product name
     * @param int $price Product price
     * @param int $quantity Product quantity
     * @param string|null $externalId External product ID (shop product ID)
     * @param string|null $photoUrl Product photo shop URL
     * @param string|null $ean EAN code or SKU code
     * @param string|null $category Product category
     * @param int|null $netPrice Product net price
     * @param int|null $vatRate Product VAT rate
     * @param int|null $vatAmount Product VAT amount
     */
    public function __construct(
        public readonly string $name,
        public readonly int $price,
        public readonly int $quantity,
        public readonly ?string $externalId = null,
        public readonly ?string $photoUrl = null,
        public readonly ?string $ean = null,
        public readonly ?string $category = null,
        public readonly ?int $netPrice = null,
        public readonly ?int $vatRate = null,
        public readonly ?int $vatAmount = null
    ) {
    }
}
