<?php

/**
 * Comfino PHP API client
 *
 * Backend routines for communication with the Comfino payment gateway REST API.
 *
 * @package Comfino\Tests\Unit\Api\Stub
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-api-client
 */

declare(strict_types=1);

namespace Comfino\Tests\Unit\Api\Stub;

use Comfino\Api\Response\Base;

/**
 * A typed Response subclass a consumer might write for a custom endpoint, used to test that
 * {@see \Comfino\Api\AbstractClient::sendCustomRequest()} accepts an arbitrary Response class.
 */
final class CustomNoteResponse extends Base
{
    public string $note;

    /** @inheritDoc */
    protected function processResponseBody(array|string|bool|int|float|null $deserializedResponseBody): void
    {
        $this->checkResponseType($deserializedResponseBody, 'array');
        $this->checkResponseStructure($deserializedResponseBody, ['note']);

        $this->note = $deserializedResponseBody['note'];
    }
}
