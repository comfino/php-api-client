<?php

/**
 * ComfinoPay PHP API client
 *
 * Backend routines for communication with the ComfinoPay payment gateway REST API.
 *
 * @package Comfino\Enum
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-api-client
 */

declare(strict_types=1);

namespace Comfino\Enum;

/**
 * Common interface for loan type values, covering both SDK-defined enum cases and loan types introduced by the
 * ComfinoPay API that are not yet defined in this version of the SDK.
 *
 * Implementations:
 *  - {@see LoanType} - known types, backed PHP enum; supports exhaustive match
 *  - {@see UnknownLoanType} - forward-compatible flyweight for unrecognized API values
 *
 * Usage pattern in consuming code:
 *
 *   $type = $financialProduct->type; // LoanTypeInterface
 *
 *   if ($type instanceof LoanType) {
 *       // Full enum control: match, ==, cases(), etc.
 *       match($type) {
 *           LoanType::PAY_LATER => ...,
 *           default => ...,
 *       }
 *   } else {
 *       // New type from API - handle generically, log, or skip
 *       $raw = $type->getValue();
 *   }
 */
interface LoanTypeInterface
{
    /**
     * Raw string value as received from the ComfinoPay API.
     */
    public function getValue(): string;

    /**
     * Returns true when this loan type is defined in the current SDK version.
     * False means the ComfinoPay API returned a type not yet added to {@see LoanType}.
     */
    public function isKnown(): bool;
}
