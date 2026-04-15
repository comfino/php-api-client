<?php

/**
 * Comfino PHP API client
 *
 * Backend routines for communication with the Comfino payment gateway REST API.
 *
 * @package Comfino\Api\Exception
 * @author Artur Kozubski <akozubski@comperia.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-api-client
 */

declare(strict_types=1);

namespace Comfino\Api\Exception;

use Comfino\Api\Retry\TimeoutConfig;
use RuntimeException;
use Throwable;

/**
 * Exception thrown when retry attempts are exhausted.
 */
class RetryExhaustedException extends RuntimeException
{
    private ?string $requestUri = null;
    private ?string $requestBody = null;

    /**
     * @param Throwable|null $originalError Original error that caused the retry exhaustion
     * @param int $attemptCount Number of retry attempts made
     * @param TimeoutConfig|null $lastTimeoutConfig Configuration for the last retry attempt
     */
    public function __construct(
        private readonly ?Throwable $originalError,
        private readonly int $attemptCount,
        private readonly ?TimeoutConfig $lastTimeoutConfig = null
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
     * Returns a new instance of RetryExhaustedException with additional request context.
     *
     * @param Throwable|null $originalError Original error that caused the retry exhaustion
     * @param int $attemptCount Number of retry attempts made
     * @param TimeoutConfig|null $lastTimeoutConfig Configuration for the last retry attempt
     * @param string|null $requestUri URI of the request that failed
     * @param string|null $requestBody Body of the request that failed
     */
    public static function withRequestContext(
        ?Throwable $originalError,
        int $attemptCount,
        ?TimeoutConfig $lastTimeoutConfig,
        ?string $requestUri = null,
        ?string $requestBody = null
    ): self {
        $exception = new self($originalError, $attemptCount, $lastTimeoutConfig);

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
        $parts = [sprintf('Request failed after %d attempt(s).', $this->attemptCount)];

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
