<?php

/**
 * ComfinoPay PHP API client
 *
 * Backend routines for communication with the ComfinoPay payment gateway REST API.
 *
 * @package Comfino\Api\Retry
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-api-client
 */

declare(strict_types=1);

namespace Comfino\Api\Retry;

/**
 * The classifier's answer for one error or response: whether to retry, and if the far side asked us to wait, for how long.
 */
final class Classification
{
    /**
     * @param RetryVerdict $verdict What to do with the call this classification describes
     * @param int|null $retryAfterMs Delay requested by the response (Retry-After), already clamped, or null when the response carried no such hint
     */
    public function __construct(
        public readonly RetryVerdict $verdict,
        public readonly ?int $retryAfterMs = null
    ) {
    }

    public static function retry(): self
    {
        return new self(RetryVerdict::Retry);
    }

    public static function retryAfter(int $retryAfterMs): self
    {
        return new self(RetryVerdict::RetryAfter, $retryAfterMs);
    }

    public static function fatal(): self
    {
        return new self(RetryVerdict::Fatal);
    }

    public static function success(): self
    {
        return new self(RetryVerdict::Success);
    }

    /**
     * True when the call may be attempted again.
     */
    public function isRetryable(): bool
    {
        return $this->verdict === RetryVerdict::Retry || $this->verdict === RetryVerdict::RetryAfter;
    }
}
