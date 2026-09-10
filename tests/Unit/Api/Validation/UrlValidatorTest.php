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

    /** @return array<string, array{string}> */
    public static function allowedUrlDataProvider(): array
    {
        return [
            'comfino apex domain over https' => ['https://comfino.pl'],
            'comfino subdomain over https' => ['https://api-ecommerce.comfino.pl'],
            'widget subdomain over https' => ['https://widget.comfino.pl/sdk/v1/comfino-sdk.min.js'],
            'craty subdomain over https' => ['https://widget.craty.pl/sdk/v1/comfino-sdk.min.js'],
            'koszulawcraty subdomain over https' => ['https://api.koszulawcraty.pl'],
            'private RFC1918 ip' => ['http://192.168.1.10:8080'],
            'loopback ip' => ['http://127.0.0.1:3000'],
            'docker bridge ip' => ['http://172.17.0.2'],
            'single-label docker service name' => ['http://comfino-sdk/sdk.js'],
            'localhost' => ['http://localhost:8080/sdk.js'],
            'dev .test host over http with port' => ['http://widget-comfino.test:8080/sdk/v1/comfino-sdk.js'],
            'dev .test host over https' => ['https://widget-comfino.test/sdk/v1/comfino-sdk.js'],
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
            'non-http scheme' => ['ftp://comfino.pl/file'],
            'javascript scheme' => ['javascript:alert(1)'],
            'comfino domain over plain http' => ['http://api-ecommerce.comfino.pl'],
            'dev tld as lookalike suffix on public domain' => ['https://evil.test.attacker.com'],
            'empty string' => [''],
            'host-less url' => ['/sdk/v1/comfino-sdk.min.js'],
        ];
    }
}
