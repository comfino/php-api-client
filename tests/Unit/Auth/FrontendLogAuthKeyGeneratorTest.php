<?php

/**
 * Comfino PHP API client
 *
 * Backend routines for communication with the Comfino payment gateway REST API.
 *
 * @package Comfino\Tests\Unit\Auth
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-api-client
 */

declare(strict_types=1);

namespace Comfino\Tests\Unit\Auth;

use Comfino\Auth\FrontendLogAuthKeyGenerator;
use PHPUnit\Framework\TestCase;
use SodiumException;

final class FrontendLogAuthKeyGeneratorTest extends TestCase
{
    /**
     * @throws SodiumException
     */
    public function testGeneratesExactly77ByteToken(): void
    {
        $generator = new FrontendLogAuthKeyGenerator();
        $widgetKey = '550e8400-e29b-41d4-a716-446655440000'; // 36-char UUID
        $accessToken = 'a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2'; // 64-char hex

        $token = $generator->generateToken($widgetKey, $accessToken);
        $decoded = sodium_base642bin($token, SODIUM_BASE64_VARIANT_ORIGINAL);

        // version(1) + timestamp(8) + hmac(32) + widgetKey(36) = 77
        $this->assertSame(77, strlen($decoded));
        $this->assertSame(FrontendLogAuthKeyGenerator::VERSION, unpack('C', substr($decoded, 0, 1))[1]);
        $this->assertSame($widgetKey, substr($decoded, 41, 36));

        $timestamp = unpack('J', substr($decoded, 1, 8))[1];

        $this->assertGreaterThanOrEqual(time() - 5, $timestamp);
        $this->assertLessThanOrEqual(time() + 5, $timestamp);
    }

    /**
     * @throws SodiumException
     */
    public function testTokenIsBase64Encoded(): void
    {
        $token = (new FrontendLogAuthKeyGenerator())->generateToken(
            '550e8400-e29b-41d4-a716-446655440000',
            'a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2'
        );

        $this->assertNotEmpty($token);
        $this->assertNotEmpty(sodium_base642bin($token, SODIUM_BASE64_VARIANT_ORIGINAL));
    }

    /**
     * @throws SodiumException
     */
    public function testDifferentWidgetKeysProduceDifferentTokens(): void
    {
        $generator = new FrontendLogAuthKeyGenerator();
        $accessToken = 'a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2'; // 64-char hex

        $token1 = $generator->generateToken('00000000-0000-0000-0000-000000000001', $accessToken);
        $token2 = $generator->generateToken('00000000-0000-0000-0000-000000000002', $accessToken);

        $this->assertNotEquals($token1, $token2);
    }

    /**
     * The HMAC must reproduce from the domain tag + version + timestamp + widgetKey, keyed with the access token.
     *
     * @throws SodiumException
     */
    public function testHmacIsCorrectAndDomainSeparated(): void
    {
        $widgetKey = '550e8400-e29b-41d4-a716-446655440000';
        $accessToken = 'a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2'; // 64-char hex

        $token = (new FrontendLogAuthKeyGenerator())->generateToken($widgetKey, $accessToken);
        $decoded = sodium_base642bin($token, SODIUM_BASE64_VARIANT_ORIGINAL);

        $versionByte = $decoded[0];
        $timestampBytes = substr($decoded, 1, 8);
        $hmacInToken = substr($decoded, 9, 32);

        $expectedHmac = hash_hmac(
            'sha3-256',
            FrontendLogAuthKeyGenerator::DOMAIN . $versionByte . $timestampBytes . $widgetKey,
            $accessToken,
            true
        );

        $this->assertSame($expectedHmac, $hmacInToken);

        /* Domain separation: the same bytes signed WITHOUT the domain tag (the paywall scheme) must differ,
           so a logging token can never be replayed as a paywall token. */
        $paywallStyleHmac = hash_hmac('sha3-256', $timestampBytes . $widgetKey, $accessToken, true);

        $this->assertNotSame($paywallStyleHmac, $hmacInToken);
    }
}
