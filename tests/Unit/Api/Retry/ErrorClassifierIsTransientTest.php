<?php

/**
 * ComfinoPay PHP API client
 *
 * Backend routines for communication with the ComfinoPay payment gateway REST API.
 *
 * @package Comfino\Tests\Unit
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-api-client
 */

declare(strict_types=1);

namespace Comfino\Tests\Unit\Api\Retry;

use Comfino\Api\Exception\ConnectionTimeout;
use Comfino\Api\Exception\RequestValidationError;
use Comfino\Api\Exception\ResponseValidationError;
use Comfino\Api\Exception\ServiceUnavailable;
use Comfino\Api\Exception\TooManyRequests;
use Comfino\Api\Retry\ErrorClassifier;
use Comfino\Tests\Unit\Api\Stub\FakeNetworkException;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use RuntimeException;
use TypeError;

final class ErrorClassifierIsTransientTest extends TestCase
{
    public function testExplicitTransientRules(): void
    {
        $classifier = new ErrorClassifier();
        $curlTimeout = new class ('timed out', 28) extends RuntimeException implements ClientExceptionInterface {
        };

        $this->assertTrue($classifier->isTransient(new FakeNetworkException()));
        $this->assertTrue($classifier->isTransient($curlTimeout));
        $this->assertTrue($classifier->isTransient(new ConnectionTimeout()));
        $this->assertTrue($classifier->isTransient(new ServiceUnavailable('down', 503)));
        $this->assertTrue($classifier->isTransient(new TooManyRequests('slow down', 429)));
    }

    public function testUnknownFailuresAreNotTransient(): void
    {
        $classifier = new ErrorClassifier();
        $tlsFailure = new class ('SSL certificate problem', 60) extends RuntimeException implements ClientExceptionInterface {
        };

        $this->assertFalse($classifier->isTransient(new TypeError('bug')));
        $this->assertFalse($classifier->isTransient(new RuntimeException('?')));
        $this->assertFalse($classifier->isTransient($tlsFailure));
        $this->assertFalse($classifier->isTransient(new ResponseValidationError('bad body')));
        $this->assertFalse($classifier->isTransient(new RequestValidationError('bad request', 400)));
        $this->assertFalse($classifier->isTransient('not a throwable'));
    }

    public function testAnUnknownThrowableIsStillRetryableByNeitherRule(): void
    {
        $classifier = new ErrorClassifier();

        $this->assertFalse($classifier->isTransient(new TypeError('bug')));
        $this->assertFalse($classifier->isRetryable(new TypeError('bug')));
    }
}
