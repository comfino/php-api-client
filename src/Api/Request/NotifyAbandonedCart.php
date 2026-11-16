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
 * Abandoned cart notification request.
 */
class NotifyAbandonedCart extends Request
{
    public function __construct(private readonly string $type)
    {
        $this->setRequestMethod('POST');
        $this->setApiEndpointPath('abandoned_cart');
    }

    protected function prepareRequestBody(): ?array
    {
        return ['type' => $this->type];
    }
}
