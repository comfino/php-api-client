<?php

/**
 * Comfino PHP API client
 *
 * Backend routines for communication with the Comfino payment gateway REST API.
 *
 * @package Comfino\Api\Request
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-api-client
 */

declare(strict_types=1);

namespace Comfino\Api\Request;

use Comfino\Api\Dto\Payment\AllowedProductConfig;
use Comfino\Api\Dto\Payment\LoanQueryCriteria;
use Comfino\Api\Request;

/**
 * Financial products listing request.
 */
class GetFinancialProducts extends Request
{
    /**
     * @param LoanQueryCriteria $queryCriteria Query criteria for the loan offer listing
     */
    public function __construct(LoanQueryCriteria $queryCriteria)
    {
        $this->setRequestMethod('GET');
        $this->setApiEndpointPath('financial-products');
        $this->setRequestParams(
            array_filter(
                [
                    'loanAmount' => $queryCriteria->loanAmount,
                    'loanTerm' => $queryCriteria->loanTerm,
                    'loanTypeSelected' => $queryCriteria->loanType?->getValue(),
                    'productTypes' => $queryCriteria->productTypes !== null
                        ? implode(',', array_map(static fn ($type) => $type->getValue(), $queryCriteria->productTypes))
                        : null,
                    'taxId' => $queryCriteria->taxId,
                ],
                static fn ($value): bool => $value !== null
            )
        );

        if ($queryCriteria->allowedProductsConfig !== null) {
            $this->setNestedRequestParams([
                'allowedProductsConfig' => array_values(
                    array_map(
                        static fn (AllowedProductConfig $allowedProductConfig): array => array_filter(
                            [
                                'type' => $allowedProductConfig->type->getValue(),
                                'maxTerm' => $allowedProductConfig->maxTerm,
                                'minTerm' => $allowedProductConfig->minTerm,
                                'terms' => $allowedProductConfig->terms,
                            ],
                            static fn ($value): bool => $value !== null
                        ),
                        $queryCriteria->allowedProductsConfig
                    )
                ),
            ]);
        }
    }

    /** @inheritDoc */
    protected function prepareRequestBody(): ?array
    {
        return null;
    }
}
