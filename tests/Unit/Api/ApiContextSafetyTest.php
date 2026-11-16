<?php

/**
 * ComfinoPay PHP API client
 *
 * Backend routines for communication with the ComfinoPay payment gateway REST API.
 *
 * @package Comfino\Tests\Unit\Api
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-api-client
 */

declare(strict_types=1);

namespace Comfino\Tests\Unit\Api;

use Comfino\Api\ApiContext;
use Comfino\Api\BoundClient;
use Comfino\Api\Client;
use Comfino\Api\SharedClient;
use Http\Mock\Client as MockHttpClient;
use InvalidArgumentException;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Two things a context must not do: let a caller-supplied value become extra headers, and print its credential.
 */
final class ApiContextSafetyTest extends TestCase
{
    private const API_KEY = 'merchant-secret-key';

    // -------------------------------------------------------------------------
    // Header injection
    // -------------------------------------------------------------------------

    /**
     * Every one of these values becomes a header value in `SharedClient::buildPsrRequest()`. Only the custom headers
     * were checked for CR/LF, which was arbitrary - these are just as caller-supplied, and `apiLanguage` is commonly
     * wired straight to a shop's locale setting.
     */
    #[DataProvider('injectableFieldProvider')]
    public function testAControlCharacterIsRejectedWhereverItWouldBecomeAHeader(string $field, string $payload): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->contextWith($field, $payload);
    }

    /** @return iterable<string, array{string, string}> */
    public static function injectableFieldProvider(): iterable
    {
        $payloads = [
            'CRLF header injection' => "pl\r\nX-Injected: yes",
            'bare LF' => "pl\nX-Injected: yes",
            'bare CR' => "pl\rX-Injected: yes",
            'NUL truncation' => "pl\x00ignored",
            'other C0 control' => "pl\x07",
            'DEL' => "pl\x7F",
        ];

        foreach (['apiKey', 'apiLanguage', 'apiCurrency', 'userAgent'] as $field) {
            foreach ($payloads as $label => $payload) {
                yield "$field / $label" => [$field, $payload];
            }
        }
    }

    /**
     * The check must reject control characters without rejecting legitimate values, or it would break a UTF-8
     * User-Agent and any currency or locale spelling a host platform happens to use.
     */
    #[DataProvider('legitimateValueProvider')]
    public function testLegitimateValuesAreStillAccepted(string $field, string $value): void
    {
        $context = $this->contextWith($field, $value);

        $this->assertSame($value, $context->$field);
    }

    /** @return array<string, array{string, string}> */
    public static function legitimateValueProvider(): array
    {
        return [
            'plain language' => ['apiLanguage', 'pl'],
            'hyphenated locale' => ['apiLanguage', 'pl-PL'],
            'underscored locale' => ['apiLanguage', 'pl_PL'],
            'currency' => ['apiCurrency', 'PLN'],
            'user agent with spaces and slashes' => ['userAgent', 'Comfino WooCommerce/8.5 (PHP 8.1)'],
            'user agent with a tab' => ['userAgent', "Comfino\tWooCommerce"],
            'user agent with UTF-8' => ['userAgent', 'Sklep Kwiatowy — Zamówienia'],
            'api key with punctuation' => ['apiKey', 'ak_live.9f8e-7d6c+5b4a/3210=='],
        ];
    }

    public function testTheSameCheckStillGuardsCustomHeaders(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ApiContext(self::API_KEY, customHeaders: ['X-Shop' => "ok\r\nX-Injected: yes"]);
    }

    public function testACustomHeaderNameIsStillRequiredToBeAToken(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ApiContext(self::API_KEY, customHeaders: ['Bad Header Name' => 'value']);
    }

    /**
     * The legacy surface stores these as plain fields and builds a context per call, so the check lands at send time
     * rather than at set time. Later than ideal, but still before anything reaches the wire - which is what matters.
     */
    public function testTheLegacySurfaceAlsoFailsClosedOnAnInjectedLanguage(): void
    {
        $factory = new Psr17Factory();
        $client = new Client(new MockHttpClient($factory), $factory, $factory, self::API_KEY);

        $client->setApiLanguage("pl\r\nX-Injected: yes");

        $this->expectException(InvalidArgumentException::class);

        $client->isShopAccountActive();
    }

    // -------------------------------------------------------------------------
    // Credential redaction in dumps
    // -------------------------------------------------------------------------

    public function testDumpingAContextDoesNotPrintTheApiKey(): void
    {
        $context = new ApiContext(self::API_KEY, tenantKey: 'shop-7');

        foreach ([$this->dump($context), print_r($context, true)] as $rendered) {
            $this->assertStringNotContainsString(self::API_KEY, $rendered);
            $this->assertStringContainsString('[REDACTED]', $rendered);
            // Everything a developer dumped the context to look at is still there.
            $this->assertStringContainsString('shop-7', $rendered);
        }
    }

    public function testDumpingABoundClientDoesNotPrintTheApiKey(): void
    {
        $factory = new Psr17Factory();
        $shared = new SharedClient(new MockHttpClient($factory), $factory, $factory);
        $client = new BoundClient($shared, new ApiContext(self::API_KEY));

        $this->assertStringNotContainsString(self::API_KEY, $this->dump($client));
        $this->assertStringNotContainsString(self::API_KEY, print_r($client, true));
    }

    public function testDumpingALegacyClientDoesNotPrintTheApiKey(): void
    {
        $factory = new Psr17Factory();
        $client = new Client(new MockHttpClient($factory), $factory, $factory, self::API_KEY);

        $this->assertStringNotContainsString(self::API_KEY, $this->dump($client));
        $this->assertStringNotContainsString(self::API_KEY, print_r($client, true));
    }

    /**
     * A plugin's own credential headers are redacted too, while the ones someone is actually dumping the context to
     * read stay readable.
     */
    public function testSensitiveCustomHeadersAreRedactedAndTheRestAreNot(): void
    {
        $context = new ApiContext(self::API_KEY, customHeaders: [
            'Authorization' => 'Bearer super-secret-token',
            'X-Api-Key' => 'another-secret',
            'X-Shop-Platform' => 'WooCommerce/8.5',
        ]);

        $rendered = $this->dump($context);

        $this->assertStringNotContainsString('super-secret-token', $rendered);
        $this->assertStringNotContainsString('another-secret', $rendered);
        $this->assertStringContainsString('WooCommerce/8.5', $rendered);
    }

    public function testAnEmptyApiKeyIsNotDressedUpAsARedactedOne(): void
    {
        // "[REDACTED]" where there is no credential would send someone hunting for a key that was never configured.
        $this->assertStringNotContainsString('[REDACTED]', $this->dump(new ApiContext('')));
    }

    /**
     * Builds a context with one field replaced, so the provider can name the field under test.
     */
    private function contextWith(string $field, string $value): ApiContext
    {
        return new ApiContext(
            $field === 'apiKey' ? $value : self::API_KEY,
            apiLanguage: $field === 'apiLanguage' ? $value : 'pl',
            apiCurrency: $field === 'apiCurrency' ? $value : 'PLN',
            userAgent: $field === 'userAgent' ? $value : null
        );
    }

    private function dump(object $subject): string
    {
        ob_start();
        var_dump($subject);

        return (string) ob_get_clean();
    }
}
