<?php

/**
 * Comfino PHP API client
 *
 * Backend routines for communication with the Comfino payment gateway REST API.
 *
 * @package Comfino\Auth
 * @author Artur Kozubski <akozubski@comperia.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-api-client
 */

declare(strict_types=1);

namespace Comfino\Auth;

/**
 * Verifies the CR-Signature header on incoming Comfino webhook requests.
 * Uses hash_equals() for timing-safe comparison.
 */
final class WebhookSignatureVerifier
{
    /**
     * Verifies the CR-Signature header on incoming Comfino webhook requests.
     *
     * @param string $signature The signature header value
     * @param string $apiKey The API key
     * @param string $payload The received request payload to verify
     *
     * @return bool True if the signature is valid, false otherwise
     */
    public function verify(string $signature, string $apiKey, string $payload): bool
    {
        return hash_equals(hash('sha3-256', $apiKey . $payload), $signature);
    }
}
