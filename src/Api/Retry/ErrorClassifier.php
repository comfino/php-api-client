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

use Comfino\Api\Exception\ConnectionTimeout;
use Comfino\Api\HttpErrorExceptionInterface;
use Comfino\Api\Support\ClockInterface;
use Comfino\Api\Support\SystemClock;
use DateTimeImmutable;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * Single source of truth for "is this failure transient?", shared by the inline retry executor and by queue-side classification in the SDK. The verdict is
 * typed rather than boolean because a 429 or a 503 can carry a `Retry-After`, and honoring it is the difference between backing off and hammering.
 *
 * Transient: any PSR-18 {@see NetworkExceptionInterface}; a {@see ClientExceptionInterface} coded as cURL's `CURLE_OPERATION_TIMEDOUT` (28), for adapters that
 * re-code a platform timeout without implementing the PSR-18 network interface; {@see ConnectionTimeout}, raised once this library's own retry budget is spent;
 * HTTP 429, 502, 503 and 504; and HTTP 500, but only for a request declared idempotent, since a failed non-idempotent POST may already have been applied
 * server-side. Everything else - 4xx other than 429, validation and authorization errors - is fatal: a 401 is a configuration problem, not a transient one.
 */
final class ErrorClassifier
{
    /** cURL error code for operation/transfer timeout (CURLE_OPERATION_TIMEDOUT). */
    public const CURL_TIMEOUT_CODE = 28;

    /** HTTP statuses that are transient regardless of what the request was. */
    public const RETRYABLE_STATUS_CODES = [429, 502, 503, 504];

    /** HTTP statuses that are transient only when replaying the request cannot duplicate a side effect. */
    public const IDEMPOTENT_ONLY_RETRYABLE_STATUS_CODES = [500];

    /**
     * Ceiling applied to a `Retry-After` value, in milliseconds. A hostile or simply wrong header must not be able to
     * park a worker for an hour, so anything longer is treated as this ceiling.
     */
    public const DEFAULT_MAX_RETRY_AFTER_MS = 30000;

    private readonly ClockInterface $clock;

    /**
     * @param int $maxRetryAfterMs Ceiling for a `Retry-After` value, in milliseconds
     * @param ClockInterface|null $clock Clock used to resolve the HTTP-date form of `Retry-After`
     */
    public function __construct(
        private readonly int $maxRetryAfterMs = self::DEFAULT_MAX_RETRY_AFTER_MS,
        ?ClockInterface $clock = null
    ) {
        $this->clock = $clock ?? new SystemClock();
    }

    /**
     * Classifies a failure. Anything that is not a {@see Throwable} is fatal - there is nothing to inspect.
     *
     * @param mixed $error The error encountered during the API call
     * @param bool $requestIsIdempotent Whether replaying the request is free of side effects
     */
    public function classify(mixed $error, bool $requestIsIdempotent = true): Classification
    {
        if (!$error instanceof Throwable) {
            return Classification::fatal();
        }

        if ($error instanceof RetryableResponse) {
            return $this->classifyResponse($error->getResponse(), $requestIsIdempotent);
        }

        if ($error instanceof NetworkExceptionInterface || $error instanceof ConnectionTimeout) {
            return Classification::retry();
        }

        if ($error instanceof ClientExceptionInterface && $error->getCode() === self::CURL_TIMEOUT_CODE) {
            return Classification::retry();
        }

        if ($error instanceof HttpErrorExceptionInterface) {
            return $this->classifyStatusCode($error->getStatusCode(), null, $requestIsIdempotent);
        }

        return Classification::fatal();
    }

    /**
     * Classifies an HTTP response that came back intact but may still be worth another attempt.
     *
     * Needed because this library maps a status code to a typed exception only after the transport call returns, so a
     * 503 never reaches the retry loop as a {@see Throwable}. The loop inspects the response instead.
     *
     * @param ResponseInterface $response Response returned by the transport
     * @param bool $requestIsIdempotent Whether replaying the request is free of side effects
     */
    public function classifyResponse(ResponseInterface $response, bool $requestIsIdempotent = true): Classification
    {
        return $this->classifyStatusCode(
            $response->getStatusCode(),
            $response->hasHeader('Retry-After') ? $response->getHeaderLine('Retry-After') : null,
            $requestIsIdempotent
        );
    }

