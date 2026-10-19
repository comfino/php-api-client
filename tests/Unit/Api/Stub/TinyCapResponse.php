<?php

/**
 * ComfinoPay PHP API client
 *
 * Backend routines for communication with the ComfinoPay payment gateway REST API.
 *
 * @package Comfino\Tests\Unit\Api\Stub
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-api-client
 */

declare(strict_types=1);

namespace Comfino\Tests\Unit\Api\Stub;

use Comfino\Api\Response\Base;

/**
 * A response whose body cap is small enough to reach in a test, exercising the documented per-subclass override.
 *
 * The boundary cases - a body exactly at the cap, a body one byte over - are the ones worth pinning, and pinning them
 * against the real 8 MB default would mean allocating 8 MB per assertion to prove the reader avoids allocating too
 * much. Overriding the constant tests the same branches, and tests that the override works at all.
 */
class TinyCapResponse extends Base
{
    public const MAX_RESPONSE_BODY_BYTES = 1024;

    /** @var string|null The body as it was actually read */
    public ?string $body = null;

    /** @inheritDoc */
    protected function processResponseBody(array|string|int|float|bool|null $deserializedResponseBody): void
    {
        $this->body = is_string($deserializedResponseBody) ? $deserializedResponseBody : null;
    }
}
