<?php

/**
 * Comfino PHP API client
 *
 * Backend routines for communication with the Comfino payment gateway REST API.
 *
 * @package Comfino\Api\RateLimit
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-api-client
 */

declare(strict_types=1);

namespace Comfino\Api\RateLimit;

/**
 * The limiter's answer for one reservation attempt.
 */
final class Reservation
{
    /**
     * @param bool $accepted Whether the call may proceed
     * @param int $retryAfterMs How long until capacity is expected to be available; 0 when accepted
     * @param int $remaining Tokens left in the bucket after this reservation
     */
    public function __construct(
        public readonly bool $accepted,
        public readonly int $retryAfterMs = 0,
        public readonly int $remaining = 0
    ) {
    }

    public static function accepted(int $remaining): self
    {
        return new self(true, 0, $remaining);
    }

    public static function rejected(int $retryAfterMs): self
    {
        return new self(false, $retryAfterMs, 0);
    }
}
