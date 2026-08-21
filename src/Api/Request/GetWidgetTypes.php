<?php

/**
 * Comfino PHP API client
 *
 * Backend routines for communication with the Comfino payment gateway REST API.
 *
 * @package Comfino\Api\Request
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-api-client
 */

declare(strict_types=1);

namespace Comfino\Api\Request;

use Comfino\Api\Request;

/**
 * Available widget types listing request.
 *
 * Uses GET /widget/v{version}/widget-types — the "widget" namespace prefix comes before the version segment,
 * unlike standard store-API paths which follow {baseUrl}/v{version}/{path}.
 */
class GetWidgetTypes extends Request
{
    public function __construct()
    {
        $this->setRequestMethod('GET');
        $this->setApiEndpointPath('widget-types');
    }

    /** @inheritDoc */
    protected function prepareRequestBody(): ?array
    {
        return null;
    }

    /** @inheritDoc */
    protected function getApiEndpointUri(string $apiBaseUrl, int $apiVersion): string
    {
        $uri = implode('/', [trim($apiBaseUrl, " /\n\r\t\v\0"), 'widget', "v$apiVersion", $this->apiEndpointPath]);

        if (!empty($this->requestParams)) {
            $uri .= ('?' . http_build_query($this->requestParams));
        }

        return $uri;
    }
}
