<?php

/**
 * ComfinoPay PHP API client
 *
 * @package Comfino\Api\Response
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-api-client
 */

declare(strict_types=1);

namespace Comfino\Api\Response;

/**
 * Available creditors list response.
 *
 * The API returns a map of product type code → array of creditor codes, e.g.:
 * { "PAY_LATER": ["twisto", "pragmago"], "LEASING": ["leaselink"] }
 */
class GetCreditors extends Base
{
    /** @var array<string, string[]> Product type → creditor code list */
    public readonly array $creditors;

    /** @inheritDoc */
    protected function processResponseBody(array|string|bool|null|float|int $deserializedResponseBody): void
    {
        $this->checkResponseType($deserializedResponseBody, 'array');

        $this->creditors = $deserializedResponseBody;
    }
}