    /**
     * Convenience predicate for callers that only need a yes/no answer.
     *
     * @param mixed $error The error encountered during the API call
     * @param bool $requestIsIdempotent Whether replaying the request is free of side effects
     */
    public function isRetryable(mixed $error, bool $requestIsIdempotent = true): bool
    {
        return $this->classify($error, $requestIsIdempotent)->isRetryable();
    }

    /**
     * True only when $error matches an explicit transient rule: a PSR-18 network error, a cURL operation timeout, {@see ConnectionTimeout},
     * HTTP 429 or any HTTP 5xx (also as a {@see RetryableResponse}). An unknown throwable returns false.
     *
     * Not the same question as {@see isRetryable()}: that one answers "may this be replayed" and a request-declared non-idempotent POST
     * answers no to a 500, while error reporting asks "is this a temporary outage rather than a defect". Neither falls back to a catch-all,
     * so a `TypeError` in host code is never reported as transient.
     *
     * @param mixed $error The error encountered during the API call
     */
    public function isTransient(mixed $error): bool
    {
        if ($error instanceof RetryableResponse) {
            $statusCode = $error->getResponse()->getStatusCode();

            return $statusCode === 429 || $statusCode >= 500;
        }

        if ($error instanceof NetworkExceptionInterface || $error instanceof ConnectionTimeout) {
            return true;
        }

        if ($error instanceof ClientExceptionInterface && $error->getCode() === self::CURL_TIMEOUT_CODE) {
            return true;
        }

        if ($error instanceof HttpErrorExceptionInterface) {
            $statusCode = $error->getStatusCode();

            return $statusCode === 429 || $statusCode >= 500;
        }

        return false;
    }

    /**
     * Parses a `Retry-After` header value in either RFC 9110 form - delta-seconds or an HTTP date - and returns the
     * delay in milliseconds, clamped to {@see $maxRetryAfterMs}. Returns null for a value that is neither.
     *
     * @param string $headerValue Raw header value
     */
    public function parseRetryAfter(string $headerValue): ?int
    {
        $headerValue = trim($headerValue);

        if ($headerValue === '') {
            return null;
        }

        if (preg_match('/^\d+$/', $headerValue) === 1) {
            return $this->clampRetryAfter(((int) $headerValue) * 1000);
        }

        $date = false;

        foreach (['D, d M Y H:i:s \G\M\T', 'D, d-M-y H:i:s \G\M\T', 'D M j H:i:s Y'] as $format) {
            if (($date = DateTimeImmutable::createFromFormat($format, $headerValue)) !== false) {
                break;
            }
        }

        if ($date === false) {
            return null;
        }

        return $this->clampRetryAfter((int) round(($date->getTimestamp() - $this->clock->now()) * 1000));
    }

    /**
     * Maps an HTTP status code (plus an optional `Retry-After`) onto a verdict.
     *
     * @param int $statusCode HTTP status code
     * @param string|null $retryAfterHeader Raw `Retry-After` header value, when the response carried one
     * @param bool $requestIsIdempotent Whether replaying the request is free of side effects
     */
    private function classifyStatusCode(
        int $statusCode,
        ?string $retryAfterHeader,
        bool $requestIsIdempotent
    ): Classification {
        $retryable = in_array($statusCode, self::RETRYABLE_STATUS_CODES, true) ||
            ($requestIsIdempotent && in_array($statusCode, self::IDEMPOTENT_ONLY_RETRYABLE_STATUS_CODES, true));

        if (!$retryable) {
            return $statusCode >= 200 && $statusCode < 400 ? Classification::success() : Classification::fatal();
        }

        if ($retryAfterHeader !== null && ($retryAfterMs = $this->parseRetryAfter($retryAfterHeader)) !== null) {
            return Classification::retryAfter($retryAfterMs);
        }

        return Classification::retry();
    }

    /**
     * Clamps a parsed `Retry-After` delay into the [0, maxRetryAfterMs] range.
     *
     * @param int $retryAfterMs Parsed delay in milliseconds
     */
    private function clampRetryAfter(int $retryAfterMs): int
    {
        return max(0, min($retryAfterMs, $this->maxRetryAfterMs));
    }
}
