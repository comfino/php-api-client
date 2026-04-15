<?php

/**
 * Comfino PHP API client
 *
 * Backend routines for communication with the Comfino payment gateway REST API.
 *
 * @package Comfino\Tests\Unit\Enum
 * @author Artur Kozubski <akozubski@comperia.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-api-client
 */

declare(strict_types=1);

namespace Comfino\Tests\Unit\Enum;

use Comfino\Enum\OrderStatus;
use Comfino\Enum\OrderStatusInterface;
use Comfino\Enum\UnknownOrderStatus;
use PHPUnit\Framework\TestCase;

final class OrderStatusTest extends TestCase
{
    // =========================================================================
    // OrderStatus
    // =========================================================================

    // -------------------------------------------------------------------------
    // fromApiValue - known cases
    // -------------------------------------------------------------------------

    /**
     * @dataProvider knownApiValuesProvider
     */
    public function testFromApiValueReturnsCorrectEnumCase(string $apiValue, OrderStatus $expected): void
    {
        $this->assertSame($expected, OrderStatus::fromApiValue($apiValue));
    }

    /**
     * @return array<string, array{string, OrderStatus}>
     */
    public static function knownApiValuesProvider(): array
    {
        return [
            'CREATED' => ['CREATED', OrderStatus::CREATED],
            'WAITING_FOR_FILLING' => ['WAITING_FOR_FILLING', OrderStatus::WAITING_FOR_FILLING],
            'WAITING_FOR_CONFIRMATION' => ['WAITING_FOR_CONFIRMATION', OrderStatus::WAITING_FOR_CONFIRMATION],
            'WAITING_FOR_PAYMENT' => ['WAITING_FOR_PAYMENT', OrderStatus::WAITING_FOR_PAYMENT],
            'ACCEPTED' => ['ACCEPTED', OrderStatus::ACCEPTED],
            'PAID' => ['PAID', OrderStatus::PAID],
            'REJECTED' => ['REJECTED', OrderStatus::REJECTED],
            'RESIGN' => ['RESIGN', OrderStatus::RESIGN],
            'CANCELLED_BY_SHOP' => ['CANCELLED_BY_SHOP', OrderStatus::CANCELLED_BY_SHOP],
            'CANCELLED' => ['CANCELLED', OrderStatus::CANCELLED],
        ];
    }

    // -------------------------------------------------------------------------
    // fromApiValue - unknown values
    // -------------------------------------------------------------------------

    /**
     * @dataProvider unknownApiValuesProvider
     */
    public function testFromApiValueReturnsUnknownOrderStatusForUnrecognisedValue(string $apiValue): void
    {
        $result = OrderStatus::fromApiValue($apiValue);

        $this->assertInstanceOf(UnknownOrderStatus::class, $result);
        $this->assertFalse($result->isKnown());
        $this->assertSame($apiValue, $result->getValue());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unknownApiValuesProvider(): array
    {
        return [
            'lowercase known value' => ['accepted'], // case-sensitive - must not match
            'future status' => ['FUTURE_STATUS'],
            'empty string' => [''],
            'numeric string' => ['42'],
            'mixed case' => ['Cancelled'],
        ];
    }

    // -------------------------------------------------------------------------
    // isKnown - all cases return true
    // -------------------------------------------------------------------------

    /**
     * @dataProvider allCasesProvider
     */
    public function testIsKnownReturnsTrueForEveryCase(OrderStatus $status): void
    {
        $this->assertTrue($status->isKnown());
    }

    // -------------------------------------------------------------------------
    // getValue - backing string matches the enum case name
    // -------------------------------------------------------------------------

    /**
     * @dataProvider allCasesProvider
     */
    public function testGetValueReturnsBackingStringForEveryCase(OrderStatus $status): void
    {
        // OrderStatus backing values are the uppercase string representation of the case name.
        $this->assertSame($status->value, $status->getValue());
    }

    // -------------------------------------------------------------------------
    // fromApiValue is idempotent: roundtrip getValue → fromApiValue → same case
    // -------------------------------------------------------------------------

    /**
     * @dataProvider allCasesProvider
     */
    public function testFromApiValueRoundtripReturnsSameCase(OrderStatus $status): void
    {
        $this->assertSame($status, OrderStatus::fromApiValue($status->getValue()));
    }

    /**
     * @return array<string, array{OrderStatus}>
     */
    public static function allCasesProvider(): array
    {
        $cases = [];

        foreach (OrderStatus::cases() as $case) {
            $cases[$case->name] = [$case];
        }

        return $cases;
    }

    // =========================================================================
    // UnknownOrderStatus
    // =========================================================================

    // -------------------------------------------------------------------------
    // Flyweight / identity
    // -------------------------------------------------------------------------

    public function testOfReturnsSameInstanceForSameValue(): void
    {
        $this->assertSame(
            UnknownOrderStatus::of('SOME_FUTURE_STATUS'),
            UnknownOrderStatus::of('SOME_FUTURE_STATUS')
        );
    }

    public function testOfReturnsDifferentInstancesForDifferentValues(): void
    {
        $this->assertNotSame(
            UnknownOrderStatus::of('STATUS_A'),
            UnknownOrderStatus::of('STATUS_B')
        );
    }

    // -------------------------------------------------------------------------
    // getValue
    // -------------------------------------------------------------------------

    /**
     * @dataProvider rawStatusValuesProvider
     */
    public function testGetValueReturnsRawApiString(string $rawValue): void
    {
        $this->assertSame($rawValue, UnknownOrderStatus::of($rawValue)->getValue());
    }

    // -------------------------------------------------------------------------
    // isKnown
    // -------------------------------------------------------------------------

    /**
     * @dataProvider rawStatusValuesProvider
     */
    public function testIsKnownReturnsFalse(string $rawValue): void
    {
        $this->assertFalse(UnknownOrderStatus::of($rawValue)->isKnown());
    }

    // -------------------------------------------------------------------------
    // __toString
    // -------------------------------------------------------------------------

    /**
     * @dataProvider rawStatusValuesProvider
     */
    public function testToStringReturnsRawApiString(string $rawValue): void
    {
        $this->assertSame($rawValue, (string) UnknownOrderStatus::of($rawValue));
    }

    // -------------------------------------------------------------------------
    // Implements OrderStatusInterface
    // -------------------------------------------------------------------------

    public function testUnknownOrderStatusImplementsOrderStatusInterface(): void
    {
        $this->assertInstanceOf(OrderStatusInterface::class, UnknownOrderStatus::of('ANY'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function rawStatusValuesProvider(): array
    {
        return [
            'future API status' => ['FUTURE_API_STATUS'],
            'lowercase value' => ['pending'],
            'empty string' => [''],
            'numeric string' => ['0'],
            'mixed case' => ['Partially_Paid'],
        ];
    }
}
