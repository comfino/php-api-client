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

use Comfino\Enum\LoanTypeInterface;

/**
 * Interface for loan parameters used in Comfino payment gateway transactions.
 */
interface LoanParametersInterface
{
    /**
     * Requested loan amount.
     *
     * @return int Loan amount
     */
    public function getAmount(): int;

    /**
     * Number of requested installments.
     *
     * @return int|null Number of requested installments
     */
    public function getTerm(): ?int;

    /**
     * Selected financial product type.
     *
     * @return LoanTypeInterface|null Selected financial product type
     */
    public function getType(): ?LoanTypeInterface;

    /**
     * Allowed product types shown as alternatives on the transaction website.
     *
     * @return LoanTypeInterface[]|null Allowed product types
     */
    public function getAllowedProductTypes(): ?array;
}
