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

/**
 * Response from the API to the order (loan application) creation request.
 */
class CreateOrder extends Base
{
    /** @var string Created order status */
    public readonly string $status;
    /** @var string Unique identifier of the order in the merchant system sent in the order creation request */
    public readonly string $externalId;
    /** @var string URL of the Comfino payment gateway application page where the shop platform must redirect the customer */
    public readonly string $applicationUrl;

    /** @inheritDoc */
    protected function processResponseBody(array|string|bool|null|float|int $deserializedResponseBody): void
    {
        $this->checkResponseType($deserializedResponseBody, 'array');
        $this->checkResponseStructure($deserializedResponseBody, ['status', 'externalId', 'applicationUrl']);
        $this->checkResponseType($deserializedResponseBody['status'], 'string', 'status');
        $this->checkResponseType($deserializedResponseBody['externalId'], 'string', 'externalId');
        $this->checkResponseType($deserializedResponseBody['applicationUrl'], 'string', 'applicationUrl');

        $this->status = $deserializedResponseBody['status'];
        $this->externalId = $deserializedResponseBody['externalId'];
        $this->applicationUrl = $deserializedResponseBody['applicationUrl'];
    }
}
