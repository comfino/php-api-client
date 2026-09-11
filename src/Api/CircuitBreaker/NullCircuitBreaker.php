<?php

/**
 * ComfinoPay PHP API client
 *
 * Backend routines for communication with the ComfinoPay payment gateway REST API.
 *
 * @package Comfino\Api\CircuitBreaker
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-api-client
 */

declare(strict_types=1);

namespace Comfino\Api\CircuitBreaker;

/**
 * Breaker that is never open. The default, so that adding a breaker to the client is opt-in and no existing host
 * changes behavior by upgrading.
 */
final class NullCircuitBreaker implements CircuitBreakerInterface
{
    /** @inheritDoc */
    public function isOpen(string $key): bool
    {
        return false;
    }

    /** @inheritDoc */
    public function recordSuccess(string $key): void
    {
    }

    /** @inheritDoc */
    public function recordFailure(string $key): void
    {
    }
}
