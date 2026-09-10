<?php

/**
 * ComfinoPay PHP API client
 *
 * Backend routines for communication with the ComfinoPay payment gateway REST API.
 *
 * @package Comfino\Api\Request
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-api-client
 */

declare(strict_types=1);

namespace Comfino\Api\Request;

use Comfino\Api\Request;

/**
 * Claims a short-lived plugin access token for authenticating error-logging requests to CETS.
 *
 * The monolith validates the shop's API key (via the Api-Key header, added automatically by AbstractClient), issues a
 * 64-char hex access_token, and returns its expiry. The plugin must cache the token and re-claim before expiry. HMAC
 * signing of error-log requests to CETS uses the access_token as the key instead of the permanent API key, so the API
 * key never reaches CETS.
 */
class ClaimErrorLoggingToken extends Request
{
    public function __construct()
    {
        $this->setRequestMethod('POST');
        $this->setApiEndpointPath('error-logging-token');
    }

    /** @inheritDoc */
    protected function prepareRequestBody(): ?array
    {
        return null;
    }
}
