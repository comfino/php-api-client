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
 * Order interface representing a deferred payment transaction with Comfino payment gateway.
 */
interface OrderInterface
{
    /**
     * Shop internal order ID.
     *
     * @return string Shop internal order ID sent as external ID to the Comfino API
     */
    public function getId(): string;

    /**
     * Callback URL used by Comfino API for sending notifications about transaction status changes.
     *
     * @return string|null URL of the Comfino API callback
     */
    public function getNotifyUrl(): ?string;

    /**
     * URL of the shop confirmation page where the customer is redirected after the transaction (success or failure).
     *
     * @return string URL of the shop confirmation page
     */
    public function getReturnUrl(): string;

    /**
     * Returns the loan parameters for the order.
     *
     * @return LoanParametersInterface Loan parameters for the order
     */
    public function getLoanParameters(): LoanParametersInterface;

    /**
     * Returns the shop cart.
     *
     * @return CartInterface Shop cart
     */
    public function getCart(): CartInterface;

    /**
     * Returns the customer.
     *
     * @return CustomerInterface Customer associated with the order
     */
    public function getCustomer(): CustomerInterface;

    /**
     * Returns the seller.
     *
     * @return SellerInterface|null Seller associated with the order
     */
    public function getSeller(): ?SellerInterface;

    /**
     * Returns the account number.
     *
     * @return string|null Account number associated with the order
     */
    public function getAccountNumber(): ?string;

    /**
     * Returns the transfer title.
     *
     * @return string|null Transfer title associated with the order
     */
    public function getTransferTitle(): ?string;
}
