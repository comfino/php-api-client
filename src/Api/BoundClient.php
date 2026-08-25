<?php

/**
 * Comfino PHP API client
 *
 * Backend routines for communication with the Comfino payment gateway REST API.
 *
 * @package Comfino\Api
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-api-client
 */

declare(strict_types=1);

namespace Comfino\Api;

use Comfino\Api\Dto\Payment\LoanQueryCriteria;
use Comfino\Api\Dto\Plugin\ShopEnvironmentReport;
use Comfino\Api\Dto\Plugin\ShopPluginError;
use Comfino\Api\Response\ClaimErrorLoggingToken as ClaimErrorLoggingTokenResponse;
use Comfino\Api\Response\CreateOrder as CreateOrderResponse;
use Comfino\Api\Response\CustomResponse;
use Comfino\Api\Response\GetCreditors as GetCreditorsResponse;
use Comfino\Api\Response\GetFinancialProductDetails as GetFinancialProductDetailsResponse;
use Comfino\Api\Response\GetFinancialProducts as GetFinancialProductsResponse;
use Comfino\Api\Response\GetLatestPluginRelease as GetLatestPluginReleaseResponse;
use Comfino\Api\Response\GetProductTypes as GetProductTypesResponse;
use Comfino\Api\Response\GetSupportedPlatforms as GetSupportedPlatformsResponse;
use Comfino\Api\Response\GetUserSettings as GetUserSettingsResponse;
use Comfino\Api\Response\GetWidgetTypes as GetWidgetTypesResponse;
use Comfino\Api\Response\ValidateOrder as ValidateOrderResponse;
use Comfino\Enum\ProductListType;
use Comfino\Shop\Order\CartInterface;
use Comfino\Shop\Order\OrderInterface;

/**
 * One tenant's view of a {@see SharedClient}.
 *
 * The bridge that lets the multi-tenant client ship without breaking the four existing plugins: it implements
 * {@see ClientInterface} exactly as before, holds the tenant's {@see ApiContext} for them, and forwards every call.
 * A plugin keeps writing
 *
 *     $client->setApiKey($key);
 *     $client->enableSandboxMode();
 *     $products = $client->getFinancialProducts($criteria);
 *
 * while the credential is no longer stored on anything shared - each setter replaces this instance's context, and the
 * client underneath stays stateless. Two instances bound to different contexts can therefore share one transport,
 * one retry executor and one connection pool without any chance of one tenant's key reaching the other's request.
 *
 * Cheap enough to create per tenant per request: it holds two references and allocates nothing else.
 */
final class BoundClient implements ClientInterface
{
    /**
     * @param SharedClient $client Stateless client doing the actual work
     * @param ApiContext $context Tenant context every call is made in
     * @param RequestOptions|null $defaultOptions Options applied to calls that pass none of their own
     */
    public function __construct(
        private readonly SharedClient $client,
        private ApiContext $context,
        private ?RequestOptions $defaultOptions = null
    ) {
    }

    /**
     * Returns the context this client is bound to.
     */
    public function getContext(): ApiContext
    {
        return $this->context;
    }

    /**
     * Returns the stateless client underneath, for callers ready to pass contexts explicitly.
     */
    public function getSharedClient(): SharedClient
    {
        return $this->client;
    }

    /**
     * Returns a second bound client over the same shared client, for another tenant.
     *
     * The safe way to serve a second tenant from the same transport: a new binding, never a mutated one.
     *
     * @param ApiContext $context Context the returned client sends every call in
     */
    public function withContext(ApiContext $context): self
    {
        return new self($this->client, $context, $this->defaultOptions);
    }

    /**
     * Sets the options applied to calls that pass none of their own.
     *
     * @param RequestOptions|null $options Default per-call options, or null to clear them
     */
    public function setDefaultOptions(?RequestOptions $options): void
    {
        $this->defaultOptions = $options;
    }

