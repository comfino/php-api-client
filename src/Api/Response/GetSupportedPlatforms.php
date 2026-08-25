<?php

/**
 * Comfino PHP API client
 *
 * Backend routines for communication with the Comfino payment gateway REST API.
 *
 * @package Comfino\Api\Response
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-api-client
 */

declare(strict_types=1);

namespace Comfino\Api\Response;

/**
 * Response to the supported plugin platforms list request.
 */
class GetSupportedPlatforms extends Base
{
    /**
     * @var array<int, array{code: string, name: string}> Supported platforms, each with a stable code and display name
     */
    public readonly array $platforms;

    /** @inheritDoc */
    protected function processResponseBody(array|string|bool|null|float|int $deserializedResponseBody): void
    {
        $this->checkResponseType($deserializedResponseBody, 'array');
        $this->checkResponseStructure($deserializedResponseBody, ['platforms']);
        $this->checkResponseType($deserializedResponseBody['platforms'], 'array', 'platforms');

        $this->platforms = array_map(
            function ($platform): array {
                $this->checkResponseType($platform, 'array', 'platforms[]');
                $this->checkResponseStructure($platform, ['code', 'name']);

                return ['code' => $platform['code'], 'name' => $platform['name']];
            },
            $deserializedResponseBody['platforms']
        );
    }
}
