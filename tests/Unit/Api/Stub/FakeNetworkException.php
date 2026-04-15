<?php

/**
 * Comfino PHP API client
 *
 * Backend routines for communication with the Comfino payment gateway REST API.
 *
 * @package Comfino\Tests\Unit\Api\Stub
 * @author Artur Kozubski <akozubski@comperia.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-api-client
 */

declare(strict_types=1);

namespace Comfino\Tests\Unit\Api\Stub;

use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;
use RuntimeException;

/**
 * A network-level exception that satisfies Psr18ErrorDetector::isRetryable().
 *
 * Only the type check matters for retry logic; getRequest() is never called.
 */
final class FakeNetworkException extends RuntimeException implements NetworkExceptionInterface
{
    public function __construct()
    {
        parent::__construct('Simulated network failure.');
    }

    public function getRequest(): RequestInterface
    {
        /* getRequest() is part of the interface contract but is never called by the retry logic - only the type
           check matters. */
        throw new \LogicException('Not needed in tests.');
    }
}
