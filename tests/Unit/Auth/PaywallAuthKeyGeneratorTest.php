<?php

/**
 * Comfino PHP API client
 *
 * Backend routines for communication with the Comfino payment gateway REST API.
 *
 * @package Comfino\Tests\Unit\Auth
 * @author Artur Kozubski <akozubski@comperia.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-api-client
 */

declare(strict_types=1);

namespace Comfino\Tests\Unit\Auth;

use Comfino\Auth\PaywallAuthKeyGenerator;
use PHPUnit\Framework\TestCase;

final class PaywallAuthKeyGeneratorTest extends TestCase
{
    /**
     * @throws \SodiumException
     */
    public function testGeneratesExactly76ByteToken(): void
    {
        $generator = new PaywallAuthKeyGenerator();
        $widgetKey = '550e8400-e29b-41d4-a716-446655440000'; // 36-char UUID
        $apiKey = 'test-api-key';

        $token = $generator->generateAuthKey($widgetKey, $apiKey);
        $decoded = sodium_base642bin($token, SODIUM_BASE64_VARIANT_ORIGINAL);

        $this->assertSame(76, strlen($decoded));
        $this->assertSame($widgetKey, substr($decoded, 40, 36));

        $timestamp = unpack('J', substr($decoded, 0, 8))[1];

        $this->assertGreaterThanOrEqual(time() - 5, $timestamp);
        $this->assertLessThanOrEqual(time() + 5, $timestamp);
    }

    /**
     * @throws \SodiumException
     */
    public function testTokenIsBase64Encoded(): void
    {
        $token = (new PaywallAuthKeyGenerator())->generateAuthKey('550e8400-e29b-41d4-a716-446655440000', 'api-key');

        $this->assertNotEmpty($token);

        // Sodium base64 should decode without error.
        $this->assertNotEmpty(sodium_base642bin($token, SODIUM_BASE64_VARIANT_ORIGINAL));
    }

    /**
     * @throws \SodiumException
     */
    public function testDifferentWidgetKeysProduceDifferentTokens(): void
    {
        $generator = new PaywallAuthKeyGenerator();
        $apiKey = 'test-api-key';

        $token1 = $generator->generateAuthKey('00000000-0000-0000-0000-000000000001', $apiKey);
        $token2 = $generator->generateAuthKey('00000000-0000-0000-0000-000000000002', $apiKey);

        $this->assertNotEquals($token1, $token2);
    }

    /**
     * @throws \SodiumException
     */
    public function testDifferentApiKeysProduceDifferentTokens(): void
    {
        $generator = new PaywallAuthKeyGenerator();
        $widgetKey = '550e8400-e29b-41d4-a716-446655440000';

        $token1 = $generator->generateAuthKey($widgetKey, 'api-key-1');
        $token2 = $generator->generateAuthKey($widgetKey, 'api-key-2');

        $decoded1 = sodium_base642bin($token1, SODIUM_BASE64_VARIANT_ORIGINAL);
        $decoded2 = sodium_base642bin($token2, SODIUM_BASE64_VARIANT_ORIGINAL);

        // Timestamps may be the same, but HMAC (bytes 8-39) must differ.
        $this->assertNotEquals(substr($decoded1, 8, 32), substr($decoded2, 8, 32));
    }

    /**
     * @throws \SodiumException
     */
    public function testHmacIsCorrect(): void
    {
        $widgetKey = '550e8400-e29b-41d4-a716-446655440000';
        $apiKey = 'test-api-key';

        $token = (new PaywallAuthKeyGenerator())->generateAuthKey($widgetKey, $apiKey);
        $decoded = sodium_base642bin($token, SODIUM_BASE64_VARIANT_ORIGINAL);

        $timestampBytes = substr($decoded, 0, 8);
        $hmacInToken = substr($decoded, 8, 32);
        $expectedHmac = hash_hmac('sha3-256', $timestampBytes . $widgetKey, $apiKey, true);

        $this->assertSame($expectedHmac, $hmacInToken);
    }
}