    // -------------------------------------------------------------------------
    // Legacy mutators - each replaces this binding's context, mutating nothing shared
    // -------------------------------------------------------------------------

    /**
     * Sets the API key used for request authentication.
     *
     * @param string $apiKey API key
     */
    public function setApiKey(string $apiKey): void
    {
        $this->context = $this->context->withApiKey($apiKey);
    }

    /**
     * Returns the API key used for request authentication.
     */
    public function getApiKey(): string
    {
        return $this->context->apiKey;
    }

    /**
     * Sets the API language code (ISO 639-1).
     *
     * @param string $language Language code
     */
    public function setApiLanguage(string $language): void
    {
        $this->context = $this->context->withApiLanguage($language);
    }

    /**
     * Returns the API language code (ISO 639-1).
     */
    public function getApiLanguage(): string
    {
        return $this->context->apiLanguage;
    }

    /**
     * Sets the API currency code (ISO 4217).
     *
     * @param string $apiCurrency Currency code
     */
    public function setApiCurrency(string $apiCurrency): void
    {
        $this->context = $this->context->withApiCurrency($apiCurrency);
    }

    /**
     * Returns the API currency code (ISO 4217).
     */
    public function getApiCurrency(): string
    {
        return $this->context->apiCurrency;
    }

    /**
     * Returns the effective API base URL.
     */
    public function getApiBaseUrl(): string
    {
        return $this->context->getApiBaseUrl();
    }

    /**
     * Sets a custom API base URL or clears the override.
     *
     * @param string|null $baseUrl Custom base URL, or null to clear
     */
    public function setCustomApiBaseUrl(?string $baseUrl): void
    {
        $this->context = $this->context->withApiBaseUrl($baseUrl);
    }

    /**
     * Sets the User-Agent override.
     *
     * @param string|null $userAgent User-Agent string, or null to let the client build one
     */
    public function setCustomUserAgent(?string $userAgent): void
    {
        $this->context = $this->context->withUserAgent($userAgent);
    }

    /**
     * Adds or replaces a custom HTTP header.
     *
     * @param string $headerName Header name
     * @param string $headerValue Header value
     */
    public function addCustomHeader(string $headerName, string $headerValue): void
    {
        $this->context = $this->context->withCustomHeader($headerName, $headerValue);
    }

    /**
     * Removes a custom HTTP header previously added.
     *
     * @param string $headerName Header name
     */
    public function removeCustomHeader(string $headerName): void
    {
        $this->context = $this->context->withoutCustomHeader($headerName);
    }

    /**
     * Removes every custom HTTP header.
     */
    public function clearCustomHeaders(): void
    {
        $this->context = $this->context->withoutCustomHeaders();
    }

    /**
     * Enables API sandbox mode.
     */
    public function enableSandboxMode(): void
    {
        $this->context = $this->context->withSandboxMode(true);
    }

    /**
     * Disables API sandbox mode.
     */
    public function disableSandboxMode(): void
    {
        $this->context = $this->context->withSandboxMode(false);
    }

    /** @inheritDoc */
    public function getVersion(): string
    {
        return $this->client->getVersion();
    }

    /** @inheritDoc */
    public function getTrackId(): string
    {
        $this->context = $this->context->withGeneratedTrackId();

        return (string) $this->context->trackId;
    }

    /** @inheritDoc */
    public function setTrackId(?string $trackId): void
    {
        if ($this->context->trackId !== null || $trackId === null) {
            return;
        }

        if (preg_match(AbstractClient::TRACK_ID_PATTERN, $trackId) !== 1) {
            return;
        }

        $this->context = $this->context->withTrackId($trackId);
    }

    // -------------------------------------------------------------------------
    // API calls
    // -------------------------------------------------------------------------

    /** @inheritDoc */
    public function isShopAccountActive(?string $cacheInvalidateUrl = null, ?string $configurationUrl = null): bool
    {
        return $this->client->isShopAccountActive(
            $this->context,
            $cacheInvalidateUrl,
            $configurationUrl,
            $this->defaultOptions
        );
    }

