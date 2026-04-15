<?php

/**
 * Comfino PHP API client
 *
 * Backend routines for communication with the Comfino payment gateway REST API.
 *
 * @package Comfino\Api\Response
 * @author Artur Kozubski <akozubski@comperia.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-api-client
 */

declare(strict_types=1);

namespace Comfino\Api\Response;

use Comfino\Enum\WidgetType;
use Comfino\Enum\WidgetTypeInterface;

/**
 * Response from the API containing widget types for the promotional banner widget.
 */
class GetWidgetTypes extends Base
{
    /** @var WidgetTypeInterface[] All widget types returned by the API, including any not yet defined in this SDK wrapped in UnknownWidgetType */
    public readonly array $widgetTypes;
    /** @var string[] Widget types with their names as key value pairs */
    public readonly array $widgetTypesWithNames;

    /** @inheritDoc */
    protected function processResponseBody(array|string|bool|null|float|int $deserializedResponseBody): void
    {
        $this->checkResponseType($deserializedResponseBody, 'array');

        $this->widgetTypesWithNames = $deserializedResponseBody;
        $this->widgetTypes = array_map(
            static fn (string $widgetType): WidgetTypeInterface => WidgetType::fromApiValue($widgetType),
            array_keys($deserializedResponseBody)
        );
    }
}
