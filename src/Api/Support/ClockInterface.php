<?php

/**
 * Comfino PHP API client
 *
 * Backend routines for communication with the Comfino payment gateway REST API.
 *
 * @package Comfino\Api\Support
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-api-client
 */

declare(strict_types=1);

namespace Comfino\Api\Support;

/**
 * Minimal clock abstraction used by the retry backoff, the outbound rate limiter, and the circuit breaker.
 *
 * A dedicated interface (instead of PSR-20, which would add a dependency for a single method) keeps the library
 * dependency-free while still letting tests drive time deterministically instead of sleeping.
 */
interface ClockInterface
{
    /**
     * Returns the current Unix timestamp as a float, with sub-second precision.
     */
    public function now(): float;
}
