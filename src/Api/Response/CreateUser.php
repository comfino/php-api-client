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

/**
 * Shop owner registration response.
 *
 * `apiKey` and `widgetKey` are the credentials the shop must store from this point on - the request that produced
 * them was the one call in this library sent without an `Api-Key` header.
 */
class CreateUser extends Base
{
    /** @var string API key authenticating every subsequent call for this shop */
    public readonly string $apiKey;
    /** @var string Widget key for the shop's promotional banner / paywall widgets */
    public readonly string $widgetKey;

    /** @inheritDoc */
    protected function processResponseBody(array|string|bool|null|float|int $deserializedResponseBody): void
    {
        $this->checkResponseType($deserializedResponseBody, 'array');
        $this->checkResponseStructure($deserializedResponseBody, ['apiKey', 'widgetKey']);
        $this->checkResponseType($deserializedResponseBody['apiKey'], 'string', 'apiKey');
        $this->checkResponseType($deserializedResponseBody['widgetKey'], 'string', 'widgetKey');

        $this->apiKey = $deserializedResponseBody['apiKey'];
        $this->widgetKey = $deserializedResponseBody['widgetKey'];
    }
}
