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
 * Free-form response for API endpoints not covered by dedicated {@see \Comfino\Api\Response} subclass.
 *
 * Exposes the deserialized response body verbatim instead of mapping it to typed properties - HTTP status → exception
 * mapping (400/401/403/404/405/409/5xx) still applies, since it happens in the parent class before
 * {@see processResponseBody()} is ever called.
 */
class CustomResponse extends Base
{
    /** @var array<string, mixed>|string|bool|int|float|null Deserialized response body, verbatim */
    public readonly array|string|bool|int|float|null $body;

    /** @inheritDoc */
    protected function processResponseBody(array|string|bool|int|float|null $deserializedResponseBody): void
    {
        $this->body = $deserializedResponseBody;
    }
}
