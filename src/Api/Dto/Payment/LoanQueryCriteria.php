<?php

/**
 * ComfinoPay PHP API client
 *
 * Backend routines for communication with the ComfinoPay payment gateway REST API.
 *
 * @package Comfino\Api\Dto\Payment
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-api-client
 */

declare(strict_types=1);

namespace Comfino\Api\Dto\Payment;

use Comfino\Enum\LoanTypeInterface;

/**
 * Loan query criteria for ComfinoPay API request for loan offers listing.
 */
class LoanQueryCriteria
{
    /**
     * @param int $loanAmount Loan amount
     * @param int|null $loanTerm Loan term in months
     * @param LoanTypeInterface|null $loanType Loan type (financial product type)
     * @param int|null $priceModifier Price modifier (value modifier, e.g. commission amount)
     * @param LoanTypeInterface[]|null $productTypes Financial product types to filter by
     * @param string|null $taxId Tax ID (NIP - VAT ID)
     * @param AllowedProductConfig[]|null $allowedProductsConfig Per-product term constraints (optional)
     */
    public function __construct(
        public readonly int $loanAmount,
        public readonly ?int $loanTerm = null,
        public readonly ?LoanTypeInterface $loanType = null,
        public readonly ?int $priceModifier = null,
        public readonly ?array $productTypes = null,
        public readonly ?string $taxId = null,
        public readonly ?array $allowedProductsConfig = null
    ) {
    }
}
