<?php

/**
 * Comfino PHP API client
 *
 * Backend routines for communication with the Comfino payment gateway REST API.
 *
 * @package Comfino\Api\Dto\Plugin
 * @author Artur Kozubski <akozubski@comperia.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-api-client
 */

declare(strict_types=1);

namespace Comfino\Api\Dto\Plugin;

/**
 * Represents an error encountered during payment plugin execution on a shop.
 */
final class ShopPluginError
{
    /**
     * @param string $host Host name of the shop
     * @param string $platform E-commerce platform name
     * @param array<array-key, mixed> $environment Various environment variables
     * @param string $errorCode Error code
     * @param string $errorMessage Error message
     * @param string|null $apiRequestUrl API request URL
     * @param string|null $apiRequest API request payload
     * @param string|null $apiResponse API response payload
     * @param string|null $stackTrace Stack trace for debugging
     */
    public function __construct(
        public string $host,
        public string $platform,
        public array $environment,
        public string $errorCode,
        public string $errorMessage,
        public ?string $apiRequestUrl = null,
        public ?string $apiRequest = null,
        public ?string $apiResponse = null,
        public ?string $stackTrace = null
    ) {
    }
}
