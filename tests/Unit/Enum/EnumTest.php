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

use Comfino\Enum\LoanType;
use Comfino\Enum\OrderStatus;
use Comfino\Enum\UnknownLoanType;
use Comfino\Enum\UnknownOrderStatus;
use Comfino\Enum\UnknownWidgetType;
use Comfino\Enum\WidgetType;
use PHPUnit\Framework\TestCase;

final class EnumTest extends TestCase
{
    // -------------------------------------------------------------------------
    // LoanType
    // -------------------------------------------------------------------------

    public function testLoanTypeFromApiValueReturnsKnownCase(): void
    {
        $this->assertSame(LoanType::PAY_LATER, LoanType::fromApiValue('PAY_LATER'));
    }

    public function testLoanTypeKnownCaseIsKnown(): void
    {
        $this->assertTrue(LoanType::INSTALLMENTS_ZERO_PERCENT->isKnown());
        $this->assertTrue(LoanType::PAY_LATER->isKnown());
    }

    public function testLoanTypeGetValueReturnsBackingValue(): void
    {
        $this->assertSame('PAY_LATER', LoanType::PAY_LATER->getValue());
        $this->assertSame('BLIK', LoanType::BLIK->getValue());
    }

    public function testLoanTypeFromApiValueReturnsUnknownTypeForUnrecognisedValue(): void
    {
        $result = LoanType::fromApiValue('FUTURE_PRODUCT_TYPE');

        $this->assertFalse($result->isKnown());
        $this->assertSame('FUTURE_PRODUCT_TYPE', $result->getValue());
    }

    // -------------------------------------------------------------------------
    // UnknownLoanType - flyweight
    // -------------------------------------------------------------------------

    public function testUnknownLoanTypeFlyweightReturnsSameInstanceForSameValue(): void
    {
        $this->assertSame(UnknownLoanType::of('SOME_FUTURE_TYPE'), UnknownLoanType::of('SOME_FUTURE_TYPE'));
    }

    public function testUnknownLoanTypeDifferentValuesDifferentInstances(): void
    {
        $this->assertNotSame(UnknownLoanType::of('TYPE_A'), UnknownLoanType::of('TYPE_B'));
    }

    public function testUnknownLoanTypeIsNotKnown(): void
    {
        $this->assertFalse(UnknownLoanType::of('ANYTHING')->isKnown());
    }

    public function testUnknownLoanTypeToStringReturnsValue(): void
    {
        $this->assertSame('CUSTOM_TYPE', (string) UnknownLoanType::of('CUSTOM_TYPE'));
    }

    // -------------------------------------------------------------------------
    // OrderStatus
    // -------------------------------------------------------------------------

    public function testOrderStatusFromApiValueReturnsKnownCase(): void
    {
        $this->assertSame(OrderStatus::ACCEPTED, OrderStatus::fromApiValue('ACCEPTED'));
    }

    public function testOrderStatusKnownCaseIsKnown(): void
    {
        $this->assertTrue(OrderStatus::CANCELLED->isKnown());
        $this->assertTrue(OrderStatus::PAID->isKnown());
    }

    public function testOrderStatusGetValueReturnsBackingValue(): void
    {
        $this->assertSame('WAITING_FOR_PAYMENT', OrderStatus::WAITING_FOR_PAYMENT->getValue());
    }

    public function testOrderStatusFromApiValueReturnsUnknownStatusForUnrecognisedValue(): void
    {
        $result = OrderStatus::fromApiValue('FUTURE_STATUS');

        $this->assertFalse($result->isKnown());
        $this->assertSame('FUTURE_STATUS', $result->getValue());
    }

    public function testUnknownOrderStatusFlyweightReturnsSameInstanceForSameValue(): void
    {
        $this->assertSame(UnknownOrderStatus::of('PENDING_REVIEW'), UnknownOrderStatus::of('PENDING_REVIEW'));
    }

    // -------------------------------------------------------------------------
    // WidgetType
    // -------------------------------------------------------------------------

    public function testWidgetTypeFromApiValueReturnsKnownCase(): void
    {
        $this->assertSame(WidgetType::STANDARD, WidgetType::fromApiValue('standard'));
    }

    public function testWidgetTypeKnownCaseIsKnown(): void
    {
        $this->assertTrue(WidgetType::CLASSIC->isKnown());
    }

    public function testWidgetTypeGetValueReturnsBackingValue(): void
    {
        $this->assertSame('classic', WidgetType::CLASSIC->getValue());
    }

    public function testWidgetTypeFromApiValueReturnsUnknownForUnrecognisedValue(): void
    {
        $result = WidgetType::fromApiValue('future_widget');

        $this->assertFalse($result->isKnown());
        $this->assertSame('future_widget', $result->getValue());
    }

    public function testUnknownWidgetTypeFlyweightReturnsSameInstance(): void
    {
        $this->assertSame(UnknownWidgetType::of('new_widget'), UnknownWidgetType::of('new_widget'));
    }

    public function testUnknownWidgetTypeDifferentValuesDifferentInstances(): void
    {
        $this->assertNotSame(UnknownWidgetType::of('widget_a'), UnknownWidgetType::of('widget_b'));
    }

    public function testUnknownWidgetTypeIsNotKnown(): void
    {
        $this->assertFalse(UnknownWidgetType::of('any_widget')->isKnown());
    }

    public function testUnknownWidgetTypeToStringReturnsValue(): void
    {
        $this->assertSame('custom_widget', (string) UnknownWidgetType::of('custom_widget'));
    }

    // -------------------------------------------------------------------------
    // UnknownOrderStatus
    // -------------------------------------------------------------------------

    public function testUnknownOrderStatusGetValueReturnsRawApiValue(): void
    {
        $this->assertSame('FUTURE_STATUS', UnknownOrderStatus::of('FUTURE_STATUS')->getValue());
    }

    public function testUnknownOrderStatusIsNotKnown(): void
    {
        $this->assertFalse(UnknownOrderStatus::of('UNKNOWN_STATUS')->isKnown());
    }

    public function testUnknownOrderStatusToStringReturnsValue(): void
    {
        $this->assertSame('PENDING_REVIEW', (string) UnknownOrderStatus::of('PENDING_REVIEW'));
    }

    public function testUnknownOrderStatusDifferentValuesDifferentInstances(): void
    {
        $this->assertNotSame(UnknownOrderStatus::of('STATUS_A'), UnknownOrderStatus::of('STATUS_B'));
    }

    // -------------------------------------------------------------------------
    // OrderStatus - all known cases and value access
    // -------------------------------------------------------------------------

    public function testOrderStatusAllKnownCasesAreKnown(): void
    {
        foreach (OrderStatus::cases() as $case) {
            $this->assertTrue($case->isKnown(), "Expected OrderStatus::{$case->name} to be known");
        }
    }

    public function testOrderStatusGetValueMatchesBackingValue(): void
    {
        $this->assertSame('CREATED', OrderStatus::CREATED->getValue());
        $this->assertSame('CANCELLED_BY_SHOP', OrderStatus::CANCELLED_BY_SHOP->getValue());
        $this->assertSame('RESIGN', OrderStatus::RESIGN->getValue());
    }

    // -------------------------------------------------------------------------
    // WidgetType - all known cases
    // -------------------------------------------------------------------------

    public function testWidgetTypeAllKnownCasesAreKnown(): void
    {
        foreach (WidgetType::cases() as $case) {
            $this->assertTrue($case->isKnown(), "Expected WidgetType::{$case->name} to be known");
        }
    }
}
