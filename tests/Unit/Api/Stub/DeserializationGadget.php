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

/**
 * Stands in for the gadget class an object-injection payload would name, and counts its own construction.
 *
 * `msgpack_unpack()` with `msgpack.php_only` on runs `__wakeup()` *during* the unpack, so a test that only inspects the
 * returned value cannot tell "the payload was refused" from "the payload ran and then the result was rejected". The
 * counter can: it stays at zero only if nothing ever handed this payload to an object-deserializing parser.
 */
class DeserializationGadget
{
    /** @var int Times a payload managed to construct this class since the last reset. */
    public static int $constructed = 0;

    public string $note = 'attacker-controlled';

    /** Resets the counter, so each test starts from a known state. */
    public static function reset(): void
    {
        self::$constructed = 0;
    }

    public function __wakeup(): void
    {
        self::$constructed++;
    }
}
