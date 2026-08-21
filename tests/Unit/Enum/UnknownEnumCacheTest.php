<?php

/**
 * Comfino PHP API client
 *
 * Backend routines for communication with the Comfino payment gateway REST API.
 *
 * @package Comfino\Tests\Unit\Enum
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-api-client
 */

declare(strict_types=1);

namespace Comfino\Tests\Unit\Enum;

use Comfino\Enum\UnknownLoanType;
use Comfino\Enum\UnknownOrderStatus;
use Comfino\Enum\UnknownWidgetType;
use PHPUnit\Framework\TestCase;

/**
 * The flyweight caches behind the forward-compatible enum containers are harmless in a request-scoped process and a
 * slow leak in a long-lived worker: they grow with the number of *distinct* unknown values the API has ever returned,
 * which nothing in the process controls.
 */
final class UnknownEnumCacheTest extends TestCase
{
    public function testIdentityIsPreservedForRepeatedValues(): void
    {
        $this->assertSame(UnknownLoanType::of('BRAND_NEW_TYPE'), UnknownLoanType::of('BRAND_NEW_TYPE'));
    }

    /**
     * @dataProvider containerProvider
     *
     * @param callable(string): object $factory
     * @param callable(): int $counter
     */
    public function testCacheNeverGrowsPastTheCeiling(callable $factory, callable $counter, int $ceiling): void
    {
        for ($i = 0; $i < $ceiling * 3; $i++) {
            $factory("VALUE_$i");
        }

        $this->assertLessThanOrEqual($ceiling, $counter());
    }

    /**
     * @dataProvider containerProvider
     *
     * @param callable(string): object $factory
     * @param callable(): int $counter
     */
    public function testRecentValuesSurviveTheEviction(callable $factory, callable $counter, int $ceiling): void
    {
        for ($i = 0; $i < $ceiling * 2; $i++) {
            $factory("FILL_$i");
        }

        $recent = $factory('STILL_IN_PLAY');

        /* Eviction is oldest-first rather than a full flush, so identity comparison keeps working for the values
           actually in play within one request. */
        $this->assertSame($recent, $factory('STILL_IN_PLAY'));
        $this->assertLessThanOrEqual($ceiling, $counter());
    }

    /**
     * @return array<string, array{callable(string): object, callable(): int, int}>
     */
    public static function containerProvider(): array
    {
        return [
            'loan type' => [
                static fn (string $value): object => UnknownLoanType::of($value),
                static fn (): int => UnknownLoanType::cachedInstanceCount(),
                UnknownLoanType::MAX_CACHED_INSTANCES,
            ],
            'order status' => [
                static fn (string $value): object => UnknownOrderStatus::of($value),
                static fn (): int => UnknownOrderStatus::cachedInstanceCount(),
                UnknownOrderStatus::MAX_CACHED_INSTANCES,
            ],
            'widget type' => [
                static fn (string $value): object => UnknownWidgetType::of($value),
                static fn (): int => UnknownWidgetType::cachedInstanceCount(),
                UnknownWidgetType::MAX_CACHED_INSTANCES,
            ],
        ];
    }
}
