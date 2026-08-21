<?php

/**
 * Comfino PHP API client
 *
 * Backend routines for communication with the Comfino payment gateway REST API.
 *
 * @package Comfino\Api\Retry
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-api-client
 */

declare(strict_types=1);

namespace Comfino\Api\Retry;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Throwable;

/**
 * Error detector for PSR-18 exceptions.
 *
 * @deprecated Since 3.0.0. Use {@see ErrorClassifier}, which this class now delegates to. The detector answered
 *             transport failures only, so an inline call gave up on a 429 or a 503 exactly where the SDK's queue-side
 *             classifier would have kept going - a disagreement about the meaning of "transient" that was invisible
 *             from either side. It also returned a bare boolean, which cannot carry a `Retry-After`.
 */
final class Psr18ErrorDetector
{
    /** cURL error code for operation/transfer timeout (CURLE_OPERATION_TIMEDOUT). */
    private const CURL_TIMEOUT_CODE = 28;

    /**
     * Determines if the given PSR-18 exception is retryable.
     *
     * Transport-level failures only, as before: an HTTP status is deliberately not consulted here, so that upgrading
     * this library cannot change what an existing caller of this method retries. Use {@see ErrorClassifier} for the
     * full verdict.
     *
     * @param Throwable $e PSR-18 exception
     *
     * @return bool True if the exception is retryable, false otherwise
     */
    public function isRetryable(Throwable $e): bool
    {
        // Primary: proper PSR-18 network-level exception (connection refused, DNS, timeout, etc.).
        if ($e instanceof NetworkExceptionInterface) {
            return true;
        }

        /* Secondary: ClientExceptionInterface explicitly coded as curl timeout (code 28). This catches
           adapters that rethrow platform-specific timeout exceptions using the curl error code but do
           not implement NetworkExceptionInterface. */
        if ($e instanceof ClientExceptionInterface && $e->getCode() === self::CURL_TIMEOUT_CODE) {
            return true;
        }

        return false;
    }
}
