<?php

/**
 * ComfinoPay PHP API client
 *
 * Backend routines for communication with the ComfinoPay payment gateway REST API.
 *
 * @package Comfino\Api\Dto\Plugin
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-api-client
 */

declare(strict_types=1);

namespace Comfino\Api\Dto\Plugin;

/**
 * Severity hint for a reported shop plugin error.
 *
 * Used by the API-side classification pipeline to prioritize and route reports. The plugin supplies this explicitly, so
 * the severity no longer has to be inferred from the message text.
 */
enum ErrorSeverity: string
{
    /** Fatal condition that prevents the plugin from functioning. */
    case Critical = 'critical';

    /** A recoverable error: an operation failed, but the plugin keeps running. */
    case Error = 'error';

    /** A warning: unexpected condition that did not break the current operation. */
    case Warning = 'warning';

    /** A notice: minor, informational deviation from the expected flow. */
    case Notice = 'notice';

    /** Purely informational diagnostic entry. */
    case Info = 'info';
}
