<?php

/**
 * Comfino PHP API client
 *
 * Backend routines for communication with the Comfino payment gateway REST API.
 *
 * @package Comfino\Shop\Order\Customer
 * @author Artur Kozubski <akozubski@comperia.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-api-client
 */

declare(strict_types=1);

namespace Comfino\Shop\Order\Customer;

/**
 * Interface for customer address.
 */
interface AddressInterface
{
    /**
     * Returns street address.
     *
     * @return string|null Street address
     */
    public function getStreet(): ?string;

    /**
     * Returns building number.
     *
     * @return string|null Building number
     */
    public function getBuildingNumber(): ?string;

    /**
     * Returns apartment number.
     *
     * @return string|null Apartment number
     */
    public function getApartmentNumber(): ?string;

    /**
     * Returns postal code.
     *
     * @return string|null Postal code
     */
    public function getPostalCode(): ?string;

    /**
     * Returns city.
     *
     * @return string|null City
     */
    public function getCity(): ?string;

    /**
     * Returns country code.
     *
     * @return string|null Country code 2-letter ISO 3166-1 alpha-2 (e.g. PL, DE, US)
     */
    public function getCountryCode(): ?string;
}
