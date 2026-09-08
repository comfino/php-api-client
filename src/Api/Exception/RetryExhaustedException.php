<?php

/**
 * ComfinoPay PHP API client
 *
 * Backend routines for communication with the ComfinoPay payment gateway REST API.
 *
 * @package Comfino\Api\Exception
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-api-client
 */

declare(strict_types=1);

namespace Comfino\Api\Exception;

use Comfino\Api\Retry\RetryExhaustionReason;
use Comfino\Api\Retry\TimeoutConfig;
use RuntimeException;
use Throwable;

/**
 * Exception thrown when retry attempts are exhausted.
 *
 * Carries the diagnostic payload a timeout report needs - attempt count, final timeouts, request URI and body - plus
 * the {@see RetryExhaustionReason} that says which budget ran out. The client converts it into a
 * {@see ConnectionTimeout} so that plugins can keep handling one exception type without knowing about retry internals.
 */
class RetryExhaustedException extends RuntimeException
{
    private ?string $requestUri = null;
    private ?string $requestBody = null;

    /**
     * @param Throwable|null $originalError Original error that caused the retry exhaustion
     * @param int $attemptCount Number of retry attempts made
     * @param TimeoutConfig|null $lastTimeoutConfig Configuration for the last retry attempt
     * @param RetryExhaustionReason $reason Which budget ran out
     */
    public function __construct(
        private readonly ?Throwable $originalError,
        private readonly int $attemptCount,
        private readonly ?TimeoutConfig $lastTimeoutConfig = null,
        private readonly RetryExhaustionReason $reason = RetryExhaustionReason::AttemptsExhausted
    ) {
        parent::__construct($this->buildMessage(), $originalError?->getCode() ?? 0, $originalError);
    }

    public function getOriginalError(): ?Throwable
    {
        return $this->originalError;
    }

    public function getAttemptCount(): int
    {
        return $this->attemptCount;
    }

    public function getLastTimeoutConfig(): ?TimeoutConfig
    {
        return $this->lastTimeoutConfig;
    }

    /**
     * Returns which budget ran out: the attempt count, the transfer-time budget, or neither because the request could
     * not be replayed safely.
     */
    public function getReason(): RetryExhaustionReason
    {
        return $this->reason;
    }

    /**
     * Returns a new instance of RetryExhaustedException with additional request context.
     *
     * @param Throwable|null $originalError Original error that caused the retry exhaustion
     * @param int $attemptCount Number of retry attempts made
     * @param TimeoutConfig|null $lastTimeoutConfig Configuration for the last retry attempt
     * @param string|null $requestUri URI of the request that failed
     * @param string|null $requestBody Body of the request that failed
     * @param RetryExhaustionReason $reason Which budget ran out
     */
    public static function withRequestContext(
        ?Throwable $originalError,
        int $attemptCount,
        ?TimeoutConfig $lastTimeoutConfig,
        ?string $requestUri = null,
        ?string $requestBody = null,
        RetryExhaustionReason $reason = RetryExhaustionReason::AttemptsExhausted
    ): self {
        $exception = new self($originalError, $attemptCount, $lastTimeoutConfig, $reason);

        if ($requestUri !== null) {
            $exception->requestUri = $requestUri;
        }

        if ($requestBody !== null) {
            $exception->requestBody = $requestBody;
        }

        return $exception;
    }

    public function getRequestUri(): ?string
    {
        return $this->requestUri;
    }

    public function getRequestBody(): ?string
    {
        return $this->requestBody;
    }

    private function buildMessage(): string
    {
        $parts = [
            sprintf(
                'Request failed after %d attempt(s) (%s)',
                $this->attemptCount,
                str_replace('_', ' ', $this->reason->value)
            ),
        ];

        if ($this->lastTimeoutConfig !== null) {
            $parts[] = sprintf(
                'Final timeouts: connection=%ds, transfer=%ds',
                $this->lastTimeoutConfig->connectionTimeout,
                $this->lastTimeoutConfig->transferTimeout
            );
        }

        if ($this->originalError !== null) {
            $parts[] = sprintf('Original error: %s', $this->originalError->getMessage());
        }

        return implode('. ', $parts) . '.';
    }
}
