<?php

/**
 * ComfinoPay PHP API client
 *
 * Backend routines for communication with the ComfinoPay payment gateway REST API.
 *
 * @package Comfino\Tests\Unit\Api\Stub
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-api-client
 */

declare(strict_types=1);

namespace Comfino\Tests\Unit\Api\Stub;

use Comfino\Api\RateLimit\AtomicTokenBucketStoreInterface;
use Comfino\Api\RateLimit\TokenBucket;

/**
 * An atomic store that refuses its first $refusals swaps, standing in for another worker winning the race.
 *
 * A real concurrent test would need two processes. What the limiter's contract actually says is "a lost swap is
 * retried, and a swap that keeps being lost is a rejection", and refusing a counted number of swaps is what makes both
 * halves of that observable in one process.
 */
final class SwapRefusingTokenBucketStore implements AtomicTokenBucketStoreInterface
{
    /** @var array<string, TokenBucket> */
    private array $buckets = [];

    public int $swapAttempts = 0;

    public function __construct(private readonly int $refusals)
    {
    }

    public function get(string $key): ?TokenBucket
    {
        return $this->buckets[$key] ?? null;
    }

    public function set(string $key, TokenBucket $bucket): void
    {
        $this->buckets[$key] = $bucket;
    }

    public function compareAndSet(string $key, ?TokenBucket $expected, TokenBucket $new): bool
    {
        if (++$this->swapAttempts <= $this->refusals) {
            return false;
        }

        $this->buckets[$key] = $new;

        return true;
    }
}
