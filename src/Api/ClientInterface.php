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
use Comfino\Api\Exception\AccessDenied;
use Comfino\Api\Exception\AuthorizationError;
use Comfino\Api\Exception\ConnectionTimeout;
use Comfino\Api\Exception\RequestValidationError;
use Comfino\Api\Exception\ResponseValidationError;
use Comfino\Api\Exception\ServiceUnavailable;
use Comfino\Api\Response\ClaimErrorLoggingToken as ClaimErrorLoggingTokenResponse;
use Comfino\Api\Response\CreateOrder as CreateOrderResponse;
use Comfino\Api\Response\CustomResponse;
use Comfino\Api\Response\GetFinancialProductDetails as GetFinancialProductDetailsResponse;
use Comfino\Api\Response\GetFinancialProducts as GetFinancialProductsResponse;
use Comfino\Api\Response\GetLatestPluginRelease as GetLatestPluginReleaseResponse;
use Comfino\Api\Response\GetProductTypes as GetProductTypesResponse;
use Comfino\Api\Response\GetSupportedPlatforms as GetSupportedPlatformsResponse;
use Comfino\Api\Response\GetWidgetTypes as GetWidgetTypesResponse;
use Comfino\Api\Response\ValidateOrder as ValidateOrderResponse;
use Comfino\Enum\ProductListType;
use Comfino\Shop\Order\CartInterface;
use Comfino\Shop\Order\OrderInterface;
use Psr\Http\Client\ClientExceptionInterface;

/**
 * Comfino API client interface.
 */
interface ClientInterface
{
    /**
     * Returns the track ID shared across all API calls made by this client instance.
     *
     * The ID is generated on the first API call and reused for every subsequent one, making it a stable correlation key
     * for the entire PHP request lifecycle. Shop plugins should expose this value to the frontend (e.g., via a template
     * variable or meta tag) so that browser-side errors logged by the Comfino widget carry the same ID and can be
     * matched against backend errors in Sentry or other observability tools.
     *
     * @return string The track ID
     */
    public function getTrackId(): string;

    /**
     * Checks if the shop account is active.
     *
     * @param ?string $cacheInvalidateUrl URL to invalidate the plugin cache at shop side (optional)
     * @param ?string $configurationUrl URL to retrieve plugin configuration details at shop side (optional)
     *
     * @return bool True if the shop account is active, false otherwise
     */
    public function isShopAccountActive(?string $cacheInvalidateUrl = null, ?string $configurationUrl = null): bool;

    /**
     * Retrieves detailed information about a specific financial product based on the provided query criteria and
     * shopping cart.
     *
     * @param LoanQueryCriteria $queryCriteria The criteria for filtering financial products
     * @param CartInterface $cart The shopping cart containing product details
     *
     * @return GetFinancialProductDetailsResponse The response containing detailed product information
     */
    public function getFinancialProductDetails(
        LoanQueryCriteria $queryCriteria,
        CartInterface $cart
    ): GetFinancialProductDetailsResponse;

    /**
     * Retrieves a list of available financial products filtered by the given query criteria.
     *
     * @param LoanQueryCriteria $queryCriteria The criteria for filtering financial products
     *
     * @return GetFinancialProductsResponse The response containing the list of financial products
     */
    public function getFinancialProducts(LoanQueryCriteria $queryCriteria): GetFinancialProductsResponse;

    /**
     * Creates a new order (loan application) with the provided details.
     *
     * @param OrderInterface $order The order details to create
     *
     * @return CreateOrderResponse The response containing the created order details (201 Created response if the order
     *                             is created successfully, 400 Bad Request response if the order is invalid)
     */
    public function createOrder(OrderInterface $order): CreateOrderResponse;

    /**
     * Validates the provided order (loan application) based on the given criteria and the cart content.
     *
     * @param OrderInterface $order The order details to validate
     *
     * @return ValidateOrderResponse The response containing validation results (200 OK response if the order is valid,
     *                               400 Bad Request response if the order is invalid)
     */
    public function validateOrder(OrderInterface $order): ValidateOrderResponse;

    /**
     * Cancels the order based on the given order ID (external ID sent in the order creation request).
     *
     * @param string $orderId The ID of the order to cancel
     *
     * @return void
     */
    public function cancelOrder(string $orderId): void;

    /**
     * Retrieves a list of available financial product types for integration (depends on the shop contract).
     *
     * @param ProductListType $listType The type of product list to retrieve
     *
     * @return GetProductTypesResponse The response containing available product types (depends on the shop contract)
     */
    public function getProductTypes(ProductListType $listType): GetProductTypesResponse;

    /**
     * Retrieves the key for the shop promotional banner widget.
     *
     * @return string Unique widget key for the shop
     */
    public function getWidgetKey(): string;

