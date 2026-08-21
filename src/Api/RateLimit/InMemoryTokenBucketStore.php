<?php

/**
 * Comfino PHP API client
 *
 * Backend routines for communication with the Comfino payment gateway REST API.
 *
 * @package Comfino\Api\RateLimit
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-api-client
 */

declare(strict_types=1);

namespace Comfino\Api\RateLimit;

/**
 * Process-local token-bucket store backed by a plain array.
 */
final class InMemoryTokenBucketStore implements TokenBucketStoreInterface
{
    /** @var array<string, TokenBucket> */
    private array $buckets = [];

    /** @inheritDoc */
    public function get(string $key): ?TokenBucket
    {
        return $this->buckets[$key] ?? null;
    }

    /** @inheritDoc */
    public function set(string $key, TokenBucket $bucket): void
    {
        $this->buckets[$key] = $bucket;
    }
}
