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

use Comfino\Api\Dto\Account\Agreement;
use Comfino\Api\Exception\ResponseValidationError;

/**
 * Legal agreements listing response.
 */
class FetchAgreements extends Base
{
    /** @var Agreement[] Agreements to present to the shop owner before registration */
    public readonly array $agreements;

    /** @inheritDoc */
    protected function processResponseBody(array|string|bool|null|float|int $deserializedResponseBody): void
    {
        $this->checkResponseType($deserializedResponseBody, 'array');

        $agreements = [];

        foreach ($deserializedResponseBody as $index => $agreement) {
            $this->checkResponseType($agreement, 'array', "$index");
            $this->checkResponseStructure($agreement, ['id', 'content']);
            $this->checkResponseType($agreement['id'], 'string', "$index.id");
            $this->checkResponseType($agreement['content'], 'string', "$index.content");

            if (isset($agreement['required']) && !is_bool($agreement['required'])) {
                throw new ResponseValidationError("Invalid response field \"$index.required\" data type: bool expected.");
            }

            $agreements[] = new Agreement(
                $agreement['id'],
                $agreement['content'],
                $agreement['required'] ?? false
            );
        }

        $this->agreements = $agreements;
    }
}
