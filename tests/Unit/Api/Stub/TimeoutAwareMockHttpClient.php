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

use Comfino\Api\Retry\TimeoutAwareClientInterface;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

/**
 * A PSR-18 HTTP client that additionally implements TimeoutAwareClientInterface.
 * Queues canned responses / exceptions and records updateTimeouts() calls.
 */
final class TimeoutAwareMockHttpClient implements ClientInterface, TimeoutAwareClientInterface
{
    /** @var array<ResponseInterface|ClientExceptionInterface> */
    private array $queue = [];

    /** @var array<array{int, int}> Recorded (connectionTimeout, transferTimeout) pairs */
    public array $updateTimeoutCalls = [];

    public function addResponse(ResponseInterface $response): void
    {
        $this->queue[] = $response;
    }

    public function addException(ClientExceptionInterface $exception): void
    {
        $this->queue[] = $exception;
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        if ($this->queue === []) {
            throw new RuntimeException('TimeoutAwareMockHttpClient: queue is empty.');
        }

        $item = array_shift($this->queue);

        if ($item instanceof ClientExceptionInterface) {
            throw $item;
        }

        return $item;
    }

    public function updateTimeouts(int $connectionTimeout, int $transferTimeout): void
    {
        $this->updateTimeoutCalls[] = [$connectionTimeout, $transferTimeout];
    }
}
