<?php

/**
 * ComfinoPay PHP API client
 *
 * Backend routines for communication with the ComfinoPay payment gateway REST API.
 *
 * @package Comfino\Api\Response
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-api-client
 */

declare(strict_types=1);

namespace Comfino\Api\Response;

use Comfino\Enum\LoanType;
use Comfino\Enum\LoanTypeInterface;

/**
 * Response from the API containing available product types.
 *
 * Each product type is mapped to a [internalName, publicName] pair, e.g.:
 * { "PAY_LATER": ["Zapłać później", "Kup teraz i zapłać za 30 dni"] }
 */
class GetProductTypes extends Base
{
    /** @var LoanTypeInterface[] All product types returned by the API, including any not yet defined in this SDK wrapped in UnknownLoanType */
    public readonly array $productTypes;
    /** @var array<string, string> Internal display name keyed by product type */
    public readonly array $productTypesWithNames;
    /** @var array<string, string> Public (customer-facing) display name keyed by product type */
    public readonly array $productTypesWithPublicNames;

    /** @inheritDoc */
    protected function processResponseBody(array|string|bool|null|float|int $deserializedResponseBody): void
    {
        $this->checkResponseType($deserializedResponseBody, 'array');

        $productTypesWithNames = [];
        $productTypesWithPublicNames = [];

        foreach ($deserializedResponseBody as $productType => $names) {
            $this->checkResponseType($names, 'array', $productType);

            [$internalName, $publicName] = $names;

            $productTypesWithNames[$productType] = $internalName;
            $productTypesWithPublicNames[$productType] = $publicName;
        }

        $this->productTypesWithNames = $productTypesWithNames;
        $this->productTypesWithPublicNames = $productTypesWithPublicNames;
        $this->productTypes = array_map(
            static fn (string $productType): LoanTypeInterface => LoanType::fromApiValue($productType),
            array_keys($deserializedResponseBody)
        );
    }
}
