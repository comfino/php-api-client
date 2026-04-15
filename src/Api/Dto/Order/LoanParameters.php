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

use Comfino\Enum\LoanTypeInterface;

/**
 * Loan parameters for order creation DTO.
 */
class LoanParameters
{
    /**
     * @param int $amount Loan amount
     * @param int|null $maxAmount Maximum loan amount
     * @param int $term Loan term in months
     * @param LoanTypeInterface $type Loan type (financial product type)
     * @param LoanTypeInterface[]|null $allowedProductTypes Allowed product types for the loan
     */
    public function __construct(
        public readonly int $amount,
        public readonly ?int $maxAmount,
        public readonly int $term,
        public readonly LoanTypeInterface $type,
        public readonly ?array $allowedProductTypes
    ) {
    }
}
