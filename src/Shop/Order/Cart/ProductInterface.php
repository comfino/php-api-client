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
 * Interface for shop cart product.
 */
interface ProductInterface
{
    /**
     * Returns the product name.
     *
     * @return string Product name
     */
    public function getName(): string;

    /**
     * Returns the product price.
     *
     * @return int Product price
     */
    public function getPrice(): int;

    /**
     * Returns the net price of the product.
     *
     * @return int|null Net price of the product
     */
    public function getNetPrice(): ?int;

    /**
     * Returns the tax rate of the product.
     *
     * @return int|null Tax rate of the product
     */
    public function getTaxRate(): ?int;

    /**
     * Returns the tax value of the product.
     *
     * @return int|null Tax value of the product
     */
    public function getTaxValue(): ?int;

    /**
     * Returns the product ID.
     *
     * @return string|null Product ID
     */
    public function getId(): ?string;

    /**
     * Returns the product category.
     *
     * @return string|null Product category
     */
    public function getCategory(): ?string;

    /**
     * Returns the product EAN code.
     *
     * @return string|null Product EAN code
     */
    public function getEan(): ?string;

    /**
     * Returns the product photo URL.
     *
     * @return string|null Product photo URL
     */
    public function getPhotoUrl(): ?string;

    /**
     * Returns the product category IDs.
     *
     * @return int[]|null Product category IDs
     */
    public function getCategoryIds(): ?array;
}
