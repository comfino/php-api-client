<?php

/**
 * Comfino PHP API client
 *
 * Backend routines for communication with the Comfino payment gateway REST API.
 *
 * @package Comfino\Api\Dto\Payment
 * @author Artur Kozubski <akozubski@comperia.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-api-client
 */

declare(strict_types=1);

namespace Comfino\Api\Dto\Payment;

use Comfino\Enum\LoanTypeInterface;

/**
 * Financial product details DTO.
 */
class FinancialProduct
{
    /**
     * @param string $name Financial product name
     * @param LoanTypeInterface $type Loan type (financial product type code)
     * @param string $creditorName Creditor name
     * @param string $description Financial product description
     * @param string $icon Financial product icon URL
     * @param int $instalmentAmount Instalment amount
     * @param int $toPay To pay amount
     * @param int $loanTerm Loan term in months
     * @param float $rrso Annual Percentage Rate (RRSO)
     * @param string $representativeExample Representative example
     * @param string|null $remarks Additional remarks
     * @param LoanParameters[] $loanParameters Loan parameters
     * @param int|null $initialPaymentValue Initial payment value for leasing
     * @param float|null $initialPaymentRate Initial payment rate for leasing
     * @param int|null $redemptionPaymentValue Lease buyout price
     * @param float|null $redemptionPaymentRate Lease buyout rate
     * @param float|null $offerRate Lease offer interest rate
     */
    public function __construct(
        public readonly string $name,
        public readonly LoanTypeInterface $type,
        public readonly string $creditorName,
        public readonly string $description,
        public readonly string $icon,
        public readonly int $instalmentAmount,
        public readonly int $toPay,
        public readonly int $loanTerm,
        public readonly float $rrso,
        public readonly string $representativeExample,
        public readonly ?string $remarks,
        public readonly array $loanParameters,
        public readonly ?int $initialPaymentValue = null,
        public readonly ?float $initialPaymentRate = null,
        public readonly ?int $redemptionPaymentValue = null,
        public readonly ?float $redemptionPaymentRate = null,
        public readonly ?float $offerRate = null
    ) {
    }
}
