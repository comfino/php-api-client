<?php

/**
 * ComfinoPay PHP API client
 *
 * Backend routines for communication with the ComfinoPay payment gateway REST API.
 *
 * @package Comfino\Api\Response
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-api-client
 */

declare(strict_types=1);

namespace Comfino\Api\Response;

/**
 * Complete shop user settings response.
 *
 * The API returns feature flag attributes keyed by flag name, e.g.:
 * { "flags": { "FLAG_ONE": {}, "FLAG_TWO": { "maxAmount": 50000 } } }
 */
class GetUserSettings extends Base
{
    /** @var array<string, array<string, mixed>> Flag attributes keyed by flag name */
    public readonly array $flags;

    /** @inheritDoc */
    protected function processResponseBody(array|string|bool|null|float|int $deserializedResponseBody): void
    {
        $this->checkResponseType($deserializedResponseBody, 'array');
        $this->checkResponseStructure($deserializedResponseBody, ['flags']);
        $this->checkResponseType($deserializedResponseBody['flags'], 'array', 'flags');

        foreach ($deserializedResponseBody['flags'] as $flagName => $attributes) {
            $this->checkResponseType($attributes, 'array', "flags.$flagName");
        }

        $this->flags = $deserializedResponseBody['flags'];
    }

    public function hasFlag(string $flag): bool
    {
        return array_key_exists($flag, $this->flags);
    }

    /**
     * @return array<string, mixed>
     */
    public function getFlagAttributes(string $flag): array
    {
        return $this->flags[$flag] ?? [];
    }
}
