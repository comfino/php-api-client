<?php

/**
 * ComfinoPay PHP API client
 *
 * Backend routines for communication with the ComfinoPay payment gateway REST API.
 *
 * @package Comfino\Api\Dto\Account
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-api-client
 */

declare(strict_types=1);

namespace Comfino\Api\Dto\Account;

/**
 * Shop owner registration request sent to `POST user` to create a new ComfinoPay merchant account.
 *
 * This is the one call in the library made with no API key: the account does not exist yet, so
 * {@see \Comfino\Api\ApiContext::$apiKey} for it must be an empty string (the client omits the `Api-Key` header rather
 * than sending an empty one - see {@see \Comfino\Api\SharedClient}). A successful response carries the `apiKey` and
 * `widgetKey` the shop stores and uses for every subsequent call.
 *
 * A shop already registered under `webSiteUrl` is rejected with HTTP 409 ({@see \Comfino\Api\Exception\Conflict}), the
 * field-level error surfaced through the exception message exactly as for any other validation failure.
 */
final class UserRegistration
{
    /**
     * @param string $name Shop name (commonly the shop's domain, without scheme)
     * @param string $webSiteUrl Full shop URL, including scheme
     * @param string $contactName Contact person's full name
     * @param string $contactEmail Contact person's e-mail address
     * @param string $contactPhone Contact person's phone number
     * @param int $platformId Numeric identifier of the shop platform integrating this library
     * @param string[] $agreements IDs of the {@see Agreement} entries the shop owner accepted, from `GET fetch-agreements`
     */
    public function __construct(
        public readonly string $name,
        public readonly string $webSiteUrl,
        public readonly string $contactName,
        public readonly string $contactEmail,
        public readonly string $contactPhone,
        public readonly int $platformId,
        public readonly array $agreements = []
    ) {
    }
}
