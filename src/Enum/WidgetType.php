<?php

/**
 * Comfino PHP API client
 *
 * Backend routines for communication with the Comfino payment gateway REST API.
 *
 * @package Comfino\Enum
 * @author Artur Kozubski <akozubski@comperia.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
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
     * Safe factory for deserializing widget type values from Comfino API responses.
     *
     * Unlike from(), this never throws a ValueError: if the API returns a widget type not yet defined in this SDK
     * version, it returns an {@see UnknownWidgetType} flyweight so the calling code stays operational without requiring
     * an SDK upgrade.
     *
     * Use this method exclusively when parsing data received from the API.
     * Use enum cases directly (e.g. WidgetType::STANDARD) when constructing requests.
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
