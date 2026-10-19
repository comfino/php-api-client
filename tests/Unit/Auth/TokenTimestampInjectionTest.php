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

use Comfino\Api\Validation\UrlValidator;
use Comfino\Auth\FrontendLogAuthKeyGenerator;
use Comfino\Auth\PaywallAuthKeyGenerator;
use PHPUnit\Framework\TestCase;
use SodiumException;

final class TokenTimestampInjectionTest extends TestCase
{
    private const WIDGET_KEY = '550e8400-e29b-41d4-a716-446655440000';

    /**
     * @throws SodiumException
     */
    public function testPaywallTokenIsReproducibleForAGivenTimestamp(): void
    {
        $timestamp = pack('J', 1790000000);
        $expected = sodium_bin2base64(
            $timestamp . hash_hmac('sha3-256', $timestamp . self::WIDGET_KEY, 'api-key', true) . self::WIDGET_KEY,
            SODIUM_BASE64_VARIANT_ORIGINAL
        );

        $generator = new PaywallAuthKeyGenerator();

        $this->assertSame($expected, $generator->generateAuthKey(self::WIDGET_KEY, 'api-key', 1790000000));
        $this->assertSame($expected, $generator->generateAuthKey(self::WIDGET_KEY, 'api-key', 1790000000));
    }

    /**
     * @throws SodiumException
     */
    public function testLoggingTokenIsReproducibleForAGivenTimestamp(): void
    {
        $version = pack('C', FrontendLogAuthKeyGenerator::VERSION);
        $timestamp = pack('J', 1790000000);
        $hmac = hash_hmac('sha3-256', FrontendLogAuthKeyGenerator::DOMAIN . $version . $timestamp . self::WIDGET_KEY, 'signing-key', true);

        $this->assertSame(
            sodium_bin2base64($version . $timestamp . $hmac . self::WIDGET_KEY, SODIUM_BASE64_VARIANT_ORIGINAL),
            (new FrontendLogAuthKeyGenerator())->generateToken(self::WIDGET_KEY, 'signing-key', 1790000000)
        );
    }

    public function testIsComfinoHost(): void
    {
        $this->assertTrue(UrlValidator::isComfinoHost('https://app.comfino.pl/x'));
        $this->assertTrue(UrlValidator::isComfinoHost('https://comfino.pl'));
        $this->assertTrue(UrlValidator::isComfinoHost('https://APP.Craty.pl./x'));
        $this->assertFalse(UrlValidator::isComfinoHost('https://evilcomfino.pl/x'));
        $this->assertFalse(UrlValidator::isComfinoHost('https://comfino.pl.evil.com/x'));
        $this->assertFalse(UrlValidator::isComfinoHost('https://shop.test/x'));
        $this->assertFalse(UrlValidator::isComfinoHost('/relative'));
        $this->assertFalse(UrlValidator::isComfinoHost(''));
    }
}
