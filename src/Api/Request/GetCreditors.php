<?php

/**
 * ComfinoPay PHP API client
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
 * Available creditors list request.
 */
class GetCreditors extends Request
{
    public function __construct()
    {
        $this->setRequestMethod('GET');
        $this->setApiEndpointPath('creditors');
    }

    /** @inheritDoc */
    protected function prepareRequestBody(): ?array
    {
        return null;
    }
}