    /**
     * Retrieves a list of available widget types for integration.
     *
     * @return GetWidgetTypesResponse The response containing list of available widget types
     */
    public function getWidgetTypes(): GetWidgetTypesResponse;

    /**
     * Sends a logged payment plugin error to the API.
     *
     * Throws on any delivery failure (network, timeout, HTTP error) so the caller can decide whether to retry, drop, or
     * fall back. Fire-and-forget callers should wrap in try/catch.
     *
     * @param ShopPluginError $shopPluginError The error object to send
     *
     * @throws ConnectionTimeout On network timeout
     * @throws HttpErrorExceptionInterface On HTTP 4xx/5xx responses
     * @throws ClientExceptionInterface On PSR-18 transport errors
     */
    public function sendLoggedError(ShopPluginError $shopPluginError): void;

    /**
     * Notifies the API that a shop payment plugin has been removed.
     *
     * @return bool True if the removal notification was successfully sent, false otherwise
     */
    public function notifyPluginRemoval(): bool;

    /**
     * Notifies the API that a shop abandoned cart has been detected.
     *
     * @param string $type Type of abandoned cart event
     *
     * @return bool True if the notification was successful, false otherwise
     */
    public function notifyAbandonedCart(string $type): bool;

    /**
     * Reports structured shop environment to the API server-to-server.
     *
     * Carries the full set of platform / plugin / theme facts (including fingerprinting-grade fields like exact
     * versions, edition, raw theme code) that the API uses to build a per-theme selector knowledge base, return
     * auto-detection recommendations, and track installed-plugin compatibility. The complementary browser-safe
     * ShopEnvironment subset is exposed to the SDK at runtime; sensitive fields belong here only.
     *
     * Fire-and-forget like {@see sendLoggedError()} / {@see notifyPluginRemoval()}: failure MUST NOT impact
     * paywall / widget functionality.
     *
     * @param ShopEnvironmentReport $report Structured environment report
     *
     * @return bool True if the report was successfully accepted, false otherwise
     */
    public function reportShopEnvironment(ShopEnvironmentReport $report): bool;

    /**
     * Returns the client library version.
     *
     * @return string Semantic version of the API client
     */
    public function getVersion(): string;

    /**
     * Pins an externally provided track ID for this client instance.
     *
     * Ignored when a track ID is already set, when $trackId is null, or when it does not match the expected format —
     * this keeps a valid, already-established correlation key stable for the whole request lifecycle.
     *
     * @param string|null $trackId Track ID to pin
     */
    public function setTrackId(?string $trackId): void;

    /**
     * Returns the list of e-commerce platforms supported by the Comfino integration.
     *
     * @return GetSupportedPlatformsResponse
     *
     * @throws ClientExceptionInterface
     */
    public function getSupportedPlatforms(): GetSupportedPlatformsResponse;

    /**
     * Returns metadata about the latest published plugin release for the given platform.
     *
     * @param string $platform Canonical platform slug (e.g. 'magento', 'prestashop', 'woocommerce')
     *
     * @return GetLatestPluginReleaseResponse
     *
     * @throws ClientExceptionInterface
     */
    public function getLatestPluginRelease(string $platform): GetLatestPluginReleaseResponse;

    /**
     * Exchanges the API key for a short-lived token that authorizes browser-side error logging.
     *
     * @return ClaimErrorLoggingTokenResponse
     *
     * @throws ClientExceptionInterface
     */
    public function claimErrorLoggingToken(): ClaimErrorLoggingTokenResponse;

    /**
     * Sends a custom API request not covered by a dedicated client method.
     *
     * Use this to reach a new or undocumented endpoint (or one this library hasn't caught up with yet) without waiting
     * for a dedicated Request/Response pair to be added here. Reuses the same authentication, track ID, and
     * error-mapping infrastructure as the built-in methods above.
     *
     * @template TResponse of Response
     *
     * @param Request $request Custom request object - extend {@see Request} for a typed request, or use
     *                         {@see \Comfino\Api\Request\CustomRequest} for free-form JSON
     * @param class-string<TResponse> $responseClass Response class to instantiate - extend {@see Response} for typed
     *                                               parsing, or use {@see CustomResponse} (default) for free-form JSON
     *
     * @param int|null $apiVersion API version to target (defaults to the client's configured version)
     *
     * @return TResponse
     *
     * @throws RequestValidationError
     * @throws ResponseValidationError
     * @throws AuthorizationError
     * @throws AccessDenied
     * @throws ServiceUnavailable
     * @throws ClientExceptionInterface
     */
    public function sendCustomRequest(
        Request $request,
        string $responseClass = CustomResponse::class,
        ?int $apiVersion = null
    ): Response;
}
