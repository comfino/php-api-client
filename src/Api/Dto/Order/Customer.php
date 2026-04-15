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

use Comfino\Api\Dto\Order\Customer\Address;

/**
 * Customer data for order DTO.
 */
class Customer
{
    /**
     * @param string $firstName Customer first name
     * @param string $lastName Customer last name
     * @param string $email Customer e-mail
     * @param string $phoneNumber Customer mobile phone number
     * @param string $ip Customer IP address
     * @param string|null $taxId Customer tax ID (NIP or VAT ID)
     * @param bool|null $regular Customer is regular customer (true if registered user, false if guest)
     * @param bool|null $logged Customer is logged in the shop
     * @param Address|null $address Customer address
     */
    public function __construct(
        public readonly string $firstName,
        public readonly string $lastName,
        public readonly string $email,
        public readonly string $phoneNumber,
        public readonly string $ip,
        public readonly ?string $taxId,
        public readonly ?bool $regular,
        public readonly ?bool $logged,
        public readonly ?Address $address
    ) {
    }
}
