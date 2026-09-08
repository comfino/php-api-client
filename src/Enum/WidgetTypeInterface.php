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

/**
 * Common interface for widget type values, covering both SDK-defined enum cases and widget types introduced by the
 * ComfinoPay API that are not yet defined in this version of the SDK.
 *
 * Implementations:
 *  - {@see WidgetType} - known types, backed PHP enum; supports exhaustive match
 *  - {@see UnknownWidgetType} - forward-compatible flyweight for unrecognised API values
 *
 * Usage pattern in consuming code:
 *
 *   foreach ($response->widgetTypes as $type) { // WidgetTypeInterface[]
 *       if ($type instanceof WidgetType) {
 *           match($type) {
 *               WidgetType::STANDARD => ...,
 *               WidgetType::CLASSIC => ...,
 *           }
 *       } else {
 *           // New widget type from API - handle generically or skip
 *           $raw = $type->getValue();
 *       }
 *   }
 */
interface WidgetTypeInterface
{
    /**
     * Raw string value as received from the ComfinoPay API.
     */
    public function getValue(): string;

    /**
     * Returns true when this widget type is defined in the current SDK version.
     * False means the ComfinoPay API returned a type not yet added to {@see WidgetType}.
     */
    public function isKnown(): bool;
}
