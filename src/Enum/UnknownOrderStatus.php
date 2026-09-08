<?php

/**
 * ComfinoPay PHP API client
 *
 * Backend routines for communication with the ComfinoPay payment gateway REST API.
 *
 * @package Comfino\Enum
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-api-client
 */

declare(strict_types=1);

namespace Comfino\Enum;

/**
 * Forward-compatible container for order statuses returned by the ComfinoPay API that are not yet defined
 * in {@see OrderStatus}.
 *
 * Instances are flyweights - one instance per unique raw value - so identity comparison (===) works as expected
 * inside the same request lifecycle. The cache is capped at {@see MAX_CACHED_INSTANCES} entries so that a long-lived
 * worker cannot accumulate one instance per distinct unknown value the API has ever returned.
 *
 * Obtain instances exclusively through {@see OrderStatus::fromApiValue()} rather than constructing this class directly.
 */
final class UnknownOrderStatus implements OrderStatusInterface
{
    /**
     * Ceiling on the flyweight cache. The cached values are immutable and tenant-independent, so a stale entry is
     * harmless - but the cache grows with the number of *distinct* unknown values seen, which in a request-scoped
     * process is a handful and in a long-lived worker is an unbounded slow leak fed by whatever the API sends.
     */
    public const MAX_CACHED_INSTANCES = 128;

    /** @var array<string, self> */
    private static array $instances = [];

    private function __construct(private readonly string $value)
    {
    }

    /**
     * Returns the cached flyweight for the given raw API value.
     *
     * Once the cache is full, the oldest entry is evicted rather than the cache being cleared: identity comparison
     * within one request keeps working for the values actually in play, and only a value not seen for a long time can
     * lose its identity.
     */
    public static function of(string $value): self
    {
        if (isset(self::$instances[$value])) {
            return self::$instances[$value];
        }

        if (count(self::$instances) >= self::MAX_CACHED_INSTANCES) {
            array_shift(self::$instances);
        }

        return self::$instances[$value] = new self($value);
    }

    /**
     * Returns the number of flyweights currently cached. Exposed for tests and for a host that wants to assert the
     * cache is not growing without a bound.
     */
    public static function cachedInstanceCount(): int
    {
        return count(self::$instances);
    }

    /** @inheritDoc */
    public function getValue(): string
    {
        return $this->value;
    }

    /** @inheritDoc */
    public function isKnown(): bool
    {
        return false;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
