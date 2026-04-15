<?php

/**
 * Comfino PHP API client
 *
 * Backend routines for communication with the Comfino payment gateway REST API.
 *
 * @package Comfino\Api\Retry
 * @author Artur Kozubski <akozubski@comperia.pl>
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
 * Retry logic:
 * - NetworkExceptionInterface (connection/DNS/timeout errors thrown by any PSR-18 client)
 * - ClientExceptionInterface with code CURLE_OPERATION_TIMEDOUT (28) - belt-and-suspenders for adapters that wrap
 *   platform curl clients and may not always produce a NetworkExceptionInterface (e.g., when the underlying client
 *   throws a generic exception on timeout that the adapter re-codes as 28).
 */
final class Psr18ErrorDetector
{
    /** cURL error code for operation/transfer timeout (CURLE_OPERATION_TIMEDOUT). */
    private const CURL_TIMEOUT_CODE = 28;

    /**
     * Determines if the given PSR-18 exception is retryable.
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
