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
 * Time-limited HMAC-signed auth token generator for the Comfino Paywall V3 iframe API endpoint.
 *
 * Payload layout (binary, then sodium-base64 encoded):
 *   Bytes 0-7: Unix timestamp, unsigned 64-bit big-endian (8 bytes)
 *   Bytes 8-39: HMAC-SHA3-256(timestamp_bytes || widgetKey_UTF8, apiKey), raw binary (32 bytes)
 *   Bytes 40-75: widgetKey UTF-8 string (UUIDv4, 36 bytes)
 *
 * Total: 76 bytes → ~104 chars base64-encoded.
 * Token lifetime: 15 minutes (enforced server-side).
 */
final class PaywallAuthKeyGenerator
{
    /**
     * Generates a time-limited HMAC-signed auth token for the Comfino Paywall V3 iframe.
     *
     * @param string $widgetKey Unique widget key for Comfino account (36 characters, UUIDv4)
     * @param string $apiKey Unique authentication API key for Comfino account
     *
     * @return string Base64-encoded auth token
     *
     * @throws \SodiumException
     */
    public function generateAuthKey(string $widgetKey, string $apiKey): string
    {
        $timestampBytes = pack('J', time()); // 8 bytes, big-endian uint64
        $hmac = hash_hmac('sha3-256', $timestampBytes . $widgetKey, $apiKey, true); // 32 bytes raw

        return sodium_bin2base64($timestampBytes . $hmac . $widgetKey, SODIUM_BASE64_VARIANT_ORIGINAL);
    }
}
