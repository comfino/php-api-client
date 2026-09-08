<?php

/**
 * ComfinoPay PHP API client
 *
 * Backend routines for communication with the ComfinoPay payment gateway REST API.
 *
 * @package Comfino\Api\Request
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-api-client
 */

declare(strict_types=1);

namespace Comfino\Api\Request;

use Comfino\Api\Request;
use Comfino\Enum\ProductListType;

/**
 * Available financial product types listing request.
 */
class GetProductTypes extends Request
{
    /**
     * @param ProductListType $listType List type to get the product types for
     */
    public function __construct(ProductListType $listType)
    {
        $this->setRequestMethod('GET');
        $this->setApiEndpointPath('product-types');
        $this->setRequestParams(['listType' => $listType->value]);
    }

    /** @inheritDoc */
    protected function prepareRequestBody(): ?array
    {
        return null;
    }
}
