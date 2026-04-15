<?php

/**
 * Comfino PHP API client
 *
 * Backend routines for communication with the Comfino payment gateway REST API.
 *
 * @package Comfino\Api\Request
 * @author Artur Kozubski <akozubski@comperia.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-api-client
 */

declare(strict_types=1);

namespace Comfino\Api\Request;

use Comfino\Api\Dto\Payment\LoanQueryCriteria;
use Comfino\Api\Request;
use Comfino\Shop\Order\CartInterface;
use Comfino\Shop\Order\CartTrait;

/**
 * Financial product details request based on shopping cart contents.
 */
class GetFinancialProductDetails extends Request
{
    use CartTrait;

    /**
     * @param LoanQueryCriteria $queryCriteria Query criteria for the loan offer details
     * @param CartInterface $cart Shopping cart containing order details
     */
    public function __construct(LoanQueryCriteria $queryCriteria, private readonly CartInterface $cart)
    {
        $this->setRequestMethod('POST');
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
    }

    /** @inheritDoc */
    protected function prepareRequestBody(): ?array
    {
        return $this->getCartAsArray($this->cart);
    }
}
