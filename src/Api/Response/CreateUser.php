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
 * `apiKey` and `widgetKey` are the credentials the shop must store from this point on - the request that produced them
 * was the one call in this library sent without an `Api-Key` header. `uuid` identifies the merchant account record itself
 * on the ComfinoPay side, so a caller that also has its own account/installation record can link the two without matching
 * on `webSiteUrl`. Required: {@see \Comfino\Api\Request\CreateUser} always targets API v2, which always returns it,
 * regardless of the client's own configured version.
 */
class CreateUser extends Base
{
    /** @var string API key authenticating every subsequent call for this shop */
    public readonly string $apiKey;
    /** @var string Widget key for the shop's promotional banner / paywall widgets */
    public readonly string $widgetKey;
    /** @var string ComfinoPay's own identifier for the newly created merchant account */
    public readonly string $uuid;

    /** @inheritDoc */
    protected function processResponseBody(array|string|bool|null|float|int $deserializedResponseBody): void
    {
        $this->checkResponseType($deserializedResponseBody, 'array');
        $this->checkResponseStructure($deserializedResponseBody, ['apiKey', 'widgetKey', 'uuid']);
        $this->checkResponseType($deserializedResponseBody['apiKey'], 'string', 'apiKey');
        $this->checkResponseType($deserializedResponseBody['widgetKey'], 'string', 'widgetKey');
        $this->checkResponseType($deserializedResponseBody['uuid'], 'string', 'uuid');

        $this->apiKey = $deserializedResponseBody['apiKey'];
        $this->widgetKey = $deserializedResponseBody['widgetKey'];
        $this->uuid = $deserializedResponseBody['uuid'];
    }
}
