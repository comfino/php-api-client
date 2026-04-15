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

use Comfino\Shop\Order\Customer\AddressInterface;

/**
 * Interface for customer.
 */
interface CustomerInterface
{
    /**
     * Returns the first name of the customer.
     *
     * @return string First name of the customer
     */
    public function getFirstName(): string;

    /**
     * Returns the last name of the customer.
     *
     * @return string Last name of the customer
     */
    public function getLastName(): string;

    /**
     * Returns the email address of the customer.
     *
     * @return string Email address of the customer
     */
    public function getEmail(): string;

    /**
     * Returns the phone number of the customer.
     *
     * @return string Phone number of the customer
     */
    public function getPhoneNumber(): string;

    /**
     * Returns the IP address of the customer.
     *
     * @return string IP address of the customer
     */
    public function getIp(): string;

    /**
     * Returns the tax ID of the customer.
     *
     * @return string|null Tax ID of the customer
     */
    public function getTaxId(): ?string;

    /**
     * Returns whether the customer is a regular customer.
     *
     * @return bool|null Whether the customer is a regular customer
     */
    public function isRegular(): ?bool;

    /**
     * Returns whether the customer is logged in.
     *
     * @return bool|null Whether the customer is logged in
     */
    public function isLogged(): ?bool;

    /**
     * Returns the customer address.
     *
     * @return AddressInterface|null Customer address
     */
    public function getAddress(): ?AddressInterface;
}
