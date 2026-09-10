<?php

/**
 * ComfinoPay PHP API client
 *
 * Backend routines for communication with the ComfinoPay payment gateway REST API.
 *
 * @package Comfino\Api\Support
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-api-client
 */

declare(strict_types=1);

namespace Comfino\Api\Support;

/**
 * Clock whose value only moves when a test moves it. Lets the retry, limiter, and breaker tests assert time-dependent
 * behavior without sleeping.
 */
final class FrozenClock implements ClockInterface
{
    public function __construct(private float $now = 0.0)
    {
    }

    /** @inheritDoc */
    public function now(): float
    {
        return $this->now;
    }

    /**
     * Moves the clock forward by the given number of seconds (fractions allowed).
     */
    public function advance(float $seconds): void
    {
        $this->now += $seconds;
    }

    /**
     * Pins the clock to an absolute Unix timestamp.
     */
    public function set(float $now): void
    {
        $this->now = $now;
    }
}