    /** @inheritDoc */
    public function getFinancialProductDetails(
        LoanQueryCriteria $queryCriteria,
        CartInterface $cart
    ): GetFinancialProductDetailsResponse {
        return $this->client->getFinancialProductDetails($this->context, $queryCriteria, $cart, $this->defaultOptions);
    }

    /** @inheritDoc */
    public function getFinancialProducts(LoanQueryCriteria $queryCriteria): GetFinancialProductsResponse
    {
        return $this->client->getFinancialProducts($this->context, $queryCriteria, $this->defaultOptions);
    }

    /** @inheritDoc */
    public function createOrder(OrderInterface $order): CreateOrderResponse
    {
        return $this->client->createOrder($this->context, $order, $this->defaultOptions);
    }

    /** @inheritDoc */
    public function validateOrder(OrderInterface $order): ValidateOrderResponse
    {
        return $this->client->validateOrder($this->context, $order, $this->defaultOptions);
    }

    /** @inheritDoc */
    public function cancelOrder(string $orderId): void
    {
        $this->client->cancelOrder($this->context, $orderId, $this->defaultOptions);
    }

    /** @inheritDoc */
    public function getProductTypes(ProductListType $listType): GetProductTypesResponse
    {
        return $this->client->getProductTypes($this->context, $listType, $this->defaultOptions);
    }

    /** @inheritDoc */
    public function getUserSettings(): GetUserSettingsResponse
    {
        return $this->client->getUserSettings($this->context, $this->defaultOptions);
    }

    /** @inheritDoc */
    public function getCreditors(): GetCreditorsResponse
    {
        return $this->client->getCreditors($this->context, $this->defaultOptions);
    }

    /** @inheritDoc */
    public function getWidgetKey(): string
    {
        return $this->client->getWidgetKey($this->context, $this->defaultOptions);
    }

    /** @inheritDoc */
    public function getWidgetTypes(): GetWidgetTypesResponse
    {
        return $this->client->getWidgetTypes($this->context, $this->defaultOptions);
    }

    /** @inheritDoc */
    public function claimErrorLoggingToken(): ClaimErrorLoggingTokenResponse
    {
        return $this->client->claimErrorLoggingToken($this->context, $this->defaultOptions);
    }

    /** @inheritDoc */
    public function getSupportedPlatforms(): GetSupportedPlatformsResponse
    {
        return $this->client->getSupportedPlatforms($this->context, $this->defaultOptions);
    }

    /** @inheritDoc */
    public function getLatestPluginRelease(string $platform): GetLatestPluginReleaseResponse
    {
        return $this->client->getLatestPluginRelease($this->context, $platform, $this->defaultOptions);
    }

    /** @inheritDoc */
    public function sendLoggedError(ShopPluginError $shopPluginError): void
    {
        $this->client->sendLoggedError($this->context, $shopPluginError, $this->defaultOptions);
    }

    /** @inheritDoc */
    public function notifyPluginRemoval(): bool
    {
        return $this->client->notifyPluginRemoval($this->context, $this->defaultOptions);
    }

    /** @inheritDoc */
    public function notifyAbandonedCart(string $type): bool
    {
        return $this->client->notifyAbandonedCart($this->context, $type, $this->defaultOptions);
    }

    /** @inheritDoc */
    public function reportShopEnvironment(ShopEnvironmentReport $report): bool
    {
        return $this->client->reportShopEnvironment($this->context, $report, $this->defaultOptions);
    }

    /** @inheritDoc */
    public function sendCustomRequest(
        Request $request,
        string $responseClass = CustomResponse::class,
        ?int $apiVersion = null
    ): Response {
        return $this->client->sendCustomRequest(
            $this->context,
            $request,
            $responseClass,
            ($this->defaultOptions ?? new RequestOptions())->andApiVersion($apiVersion)
        );
    }
}
