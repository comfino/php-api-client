<?php

/**
 * Comfino PHP API client
 *
 * Backend routines for communication with the Comfino payment gateway REST API.
 *
 * @package Comfino\Api\Response
 * @author Artur Kozubski <akozubski@comperia.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-api-client
 */

declare(strict_types=1);

namespace Comfino\Api\Response;

use Comfino\Enum\LoanType;
use Comfino\Enum\LoanTypeInterface;

/**
 * Response from the API containing available product types.
 */
class GetProductTypes extends Base
{
    /** @var LoanTypeInterface[] All product types returned by the API, including any not yet defined in this SDK wrapped in UnknownLoanType */
    public readonly array $productTypes;
    /** @var string[] Product types with their names as key value pairs */
    public readonly array $productTypesWithNames;

    /** @inheritDoc */
    protected function processResponseBody(array|string|bool|null|float|int $deserializedResponseBody): void
    {
        $this->checkResponseType($deserializedResponseBody, 'array');

        $this->productTypesWithNames = $deserializedResponseBody;
        $this->productTypes = array_map(
            static fn (string $productType): LoanTypeInterface => LoanType::fromApiValue($productType),
            array_keys($deserializedResponseBody)
        );
    }
}
