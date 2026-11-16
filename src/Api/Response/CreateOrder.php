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

use Comfino\Api\Exception\ResponseValidationError;
use Comfino\Api\Validation\UrlValidator;

/**
 * Response from the API to the order (loan application) creation request.
 */
class CreateOrder extends Base
{
    /** @var string Created order status */
    public readonly string $status;
    /** @var string Unique identifier of the order in the merchant system sent in the order creation request */
    public readonly string $externalId;
    /** @var string URL of the ComfinoPay payment gateway application page where the shop platform must redirect the customer */
    public readonly string $applicationUrl;

    /** @inheritDoc */
    protected function processResponseBody(array|string|bool|null|float|int $deserializedResponseBody): void
    {
        $this->checkResponseType($deserializedResponseBody, 'array');
        $this->checkResponseStructure($deserializedResponseBody, ['status', 'externalId', 'applicationUrl']);
        $this->checkResponseType($deserializedResponseBody['status'], 'string', 'status');
        $this->checkResponseType($deserializedResponseBody['externalId'], 'string', 'externalId');
        $this->checkResponseType($deserializedResponseBody['applicationUrl'], 'string', 'applicationUrl');

        self::checkApplicationUrl($deserializedResponseBody['applicationUrl']);

        $this->status = $deserializedResponseBody['status'];
        $this->externalId = $deserializedResponseBody['externalId'];
        $this->applicationUrl = $deserializedResponseBody['applicationUrl'];
    }

    /**
     * Whether the application page is on a ComfinoPay domain (see {@see UrlValidator::isComfinoHost()}).
     *
     * Not enforced by the response: the set of hosts the application flow may redirect to has not been confirmed with
     * the API owners, so a host that wants to refuse a foreign one opts in here.
     */
    public function hasTrustedApplicationUrl(): bool
    {
        return UrlValidator::isComfinoHost($this->applicationUrl);
    }

    /**
     * Plugins hand `applicationUrl` to a browser redirect, so a spoofed upstream must not be able to send shoppers to a `javascript:` or
     * `data:` URI or a scheme-relative address. Only an absolute `https` URL passes; plain `http` only in a development environment.
     *
     * @throws ResponseValidationError
     */
    private static function checkApplicationUrl(string $applicationUrl): void
    {
        $parts = parse_url($applicationUrl);
        $scheme = is_array($parts) && isset($parts['scheme']) ? strtolower($parts['scheme']) : '';

        if (!is_array($parts) || !isset($parts['host']) || $parts['host'] === '' ||
            !($scheme === 'https' || ($scheme === 'http' && UrlValidator::devEnvActive()))
        ) {
            throw new ResponseValidationError('Invalid response field "applicationUrl": an absolute https URL is required.');
        }
    }
}
