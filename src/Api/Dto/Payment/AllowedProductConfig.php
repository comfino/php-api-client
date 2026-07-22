<?php

/**
 * Comfino PHP API client
 *
 * Backend routines for communication with the Comfino payment gateway REST API.
 *
 * @package Comfino\Api\Dto\Payment
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-api-client
 */

declare(strict_types=1);

namespace Comfino\Api\Dto\Payment;

use Comfino\Enum\LoanTypeInterface;

/**
 * Single entry in the allowedProductsConfig constraint list.
 * Pass only the constraints you need; omitted fields apply no restriction.
 */
class AllowedProductConfig
{
    /**
     * @param LoanTypeInterface $type Financial product type (use LoanType enum cases for requests)
     * @param int|null $maxTerm Maximum number of instalments (inclusive, null = no upper limit)
     * @param int|null $minTerm Minimum number of instalments (inclusive, null = no lower limit)
     * @param int[]|null $terms Explicit whitelist of allowed instalment counts (null = all allowed)
     */
    public function __construct(
        public readonly LoanTypeInterface $type,
        public readonly ?int $maxTerm = null,
        public readonly ?int $minTerm = null,
        public readonly ?array $terms = null
    ) {
    }
}
