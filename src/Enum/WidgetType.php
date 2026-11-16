<?php

/**
 * ComfinoPay PHP API client
 *
 * Backend routines for communication with the ComfinoPay payment gateway REST API.
 *
 * @package Comfino\Enum
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-api-client
 */

declare(strict_types=1);

namespace Comfino\Enum;

enum WidgetType: string implements WidgetTypeInterface
{
    case STANDARD = 'standard';
    case CLASSIC = 'classic';

    /**
     * Safe factory for deserializing widget type values from API responses: unlike from(), never throws a ValueError,
     * returning an {@see UnknownWidgetType} flyweight for a value not yet defined in this SDK version. Use this only for
     * parsing API data; construct enum cases directly (e.g. WidgetType::STANDARD) for requests.
     */
    public static function fromApiValue(string $value): WidgetTypeInterface
    {
        return self::tryFrom($value) ?? UnknownWidgetType::of($value);
    }

    /** @inheritDoc */
    public function getValue(): string
    {
        return $this->value;
    }

    /** @inheritDoc */
    public function isKnown(): bool
    {
        return true;
    }
}
