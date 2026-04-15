<?php

/**
 * Comfino PHP API client
 *
 * Backend routines for communication with the Comfino payment gateway REST API.
 *
 * @package Comfino\Api\Request
 * @author Artur Kozubski <akozubski@comperia.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-api-client
 */

declare(strict_types=1);

namespace Comfino\Api\Request;

use Comfino\Api\Request;

/**
 * Shop payment plugin removal notification request.
 */
class NotifyShopPluginRemoval extends Request
{
    public function __construct()
    {
        $this->setRequestMethod('PUT');
        $this->setApiEndpointPath('log-plugin-remove');
    }

    protected function prepareRequestBody(): ?array
    {
        return null;
    }
}
