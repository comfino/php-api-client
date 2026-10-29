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

use Comfino\Api\RateLimit\TokenBucket;
use Comfino\Api\RateLimit\TokenBucketStoreInterface;

/**
 * A store that implements only the get-then-set interface — the shape a host writes before it reads the docblock.
 *
 * {@see \Comfino\Api\RateLimit\InMemoryTokenBucketStore} cannot stand in for this: it declares the atomic interface, so
 * the limiter takes the swap path with it and the fallback path would never be exercised.
 */
final class PlainTokenBucketStore implements TokenBucketStoreInterface
{
    /** @var array<string, TokenBucket> */
    private array $buckets = [];

    public function get(string $key): ?TokenBucket
    {
        return $this->buckets[$key] ?? null;
    }

    public function set(string $key, TokenBucket $bucket): void
    {
        $this->buckets[$key] = $bucket;
    }
}
