<?php

/**
 * Comfino PHP API client
 *
 * Backend routines for communication with the Comfino payment gateway REST API.
 *
 * @package Comfino\Api\Retry
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-api-client
 */

declare(strict_types=1);

namespace Comfino\Api\Retry;

/**
 * Sleeper that never sleeps.
 *
 * Two uses: unit tests and request paths whose latency budget cannot absorb a backoff delay. On such a path the
 * honest configuration is a single attempt that fails fast and lets a queue or the shopper retry, not a retry that
 * parks the request thread.
 */
final class NoDelaySleeper implements SleeperInterface
{
    /** @var int[] Milliseconds this sleeper was asked to sleep, in call order - assertable in tests */
    public array $requestedDelays = [];

    /** @inheritDoc */
    public function sleepMs(int $milliseconds): void
    {
        $this->requestedDelays[] = $milliseconds;
    }
}
