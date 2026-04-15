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

/**
 * Loan parameters for financial products DTO.
 */
class LoanParameters
{
    /**
     * @param int $instalmentAmount Loan instalment amount in cents
     * @param int $toPay Loan amount to pay in cents
     * @param int $loanTerm Loan term in months
     * @param float $rrso Annual Percentage Rate (RRSO)
     * @param int|null $initialPaymentValue Initial payment value for leasing
     * @param float|null $initialPaymentRate Initial payment rate for leasing
     * @param int|null $redemptionPaymentValue Lease buyout price
     * @param float|null $redemptionPaymentRate Lease buyout rate
     * @param float|null $interest Lease interest rate
     */
    public function __construct(
        public readonly int $instalmentAmount,
        public readonly int $toPay,
        public readonly int $loanTerm,
        public readonly float $rrso,
        public readonly ?int $initialPaymentValue = null,
        public readonly ?float $initialPaymentRate = null,
        public readonly ?int $redemptionPaymentValue = null,
        public readonly ?float $redemptionPaymentRate = null,
        public readonly ?float $interest = null
    ) {
    }
}
