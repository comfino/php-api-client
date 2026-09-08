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
 * One legal agreement a shop owner must see (and, if required, accept) before registering with ComfinoPay.
 *
 * Returned by `GET fetch-agreements` and echoed back by `id` in a {@see UserRegistration} payload for every agreement the
 * shop owner checked. `content` carries shop-facing markup (currently plain text and `<a>` links) meant to be rendered
 * as-is next to a consent checkbox, not further escaped.
 */
final class Agreement
{
    /**
     * @param string $id Stable agreement identifier, echoed back in {@see UserRegistration::$agreements}
     * @param string $content Shop-facing agreement text, may contain `<a>` links
     * @param bool $required Whether registration is rejected unless this agreement is accepted
     */
    public function __construct(
        public readonly string $id,
        public readonly string $content,
        public readonly bool $required
    ) {
    }
}
