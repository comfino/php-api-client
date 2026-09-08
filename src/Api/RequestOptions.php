<?php

/**
 * ComfinoPay PHP API client
 *
 * Backend routines for communication with the ComfinoPay payment gateway REST API.
 *
 * @package Comfino\Api
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-api-client
 */

declare(strict_types=1);

namespace Comfino\Api;

use Comfino\Api\Retry\TimeoutConfig;
use InvalidArgumentException;

/**
 * Immutable per-call options: everything a request needs to know about *how* it should be sent.
 *
 * The counterpart to {@see ApiContext}. Timeouts and attempt counts belong here rather than on the client because they
 * are properties of a call site, not of a tenant: an availability probe wants two attempts, the probe inside a checkout
 * POST wants one, an order creation wants one plus an idempotency key. Getting that today means rebuilding the whole
 * client per call site; with these options one client instance serves them all.
 */
final class RequestOptions
{
    /**
     * @param TimeoutConfig|null $timeouts Timeouts for this call, overriding the retry policy's schedule
     * @param int|null $maxAttempts Attempt ceiling for this call; 1 means fail fast
     * @param int|null $apiVersion API version to target, overriding the client's default
     * @param OnLimit $onLimit What to do when the outbound limiter rejects this call
     * @param int $limiterTokens Cost of this call against the outbound limiter
     *
     * @throws InvalidArgumentException If the attempt ceiling is below 1 or the token cost is below 1
     */
    public function __construct(
        public readonly ?TimeoutConfig $timeouts = null,
        public readonly ?int $maxAttempts = null,
        public readonly ?int $apiVersion = null,
        public readonly OnLimit $onLimit = OnLimit::FailFast,
        public readonly int $limiterTokens = 1
    ) {
        if ($this->maxAttempts !== null && $this->maxAttempts < 1) {
            throw new InvalidArgumentException('Maximum attempts must be at least 1.');
        }

        if ($this->limiterTokens < 1) {
            throw new InvalidArgumentException('Limiter token cost must be at least 1.');
        }
    }

    /**
     * Options that cap this call at the given number of attempts.
     *
     * @param int $maxAttempts Attempt ceiling
     */
    public static function attempts(int $maxAttempts): self
    {
        return new self(maxAttempts: $maxAttempts);
    }

    /**
     * Options for a call that must not be retried: one attempt, surface whatever happens.
     */
    public static function failFast(): self
    {
        return new self(maxAttempts: 1);
    }

    /**
     * Options that pin the timeouts for this call.
     *
     * @param TimeoutConfig $timeouts Connection and transfer timeouts
     */
    public static function withTimeouts(TimeoutConfig $timeouts): self
    {
        return new self(timeouts: $timeouts);
    }

    /**
     * Returns a copy with the given attempt ceiling.
     *
     * @param int|null $maxAttempts Attempt ceiling, or null for the policy default
     */
    public function andAttempts(?int $maxAttempts): self
    {
        return new self($this->timeouts, $maxAttempts, $this->apiVersion, $this->onLimit, $this->limiterTokens);
    }

    /**
     * Returns a copy with the given limiter-rejection behavior.
     *
     * @param OnLimit $onLimit What to do when the outbound limiter rejects this call
     */
    public function andOnLimit(OnLimit $onLimit): self
    {
        return new self($this->timeouts, $this->maxAttempts, $this->apiVersion, $onLimit, $this->limiterTokens);
    }

    /**
     * Returns a copy targeting the given API version.
     *
     * @param int|null $apiVersion API version, or null for the client's default
     */
    public function andApiVersion(?int $apiVersion): self
    {
        return new self($this->timeouts, $this->maxAttempts, $apiVersion, $this->onLimit, $this->limiterTokens);
    }
}
