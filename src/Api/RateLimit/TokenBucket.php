<?php

/**
 * ComfinoPay PHP API client
 *
 * Backend routines for communication with the ComfinoPay payment gateway REST API.
 *
 * @package Comfino\Api\RateLimit
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-api-client
 */

declare(strict_types=1);

namespace Comfino\Api\RateLimit;

/**
 * Immutable token-bucket state: how many tokens remain, and when that count was last recomputed.
 */
final class TokenBucket
{
    /**
     * @param float $tokens Tokens available at $updatedAt
     * @param float $updatedAt Unix timestamp the token count refers to
     */
    public function __construct(public readonly float $tokens, public readonly float $updatedAt)
    {
    }
}
