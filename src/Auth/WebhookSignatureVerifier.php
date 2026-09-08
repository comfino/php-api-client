<?php

/**
 * ComfinoPay PHP API client
 *
 * Backend routines for communication with the ComfinoPay payment gateway REST API.
 *
 * @package Comfino\Auth
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-api-client
 */

declare(strict_types=1);

namespace Comfino\Auth;

/**
 * Verifies the CR-Signature header on incoming ComfinoPay webhook requests.
 * Uses hash_equals() for timing-safe comparison.
 */
final class WebhookSignatureVerifier
{
    /**
     * Verifies the CR-Signature header on incoming ComfinoPay webhook requests. An empty API key is rejected outright:
     * it would reduce the expected signature to a hash of the payload alone, which any caller can compute without
     * knowing a secret, so every forged request would verify. Host platforms commonly resolve an unconfigured key
     * field to an empty string, which makes this the difference between failing closed and an authentication bypass.
     *
     * @param string $signature The signature header value
     * @param string $apiKey The API key
     * @param string $payload The received request payload to verify
     *
     * @return bool True if the signature is valid, false otherwise
     */
    public function verify(string $signature, string $apiKey, string $payload): bool
    {
        if ($signature === '' || $apiKey === '') {
            return false;
        }

        return hash_equals(hash('sha3-256', $apiKey . $payload), $signature);
    }
}
