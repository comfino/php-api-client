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
 */
class CreateUser extends Request
{
    public function __construct(private readonly UserRegistration $registration)
    {
        $this->setRequestMethod('POST');
        $this->setApiEndpointPath('user');
    }

    /**
     * Not idempotent: the API has no key to deduplicate a replay by. A retry that lands after an earlier attempt already
     * created the account cannot recover the `apiKey`/`widgetKey` from the first response - it only surfaces as a 409
     * Conflict on `webSiteUrl`, which the caller must treat as "already registered", not as transient failure worth a
     * second attempt.
     */
    public function isIdempotent(): bool
    {
        return false;
    }

    /** @inheritDoc */
    protected function prepareRequestBody(): ?array
    {
        return [
            'name' => $this->registration->name,
            'webSiteUrl' => $this->registration->webSiteUrl,
            'contactName' => $this->registration->contactName,
            'contactEmail' => $this->registration->contactEmail,
            'contactPhone' => $this->registration->contactPhone,
            'platformId' => $this->registration->platformId,
            'agreements' => $this->registration->agreements,
        ];
    }
}
