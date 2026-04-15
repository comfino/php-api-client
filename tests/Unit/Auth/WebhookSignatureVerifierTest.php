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

use Comfino\Auth\WebhookSignatureVerifier;
use PHPUnit\Framework\TestCase;

final class WebhookSignatureVerifierTest extends TestCase
{
    private WebhookSignatureVerifier $verifier;

    protected function setUp(): void
    {
        $this->verifier = new WebhookSignatureVerifier();
    }

    public function testVerifiesValidSignature(): void
    {
        $apiKey = 'secret-key';
        $body = '{"status":"ACCEPTED","orderId":"12345"}';
        $signature = hash('sha3-256', $apiKey . $body);

        $this->assertTrue($this->verifier->verify($signature, $apiKey, $body));
    }

    public function testRejectsInvalidSignature(): void
    {
        $this->assertFalse($this->verifier->verify('invalid', 'key', 'body'));
    }

    public function testRejectsEmptySignature(): void
    {
        $this->assertFalse($this->verifier->verify('', 'secret-key', 'some body'));
    }

    public function testVerifiesGetRequestWithValidKey(): void
    {
        $apiKey = 'secret-key';
        $validationKey = 'test_vkey';
        $signature = hash('sha3-256', $apiKey . $validationKey);

        $this->assertTrue($this->verifier->verify($signature, $apiKey, $validationKey));
    }

    public function testSignatureIsCaseSensitive(): void
    {
        $apiKey = 'secret-key';
        $body = 'test-body';
        $validSignature = hash('sha3-256', $apiKey . $body);
        $upperSignature = strtoupper($validSignature);

        $this->assertTrue($this->verifier->verify($validSignature, $apiKey, $body));
        $this->assertFalse($this->verifier->verify($upperSignature, $apiKey, $body));
    }

    public function testDifferentApiKeyFails(): void
    {
        $body = '{"test":"data"}';
        $signature = hash('sha3-256', 'correct-key' . $body);

        $this->assertFalse($this->verifier->verify($signature, 'wrong-key', $body));
    }
}
