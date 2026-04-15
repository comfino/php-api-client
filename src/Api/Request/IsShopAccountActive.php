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
 * Shop account activity check request.
 */
class IsShopAccountActive extends Request
{
    /**
     * @param string|null $cacheInvalidateUrl URL to invalidate the plugin cache at shop side (optional)
     * @param string|null $configurationUrl URL to retrieve plugin configuration details at shop side (optional)
     */
    public function __construct(?string $cacheInvalidateUrl, ?string $configurationUrl)
    {
        $this->setRequestMethod('GET');
        $this->setApiEndpointPath('user/is-active');

        $requestHeaders = [];

        if (!empty($cacheInvalidateUrl)) {
            $requestHeaders['Comfino-Cache-Invalidate-Url'] = $cacheInvalidateUrl;
        }

        if (!empty($configurationUrl)) {
            $requestHeaders['Comfino-Configuration-Url'] = $configurationUrl;
        }

        if (count($requestHeaders) > 0) {
            $this->setRequestHeaders($requestHeaders);
        }
    }

    /** @inheritDoc */
    protected function prepareRequestBody(): ?array
    {
        return null;
    }
}
