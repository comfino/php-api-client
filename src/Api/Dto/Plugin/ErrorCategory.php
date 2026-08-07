<?php

/**
 * Comfino PHP API client
 *
 * Backend routines for communication with the Comfino payment gateway REST API.
 *
 * @package Comfino\Api\Dto\Plugin
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-api-client
 */

declare(strict_types=1);

namespace Comfino\Api\Dto\Plugin;

/**
 * Technical classification of a reported shop plugin error.
 *
 * This is a structured replacement for the free-form message prefixes the plugin used to embed in error_message (e.g.
 * "Exception TypeError in ..."). It lets the API-side classification pipeline group errors by their technical nature
 * without parsing message text.
 */
enum ErrorCategory: string
{
    /** A PHP engine error caught by the error handler (E_ERROR, E_WARNING, E_NOTICE, ...). */
    case PhpError = 'php_error';

    /** A \TypeError thrown at runtime (type mismatch). */
    case ExceptionTypeError = 'exception_type_error';

    /** A \ParseError thrown while evaluating PHP code. */
    case ExceptionParseError = 'exception_parse_error';

    /** A \DivisionByZeroError. */
    case ExceptionDivision = 'exception_division';

    /** An \ArgumentCountError (too few arguments passed to a function). */
    case ExceptionArgCount = 'exception_arg_count';

    /** A \LogicException subtype (programmer error detectable before runtime). */
    case ExceptionLogicError = 'exception_logic_error';

    /** A generic \Error not matched by a more specific category. */
    case ExceptionError = 'exception_error';

    /** A generic \Exception not matched by a more specific category. */
    case ExceptionGeneric = 'exception_generic';

    /** A \Throwable that is neither \Error nor \Exception (should not normally happen). */
    case ExceptionOther = 'exception_other';

    /** Failure while communicating with the Comfino API. */
    case ApiError = 'api_error';

    /** Invalid or inconsistent plugin/shop configuration. */
    case ConfigurationError = 'configuration_error';

    /** Input or state validation failure. */
    case ValidationError = 'validation_error';

    /** Catch-all for errors that do not fit any other category. */
    case Other = 'other';
}
