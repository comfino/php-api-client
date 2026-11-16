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
 * Common interface for loan type values, covering both SDK-defined enum cases and loan types the ComfinoPay API
 * has introduced that are not yet in this SDK version.
 *
 * {@see LoanType} is the known-cases backed enum supporting exhaustive match; {@see UnknownLoanType} is the forward-compatible
 * flyweight for unrecognized values. Narrow with `instanceof LoanType` before matching.
 */
interface LoanTypeInterface
{
    /**
     * Raw string value as received from the ComfinoPay API.
     */
    public function getValue(): string;

    /**
     * Returns true when this loan type is defined in the current SDK version. False means the ComfinoPay API returned a type not yet added to {@see LoanType}.
     */
    public function isKnown(): bool;
}
