<?php

/**
 * Comfino PHP API client
 *
 * Backend routines for communication with the Comfino payment gateway REST API.
 *
 * @package Comfino\Tests\Integration\Api
 * @author Artur Kozubski <akozubski@comperia.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-api-client
 */

declare(strict_types=1);

namespace Comfino\Tests\Integration\Api;

use Comfino\Api\Client;
use Comfino\Api\Dto\Payment\FinancialProduct;
use Comfino\Api\Dto\Payment\LoanQueryCriteria;
use Comfino\Enum\LoanTypeInterface;
use Comfino\Enum\ProductListType;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Sunrise\Http\Client\Curl\Client as CurlClient;

/**
 * Integration tests for the API client against the Comfino sandbox environment.
 *
 * These tests require a valid sandbox API key set in the COMFINO_SANDBOX_API_KEY
 * environment variable. They are skipped automatically when the key is absent.
 *
 * To run:
 *   COMFINO_SANDBOX_API_KEY=your-key ./vendor/bin/phpunit --testsuite Integration
 *
 * Or set the key in a local phpunit.xml (git ignored):
 *   <env name="COMFINO_SANDBOX_API_KEY" value="your-key"/>
 */
final class ClientTest extends TestCase
{
    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $apiKey = getenv('COMFINO_SANDBOX_API_KEY');

        if (empty($apiKey)) {
            $this->markTestSkipped('COMFINO_SANDBOX_API_KEY is not set.');
        }

        $psr17Factory = new Psr17Factory();

        $this->client = new Client(
            httpClient: new CurlClient($psr17Factory),
            requestFactory: $psr17Factory,
            streamFactory: $psr17Factory,
            apiKey: $apiKey,
        );

        $this->client->setCustomUserAgent('comfino-php-api-client-integration-test/' . Client::CLIENT_VERSION);
        $this->client->enableSandboxMode();
    }

    /**
     * @throws ClientExceptionInterface
     */
    public function testIsShopAccountActive(): void
    {
        $isActive = $this->client->isShopAccountActive();

        $this->assertTrue($isActive, 'Sandbox account should be active for a valid API key.');
    }

    /**
     * @throws ClientExceptionInterface
     */
    public function testGetWidgetKey(): void
    {
        $this->assertNotEmpty($this->client->getWidgetKey(), 'Widget key should not be empty.');
    }

    /**
     * @throws ClientExceptionInterface
     */
    public function testGetProductTypes(): void
    {
        $response = $this->client->getProductTypes(ProductListType::WIDGET);

        $this->assertNotEmpty($response->productTypes, 'At least one product type should be available.');

        foreach ($response->productTypes as $productType) {
            $this->assertInstanceOf(LoanTypeInterface::class, $productType);
        }
    }

    /**
     * @throws ClientExceptionInterface
     */
    public function testGetFinancialProducts(): void
    {
        // 1 500 PLN cart - a typical installment-eligible amount.
        $criteria = new LoanQueryCriteria(loanAmount: 150000);

        $response = $this->client->getFinancialProducts($criteria);

        $this->assertNotEmpty($response->financialProducts, 'At least one financial product should be available.');

        foreach ($response->financialProducts as $product) {
            $this->assertInstanceOf(FinancialProduct::class, $product);
            $this->assertNotEmpty($product->name);
            $this->assertInstanceOf(LoanTypeInterface::class, $product->type);
            $this->assertGreaterThan(0, $product->instalmentAmount);
            $this->assertGreaterThan(0, $product->toPay);
            $this->assertGreaterThan(0, $product->loanTerm);
            $this->assertNotEmpty($product->loanParameters);
        }
    }

    /**
     * @throws ClientExceptionInterface
     */
    public function testGetFinancialProductsWithLoanType(): void
    {
        $productTypesResponse = $this->client->getProductTypes(ProductListType::WIDGET);

        if (empty($productTypesResponse->productTypes)) {
            $this->markTestSkipped('No product types available in sandbox.');
        }

        $criteria = new LoanQueryCriteria(
            loanAmount: 150000,
            loanType: $productTypesResponse->productTypes[0]
        );

        $response = $this->client->getFinancialProducts($criteria);

        // Filtering by loan type may return fewer products but must not error - reaching here is enough.
    }
}
