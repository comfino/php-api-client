<?php

/**
 * Comfino PHP API client
 *
 * Backend routines for communication with the Comfino payment gateway REST API.
 *
 * @package Comfino\Enum
 * @author Artur Kozubski <akozubski@comperia.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-api-client
 */

declare(strict_types=1);

namespace Comfino\Enum;

enum LoanType: string implements LoanTypeInterface
{
    case INSTALLMENTS_ZERO_PERCENT = 'INSTALLMENTS_ZERO_PERCENT';
    case CONVENIENT_INSTALLMENTS = 'CONVENIENT_INSTALLMENTS';
    case PAY_LATER = 'PAY_LATER';
    case COMPANY_INSTALLMENTS = 'COMPANY_INSTALLMENTS';
    case COMPANY_BNPL = 'COMPANY_BNPL';
    case RENEWABLE_LIMIT = 'RENEWABLE_LIMIT';
    case BLIK = 'BLIK';
    case LEASING = 'LEASING';
    case PAY_IN_PARTS = 'PAY_IN_PARTS';
    case INSTANT_PAYMENTS = 'INSTANT_PAYMENTS';

    /**
     * Safe factory for deserializing loan type values from Comfino API responses.
     *
     * Unlike from(), this never throws a ValueError: if the API returns a loan type not yet defined in this SDK
     * version, it returns an {@see UnknownLoanType} flyweight so the calling code stays operational without requiring
     * an SDK upgrade.
     *
     * Use this method exclusively when parsing data received from the API.
     * Use enum cases directly (e.g. LoanType::PAY_LATER) when constructing requests.
     */
    public static function fromApiValue(string $value): LoanTypeInterface
    {
        return self::tryFrom($value) ?? UnknownLoanType::of($value);
    }

    /** @inheritDoc */
    public function getValue(): string
    {
        return $this->value;
    }

    /** @inheritDoc */
    public function isKnown(): bool
    {
        return true;
    }
}
