<?php

/**
 * Comfino PHP API client
 *
 * Backend routines for communication with the Comfino payment gateway REST API.
 *
 * @package Comfino\Api\Dto\Order\Customer
 * @author Artur Kozubski <akozubski@comperia.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-api-client
 */

declare(strict_types=1);

namespace Comfino\Api\Dto\Order\Customer;

/**
 * Customer address details DTO.
 */
class Address
{
    /**
     * @param string|null $street Street name
     * @param string|null $buildingNumber Building number
     * @param string|null $apartmentNumber Apartment number
     * @param string|null $postalCode Postal code
     * @param string|null $city City name
     * @param string|null $countryCode Country code (ISO 3166-1 alpha-2)
     */
    public function __construct(
        public readonly ?string $street,
        public readonly ?string $buildingNumber,
        public readonly ?string $apartmentNumber,
        public readonly ?string $postalCode,
        public readonly ?string $city,
        public readonly ?string $countryCode
    ) {
    }
}
