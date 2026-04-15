<?php

/**
 * Comfino PHP API client
 *
 * Backend routines for communication with the Comfino payment gateway REST API.
 *
 * @package Comfino\Enum
 * @author Artur Kozubski <akozubski@comperia.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-api-client
 */

declare(strict_types=1);

namespace Comfino\Enum;

/**
 * Forward-compatible container for loan types returned by the Comfino API that are not yet defined in {@see LoanType}.
 *
 * Instances are flyweights - one instance per unique raw value - so identity comparison (===) works as expected
 * inside the same request lifecycle.
 *
 * Obtain instances exclusively through {@see LoanType::fromApiValue()} rather than constructing this class directly.
 */
final class UnknownLoanType implements LoanTypeInterface
{
    /** @var array<string, self> */
    private static array $instances = [];

    private function __construct(private readonly string $value)
    {
    }

    /**
     * Returns the cached flyweight for the given raw API value.
     */
    public static function of(string $value): self
    {
        return self::$instances[$value] ??= new self($value);
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
