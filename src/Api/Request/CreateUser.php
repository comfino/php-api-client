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

use Comfino\Api\Dto\Account\UserRegistration;
use Comfino\Api\Request;

/**
 * Shop owner registration request.
 *
 * Pinned to API v2 regardless of the client's configured or requested version: v2 is the only version whose response
 * carries the merchant account `uuid`, which {@see \Comfino\Api\Response\CreateUser} now requires. v1 stays reachable
 * for any other API call a v1 client makes; this one request is the exception.
 */
class CreateUser extends Request
{
    private const FORCED_API_VERSION = 2;

    public function __construct(private readonly UserRegistration $registration)
    {
        $this->setRequestMethod('POST');
        $this->setApiEndpointPath('user');
    }

    /**
     * Not idempotent: the API has no key to deduplicate a replay by. A retry that lands after an earlier attempt already created
     * the account cannot recover the `apiKey`/`widgetKey` from the first response - it only surfaces as a 409 Conflict on
     * `webSiteUrl`, which the caller must treat as "already registered", not as transient failure worth a second attempt.
     */
    public function isIdempotent(): bool
    {
        return false;
    }

    /** @inheritDoc */
    protected function getApiEndpointUri(string $apiBaseUrl, int $apiVersion): string
    {
        return parent::getApiEndpointUri($apiBaseUrl, self::FORCED_API_VERSION);
    }

    /** @inheritDoc */
    protected function prepareRequestBody(): ?array
    {
        return array_filter(
            [
                'name' => $this->registration->name,
                'webSiteUrl' => $this->registration->webSiteUrl,
                'contactName' => $this->registration->contactName,
                'contactEmail' => $this->registration->contactEmail,
                'contactPhone' => $this->registration->contactPhone,
                'platformId' => $this->registration->platformId,
                'agreements' => $this->registration->agreements,
                'additionalDomains' => $this->registration->additionalDomains,
            ],
            static fn (string|int|array $value, string $field): bool => $field !== 'additionalDomains' || $value !== [],
            ARRAY_FILTER_USE_BOTH
        );
    }
}
