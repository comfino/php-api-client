<?php

/**
 * ComfinoPay PHP API client
 *
 * Backend routines for communication with the ComfinoPay payment gateway REST API.
 *
 * @package Comfino\Tests\Unit\Api\Validation
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-api-client
 */

declare(strict_types=1);

namespace Comfino\Tests\Unit\Api\Validation;

use Comfino\Api\Validation\UrlValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class UrlValidatorTest extends TestCase
{
    protected function setUp(): void
    {
        $this->clearDevEnvGate();
    }

    protected function tearDown(): void
    {
        $this->clearDevEnvGate();
    }

    #[DataProvider('allowedUrlDataProvider')]
    public function testIsAllowedUrlAcceptsSafeDestinations(string $url): void
    {
        $this->assertTrue(UrlValidator::isAllowedUrl($url));
    }

    #[DataProvider('rejectedUrlDataProvider')]
    public function testIsAllowedUrlRejectsUnsafeDestinations(string $url): void
    {
        $this->assertFalse(UrlValidator::isAllowedUrl($url));
    }

    /**
     * A destination that is otherwise fine but reached over cleartext http carries the API key in the clear, so it is
     * refused until the deployment opts in.
     */
    #[DataProvider('httpDevUrlDataProvider')]
    public function testIsAllowedUrlRejectsPlainHttpDevDestinationsByDefault(string $url): void
    {
        $this->assertFalse(UrlValidator::isAllowedUrl($url));
    }

    #[DataProvider('httpDevUrlDataProvider')]
    public function testIsAllowedUrlAcceptsPlainHttpDevDestinationsWhenGateIsOpen(string $url): void
    {
        $this->openDevEnvGate();

        $this->assertTrue(UrlValidator::isAllowedUrl($url));
    }

    /**
     * The gate widens the scheme for dev destinations only. It must never turn a spoofed host, a public IP or the real
     * API over http into an allowed destination - otherwise setting it in a container would undo the host allowlist too.
     */
    #[DataProvider('rejectedUrlDataProvider')]
    public function testGateDoesNotWidenTheHostAllowlist(string $url): void
    {
        $this->openDevEnvGate();

        $this->assertFalse(UrlValidator::isAllowedUrl($url));
    }

    /**
     * The value is compared strictly, character for character as every ComfinoPay plugin spells it. A looser parser here
     * would let the library consider dev mode on while its host plugin considers it off.
     */
    public function testDevEnvActiveRequiresTheExactPluginValue(): void
    {
        putenv(UrlValidator::DEV_ENV_VAR . '=TRUE');

        $this->assertTrue(UrlValidator::devEnvActive());
    }

    #[DataProvider('rejectedEnvValueDataProvider')]
    public function testDevEnvActiveRejectsEverythingElse(string $value): void
    {
        putenv(UrlValidator::DEV_ENV_VAR . '=' . $value);

        $this->assertFalse(UrlValidator::devEnvActive());
    }

    public function testDevEnvActiveIsFalseWhenUnset(): void
    {
        $this->assertFalse(UrlValidator::devEnvActive());
    }

    public function testDescribedDestinationsNameTheGateWhenItIsShut(): void
    {
        $description = UrlValidator::describeAllowedDestinations();

        $this->assertStringContainsString(UrlValidator::DEV_ENV_VAR, $description);
        $this->assertStringContainsString('over HTTPS', $description);
    }

    public function testDescribedDestinationsSayHttpIsEnabledWhenGateIsOpen(): void
    {
        $this->openDevEnvGate();

        $this->assertStringContainsString('plain http is enabled', UrlValidator::describeAllowedDestinations());
    }

    /** @return array<string, array{string}> */
    public static function allowedUrlDataProvider(): array
    {
        return [
            'comfino apex domain over https' => ['https://comfino.pl'],
            'comfino subdomain over https' => ['https://api-ecommerce.comfino.pl'],
            'widget subdomain over https' => ['https://widget.comfino.pl/sdk/v1/comfino-sdk.min.js'],
            'craty subdomain over https' => ['https://widget.craty.pl/sdk/v1/comfino-sdk.min.js'],
            'koszulawcraty subdomain over https' => ['https://api.koszulawcraty.pl'],
            'private RFC1918 ip over https' => ['https://192.168.1.10:8443'],
            'loopback ip over https' => ['https://127.0.0.1:3000'],
            'docker bridge ip over https' => ['https://172.17.0.2'],
            'single-label docker service name over https' => ['https://comfino-sdk/sdk.js'],
            'localhost over https' => ['https://localhost:8443/sdk.js'],
            'dev .test host over https' => ['https://widget-comfino.test/sdk/v1/comfino-sdk.js'],
        ];
    }

    /**
     * Dev destinations over plain http: allowed only while the gate is open.
     *
     * @return array<string, array{string}>
     */
    public static function httpDevUrlDataProvider(): array
    {
        return [
            'private RFC1918 ip' => ['http://192.168.1.10:8080'],
            'loopback ip' => ['http://127.0.0.1:3000'],
            'docker bridge ip' => ['http://172.17.0.2'],
            'ipv6 loopback' => ['http://[::1]:8080'],
            'single-label docker service name' => ['http://comfino-sdk/sdk.js'],
            'localhost' => ['http://localhost:8080/sdk.js'],
            'dev .test host over http with port' => ['http://widget-comfino.test:8080/sdk/v1/comfino-sdk.js'],
            'dev .test multi-level subdomain' => ['http://api-ecommerce.comfino.test'],
            'dev .test apex' => ['http://shop.test'],
        ];
    }

    /** @return array<string, array{string}> */
    public static function rejectedUrlDataProvider(): array
    {
        return [
            'arbitrary public domain over https' => ['https://dev-cdn.example/sdk.js'],
            'comfino lookalike suffix attack' => ['https://evilcomfino.pl'],
            'comfino in path of foreign host' => ['https://attacker.example/comfino.pl'],
            'public ip' => ['http://8.8.8.8'],
            'public ip over https' => ['https://8.8.8.8'],
            'non-http scheme' => ['ftp://comfino.pl/file'],
            'javascript scheme' => ['javascript:alert(1)'],
            'comfino domain over plain http' => ['http://api-ecommerce.comfino.pl'],
            'dev tld as lookalike suffix on public domain' => ['https://evil.test.attacker.com'],
            'empty string' => [''],
            'host-less url' => ['/sdk/v1/comfino-sdk.min.js'],
        ];
    }

    /** @return array<string, array{string}> */
    public static function rejectedEnvValueDataProvider(): array
    {
        return [
            'lowercase true' => ['true'],
            'mixed case' => ['True'],
            'one' => ['1'],
            'yes' => ['yes'],
            'on' => ['on'],
            'padded' => [' TRUE '],
            'false' => ['FALSE'],
            'empty' => [''],
            'arbitrary text' => ['maybe'],
        ];
    }

    private function openDevEnvGate(): void
    {
        putenv(UrlValidator::DEV_ENV_VAR . '=' . UrlValidator::DEV_ENV_ENABLED_VALUE);
    }

    private function clearDevEnvGate(): void
    {
        putenv(UrlValidator::DEV_ENV_VAR);
    }
}
