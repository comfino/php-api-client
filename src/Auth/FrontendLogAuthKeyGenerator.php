<?php

/**
 * Comfino PHP API client
 *
 * Backend routines for communication with the Comfino payment gateway REST API.
 *
 * @package Comfino\Auth
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-api-client
 */

declare(strict_types=1);

namespace Comfino\Auth;

/**
 * HMAC-signed auth token generator for the Comfino frontend error reporting endpoint.
 *
 * This is a sibling of {@see PaywallAuthKeyGenerator}, deliberately versioned and domain-separated so
 * that:
 *   - the backend validator can change verification rules (TTL, algorithm) by bumping the version WITHOUT re-minting in
 *     already-installed shop plugins, and
 *   - a logging token can never be accepted as a paywall token, nor vice versa (the domain string is mixed into the
 *     HMAC input).
 *
 * Payload layout (binary, then sodium-base64 encoded):
 *   Byte 0: Version, unsigned 8-bit integer (currently 1)
 *   Bytes 1-8: Unix timestamp, unsigned 64-bit big-endian (8 bytes)
 *   Bytes 9-40: HMAC-SHA3-256(DOMAIN || version || timestamp || widgetKey_UTF8, apiKey), raw (32 bytes)
 *   Bytes 41-76: widgetKey UTF-8 string (UUIDv4, 36 bytes)
 *
 * Total: 77 bytes. The secret apiKey never reaches the browser — only this derived token does.
 */
final class FrontendLogAuthKeyGenerator
{
    /** Current token format version. Bump when the layout or HMAC input changes. */
    public const VERSION = 1;

    /** HMAC domain-separation tag. Mixed into the signed message so the token is purpose-bound. */
    public const DOMAIN = 'comfino-fe-log:v1';

    /**
     * Generates an HMAC-signed auth token for the Comfino frontend error reporting endpoint.
     *
     * @param string $widgetKey Unique widget key for Comfino account (36 characters, UUIDv4)
     * @param string $signingKey Plugin access token from POST /v1/error-logging-token (64-char hex)
     *
     * @return string Base64-encoded auth token
     *
     * @throws \SodiumException
     */
    public function generateToken(string $widgetKey, string $signingKey): string
    {
        $versionByte = pack('C', self::VERSION); // 1 byte
        $timestampBytes = pack('J', time()); // 8 bytes, big-endian uint64
        $hmac = hash_hmac(
            'sha3-256',
            self::DOMAIN . $versionByte . $timestampBytes . $widgetKey,
            $signingKey,
            true
        ); // 32 bytes raw

        return sodium_bin2base64($versionByte . $timestampBytes . $hmac . $widgetKey, SODIUM_BASE64_VARIANT_ORIGINAL);
    }
}
