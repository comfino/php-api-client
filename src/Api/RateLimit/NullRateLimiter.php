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
 * Limiter that accepts everything. The default, so that adding a limiter to the client is opt-in.
 */
final class NullRateLimiter implements OutboundRateLimiterInterface
{
    /** @inheritDoc */
    public function reserve(string $key, int $tokens = 1): Reservation
    {
        return Reservation::accepted(PHP_INT_MAX);
    }
}
