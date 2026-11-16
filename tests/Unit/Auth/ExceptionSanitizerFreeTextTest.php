<?php

/**
 * ComfinoPay PHP API client
 *
 * Backend routines for communication with the ComfinoPay payment gateway REST API.
 *
 * @package Comfino\Tests\Unit
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-api-client
 */

declare(strict_types=1);

namespace Comfino\Tests\Unit\Auth;

use Comfino\Auth\ExceptionSanitizer;
use PHPUnit\Framework\TestCase;

final class ExceptionSanitizerFreeTextTest extends TestCase
{
    public function testMasksEmailPhoneAndIpAndKeepsTheRest(): void
    {
        $text = 'Cannot reach jan.kowalski@example.com (+48 600 123 456) from 192.168.1.10 for order 100000123, amount 1299.00';

        $this->assertSame(
            'Cannot reach [REDACTED] ([REDACTED]) from [REDACTED] for order 100000123, amount 1299.00',
            ExceptionSanitizer::redactText($text)
        );
    }

    /**
     * @dataProvider phoneProvider
     */
    public function testPhoneForms(string $input, string $expected): void
    {
        $this->assertSame($expected, ExceptionSanitizer::redactText($input));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function phoneProvider(): array
    {
        return [
            'plus and spaces' => ['call +48 600 123 456 now', 'call [REDACTED] now'],
            'dashes' => ['phone 600-123-456 given', 'phone [REDACTED] given'],
            'country prefix without separators' => ['phone 48600123456 given', 'phone [REDACTED] given'],
            'order id is kept' => ['order 100000123 failed', 'order 100000123 failed'],
            'amount is kept' => ['total 1299.00 PLN', 'total 1299.00 PLN'],
            'date is kept' => ['on 2026-10-17 at 12:30', 'on 2026-10-17 at 12:30'],
        ];
    }

    public function testMasksIpv6AndLeavesVersionNumbers(): void
    {
        $this->assertSame('from [REDACTED] ok', ExceptionSanitizer::redactText('from 2001:db8::ff00:42:8329 ok'));
        $this->assertSame('php 8.5.11 ok', ExceptionSanitizer::redactText('php 8.5.11 ok'));
    }

    public function testMasksCredentialsInFreeText(): void
    {
        $this->assertSame('GET failed apiKey=[REDACTED] end', ExceptionSanitizer::redactText('GET failed apiKey=abc123secret end'));
        $this->assertSame('Authorization: Bearer [REDACTED]', ExceptionSanitizer::redactText('Authorization: Bearer abc.def-ghi'));
        $this->assertSame('sig [REDACTED]', ExceptionSanitizer::redactText('sig ' . str_repeat('ab12', 16)));
    }

    public function testRedactTextIsIdempotent(): void
    {
        $once = ExceptionSanitizer::redactText('a@b.pl +48 600 123 456 10.0.0.1 token=xyz');

        $this->assertSame($once, ExceptionSanitizer::redactText($once));
    }

    public function testTruncateNeverSplitsACharacter(): void
    {
        $text = str_repeat('zażółć', 20);

        for ($max = 1; $max < 60; $max++) {
            $cut = ExceptionSanitizer::truncate($text, $max);

            $this->assertLessThanOrEqual($max, strlen($cut));
            $this->assertMatchesRegularExpression('//u', $cut, "Invalid UTF-8 at $max");
        }

        $this->assertStringEndsWith('…', ExceptionSanitizer::truncate($text, 30));
    }

    public function testTruncateKeepsShortTextAndHandlesZero(): void
    {
        $this->assertSame('short', ExceptionSanitizer::truncate('short', 512));
        $this->assertSame('', ExceptionSanitizer::truncate('anything', 0));
    }
}
