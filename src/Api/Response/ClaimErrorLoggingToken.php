<?php

/**
 * ComfinoPay PHP API client
 *
 * Backend routines for communication with the ComfinoPay payment gateway REST API.
 *
 * @package Comfino\Api\Response
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-api-client
 */

declare(strict_types=1);

namespace Comfino\Api\Response;

/**
 * Response from POST /v1/error-logging-token containing the plugin access token.
 *
 * The plugin must:
 * - Store $accessToken as the HMAC key for all subsequent error-logging requests to CETS;
 * - Cache $expiresAt (ISO 8601) and re-claim before the token expires (TTL 24 h);
 * - Fall back to the still-cached (expired) token when the monolith is temporarily unreachable
 * - CETS accepts tokens within its grace window (TTL + 48 h).
 */
class ClaimErrorLoggingToken extends Base
{
    /** 64-char lowercase hex string used as the HMAC-SHA3-256 signing key for error-logging requests to CETS. */
    public readonly string $accessToken;

    /** ISO 8601 expiry timestamp (e.g. "2026-06-25T12:00:00+00:00"). Re-claim before this time. */
    public readonly string $expiresAt;

    /** @inheritDoc */
    protected function processResponseBody(array|string|bool|null|float|int $deserializedResponseBody): void
    {
        $this->checkResponseType($deserializedResponseBody, 'array');
        $this->checkResponseStructure($deserializedResponseBody, ['access_token', 'expires_at']);
        $this->checkResponseType($deserializedResponseBody['access_token'], 'string', 'access_token');
        $this->checkResponseType($deserializedResponseBody['expires_at'], 'string', 'expires_at');

        $this->accessToken = $deserializedResponseBody['access_token'];
        $this->expiresAt = $deserializedResponseBody['expires_at'];
    }
}
