<?php

/**
 * ComfinoPay PHP API client
 *
 * Backend routines for communication with the ComfinoPay payment gateway REST API.
 *
 * @package Comfino\Api
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-api-client
 */

declare(strict_types=1);

namespace Comfino\Api;

use Comfino\Api\Dto\Account\UserRegistration;
use Comfino\Api\Dto\Payment\LoanQueryCriteria;
use Comfino\Api\Dto\Plugin\ShopEnvironmentReport;
use Comfino\Api\Dto\Plugin\ShopPluginError;
use Comfino\Api\Exception\ConnectionTimeout;
use Comfino\Api\Response\ClaimErrorLoggingToken as ClaimErrorLoggingTokenResponse;
use Comfino\Api\Response\CreateOrder as CreateOrderResponse;
use Comfino\Api\Response\CreateUser as CreateUserResponse;
use Comfino\Api\Response\CustomResponse;
use Comfino\Api\Response\FetchAgreements as FetchAgreementsResponse;
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
use Psr\Http\Client\ClientExceptionInterface;
use Throwable;

/**
 * Contract of the stateless, multi-tenant ComfinoPay API client.
 *
 * Every method takes the tenant's {@see ApiContext} first and optional per-call {@see RequestOptions} last. That shape
 * is the whole difference from {@see ClientInterface}: with no credential on the object, one instance can be a
 * container service shared by every tenant, and an attempt count or a timeout can belong to a call site instead of to
 * a client rebuilt for the purpose.
 */
interface SharedClientInterface
{
    /**
     * Returns the client library version.
     */
    public function getVersion(): string;

    /**
     * Pairs this client with one tenant context and returns the legacy, mutable-looking surface.
     *
     * @param ApiContext $context Context the returned client sends every call in
     */
    public function bind(ApiContext $context): BoundClient;

    /**
     * Returns the serializer used for requests and responses.
     */
    public function getSerializer(): SerializerInterface;

    /**
     * Checks if the shop account is active.
     *
     * @param ApiContext $context Tenant context
     * @param string|null $cacheInvalidateUrl URL to invalidate the plugin cache at shop side (optional)
     * @param string|null $configurationUrl URL to retrieve plugin configuration details at shop side (optional)
     * @param RequestOptions|null $options Per-call options
     *
     * @throws ConnectionTimeout On network timeout
     * @throws HttpErrorExceptionInterface On HTTP 4xx/5xx responses
     * @throws ClientExceptionInterface On PSR-18 transport errors
     * @throws Throwable On any other transport-level failure not covered above
     */
    public function isShopAccountActive(
        ApiContext $context,
        ?string $cacheInvalidateUrl = null,
        ?string $configurationUrl = null,
        ?RequestOptions $options = null
    ): bool;

    /**
     * Retrieves detailed information about a specific financial product.
     *
     * @param ApiContext $context Tenant context
     * @param LoanQueryCriteria $queryCriteria The criteria for filtering financial products
     * @param CartInterface $cart The shopping cart containing product details
     * @param RequestOptions|null $options Per-call options
     *
     * @throws ConnectionTimeout On network timeout
     * @throws HttpErrorExceptionInterface On HTTP 4xx/5xx responses
     * @throws ClientExceptionInterface On PSR-18 transport errors
     * @throws Throwable On any other transport-level failure not covered above
     */
    public function getFinancialProductDetails(
        ApiContext $context,
        LoanQueryCriteria $queryCriteria,
        CartInterface $cart,
        ?RequestOptions $options = null
    ): GetFinancialProductDetailsResponse;

    /**
     * Retrieves a list of available financial products filtered by the given query criteria.
     *
     * @param ApiContext $context Tenant context
     * @param LoanQueryCriteria $queryCriteria The criteria for filtering financial products
     * @param RequestOptions|null $options Per-call options
     *
     * @throws ConnectionTimeout On network timeout
     * @throws HttpErrorExceptionInterface On HTTP 4xx/5xx responses
     * @throws ClientExceptionInterface On PSR-18 transport errors
     * @throws Throwable On any other transport-level failure not covered above
     */
    public function getFinancialProducts(
        ApiContext $context,
        LoanQueryCriteria $queryCriteria,
        ?RequestOptions $options = null
    ): GetFinancialProductsResponse;

    /**
     * Creates a new order (loan application).
     *
     * Safe to retry: the API deduplicates a replay by `orderId` plus the hash of the request body, answering with the
     * **existing** order at `201 Created` rather than creating a second loan application. See
     * {@see \Comfino\Api\Request\CreateOrder::isIdempotent()} for what that requires of the caller.
     *
     * @param ApiContext $context Tenant context
     * @param OrderInterface $order The order details to create
     * @param RequestOptions|null $options Per-call options
     *
     * @throws ConnectionTimeout On network timeout
     * @throws HttpErrorExceptionInterface On HTTP 4xx/5xx responses
     * @throws ClientExceptionInterface On PSR-18 transport errors
     * @throws Throwable On any other transport-level failure not covered above
     */
    public function createOrder(
        ApiContext $context,
        OrderInterface $order,
        ?RequestOptions $options = null
    ): CreateOrderResponse;

    /**
     * Validates the provided order without creating it.
     *
     * @param ApiContext $context Tenant context
     * @param OrderInterface $order The order details to validate
     * @param RequestOptions|null $options Per-call options
     */
    public function validateOrder(
        ApiContext $context,
        OrderInterface $order,
        ?RequestOptions $options = null
    ): ValidateOrderResponse;

    /**
     * Cancels the order identified by the external ID sent in the order creation request.
     *
     * @param ApiContext $context Tenant context
     * @param string $orderId The ID of the order to cancel
     * @param RequestOptions|null $options Per-call options
     *
     * @throws ConnectionTimeout On network timeout
     * @throws HttpErrorExceptionInterface On HTTP 4xx/5xx responses
     * @throws ClientExceptionInterface On PSR-18 transport errors
     * @throws Throwable On any other transport-level failure not covered above
     */
    public function cancelOrder(ApiContext $context, string $orderId, ?RequestOptions $options = null): void;

    /**
     * Retrieves available financial product types together with their internal and public display names.
     *
     * @param ApiContext $context Tenant context
     * @param ProductListType $listType The type of product list to retrieve
     * @param RequestOptions|null $options Per-call options
     *
     * @throws ConnectionTimeout On network timeout
     * @throws HttpErrorExceptionInterface On HTTP 4xx/5xx responses
     * @throws ClientExceptionInterface On PSR-18 transport errors
     * @throws Throwable On any other transport-level failure not covered above
     */
    public function getProductTypes(
        ApiContext $context,
        ProductListType $listType,
        ?RequestOptions $options = null
    ): GetProductTypesResponse;

    /**
     * Returns the complete shop user settings for an authorized shop account.
     *
     * @param ApiContext $context Tenant context
     * @param RequestOptions|null $options Per-call options
     *
     * @throws ConnectionTimeout On network timeout
     * @throws HttpErrorExceptionInterface On HTTP 4xx/5xx responses
     * @throws ClientExceptionInterface On PSR-18 transport errors
     * @throws Throwable On any other transport-level failure not covered above
     */
    public function getUserSettings(ApiContext $context, ?RequestOptions $options = null): GetUserSettingsResponse;

    /**
     * Returns a map of available creditors grouped by product type.
     *
     * @param ApiContext $context Tenant context
     * @param RequestOptions|null $options Per-call options
     *
     * @throws ConnectionTimeout On network timeout
     * @throws HttpErrorExceptionInterface On HTTP 4xx/5xx responses
     * @throws ClientExceptionInterface On PSR-18 transport errors
     * @throws Throwable On any other transport-level failure not covered above
     */
    public function getCreditors(ApiContext $context, ?RequestOptions $options = null): GetCreditorsResponse;

    /**
     * Retrieves the key for the shop promotional banner widget.
     *
     * @param ApiContext $context Tenant context
     * @param RequestOptions|null $options Per-call options
     *
     * @throws ConnectionTimeout On network timeout
     * @throws HttpErrorExceptionInterface On HTTP 4xx/5xx responses
     * @throws ClientExceptionInterface On PSR-18 transport errors
     * @throws Throwable On any other transport-level failure not covered above
     */
    public function getWidgetKey(ApiContext $context, ?RequestOptions $options = null): string;

    /**
     * Retrieves a list of available widget types for integration.
     *
     * @param ApiContext $context Tenant context
     * @param RequestOptions|null $options Per-call options
     *
     * @throws ConnectionTimeout On network timeout
     * @throws HttpErrorExceptionInterface On HTTP 4xx/5xx responses
     * @throws ClientExceptionInterface On PSR-18 transport errors
     * @throws Throwable On any other transport-level failure not covered above
     */
    public function getWidgetTypes(ApiContext $context, ?RequestOptions $options = null): GetWidgetTypesResponse;

    /**
     * Claims a short-lived plugin access token for signing error-logging requests.
     *
     * @param ApiContext $context Tenant context
     * @param RequestOptions|null $options Per-call options
     *
     * @throws ConnectionTimeout On network timeout
     * @throws HttpErrorExceptionInterface On HTTP 4xx/5xx responses
     * @throws ClientExceptionInterface On PSR-18 transport errors
     * @throws Throwable On any other transport-level failure not covered above
     */
    public function claimErrorLoggingToken(
        ApiContext $context,
        ?RequestOptions $options = null
    ): ClaimErrorLoggingTokenResponse;

    /**
     * Retrieves the list of shop plugin platforms supported by the centralized release-notice API.
     *
     * @param ApiContext $context Tenant context
     * @param RequestOptions|null $options Per-call options
     *
     * @throws ConnectionTimeout On network timeout
     * @throws HttpErrorExceptionInterface On HTTP 4xx/5xx responses
     * @throws ClientExceptionInterface On PSR-18 transport errors
     * @throws Throwable On any other transport-level failure not covered above
     */
    public function getSupportedPlatforms(
        ApiContext $context,
        ?RequestOptions $options = null
    ): GetSupportedPlatformsResponse;

    /**
     * Retrieves the latest published plugin release notice for the given platform.
     *
     * @param ApiContext $context Tenant context
     * @param string $platform Canonical platform slug (e.g. "prestashop", "woocommerce", "magento")
     * @param RequestOptions|null $options Per-call options
     *
     * @throws ConnectionTimeout On network timeout
     * @throws HttpErrorExceptionInterface On HTTP 4xx/5xx responses
     * @throws ClientExceptionInterface On PSR-18 transport errors
     * @throws Throwable On any other transport-level failure not covered above
     */
    public function getLatestPluginRelease(
        ApiContext $context,
        string $platform,
        ?RequestOptions $options = null
    ): GetLatestPluginReleaseResponse;

    /**
     * Sends a logged payment plugin error to the API.
     *
     * Throws on any delivery failure so the caller can classify whether to retry, drop, or fall back.
     *
     * @param ApiContext $context Tenant context
     * @param ShopPluginError $shopPluginError The error object to send
     * @param RequestOptions|null $options Per-call options
     *
     * @throws ConnectionTimeout On network timeout
     * @throws HttpErrorExceptionInterface On HTTP 4xx/5xx responses
     * @throws ClientExceptionInterface On PSR-18 transport errors
     * @throws Throwable On any other transport-level failure not covered above
     */
    public function sendLoggedError(
        ApiContext $context,
        ShopPluginError $shopPluginError,
        ?RequestOptions $options = null
    ): void;

    /**
     * Notifies the API that a shop payment plugin has been removed.
     *
     * @param ApiContext $context Tenant context
     * @param RequestOptions|null $options Per-call options
     *
     * @return bool True if the notification was successfully sent
     */
    public function notifyPluginRemoval(ApiContext $context, ?RequestOptions $options = null): bool;

    /**
     * Notifies the API that a shop abandoned cart has been detected.
     *
     * @param ApiContext $context Tenant context
     * @param string $type Type of abandoned cart event
     * @param RequestOptions|null $options Per-call options
     *
     * @return bool True if the notification was successfully sent
     */
    public function notifyAbandonedCart(ApiContext $context, string $type, ?RequestOptions $options = null): bool;

    /**
     * Reports the structured shop environment to the API server-to-server.
     *
     * @param ApiContext $context Tenant context
     * @param ShopEnvironmentReport $report Structured environment report
     * @param RequestOptions|null $options Per-call options
     *
     * @return bool True if the report was accepted
     */
    public function reportShopEnvironment(
        ApiContext $context,
        ShopEnvironmentReport $report,
        ?RequestOptions $options = null
    ): bool;

    /**
     * Registers a new shop owner account with ComfinoPay.
     *
     * The one call in this interface made with no credential: pass an {@see ApiContext} whose `apiKey` is an empty
     * string, since the account does not exist yet. A shop already registered under
     * {@see UserRegistration::$webSiteUrl} is rejected with HTTP 409 - see {@see \Comfino\Api\Exception\Conflict}.
     *
     * @param ApiContext $context Tenant context; `apiKey` must be `''` for this call
     * @param UserRegistration $registration Shop and contact details, plus accepted agreement IDs from
     *                                       {@see fetchAgreements()}
     * @param RequestOptions|null $options Per-call options
     *
     * @throws ConnectionTimeout On network timeout
     * @throws HttpErrorExceptionInterface On HTTP 4xx/5xx responses
     * @throws ClientExceptionInterface On PSR-18 transport errors
     * @throws Throwable On any other transport-level failure not covered above
     */
    public function createUser(
        ApiContext $context,
        UserRegistration $registration,
        ?RequestOptions $options = null
    ): CreateUserResponse;

    /**
     * Retrieves the legal agreements a shop owner must see - and, where required, accept - before registering via
     * {@see createUser()}.
     *
     * Read-only and, like {@see createUser()}, meant to be called with an empty `apiKey` before an account exists.
     *
     * @param ApiContext $context Tenant context; `apiKey` may be `''` before registration
     * @param RequestOptions|null $options Per-call options
     *
     * @throws ConnectionTimeout On network timeout
     * @throws HttpErrorExceptionInterface On HTTP 4xx/5xx responses
     * @throws ClientExceptionInterface On PSR-18 transport errors
     * @throws Throwable On any other transport-level failure not covered above
     */
    public function fetchAgreements(ApiContext $context, ?RequestOptions $options = null): FetchAgreementsResponse;

    /**
     * Sends a custom API request not covered by a dedicated client method.
     *
     * @template TResponse of Response
     *
     * @param ApiContext $context Tenant context
     * @param Request $request Custom request object
     * @param class-string<TResponse> $responseClass Response class to instantiate
     * @param RequestOptions|null $options Per-call options
     *
     * @return TResponse
     *
     * @throws ConnectionTimeout On network timeout
     * @throws HttpErrorExceptionInterface On HTTP 4xx/5xx responses
     * @throws ClientExceptionInterface On PSR-18 transport errors
     * @throws Throwable On any other transport-level failure not covered above
     */
    public function sendCustomRequest(
        ApiContext $context,
        Request $request,
        string $responseClass = CustomResponse::class,
        ?RequestOptions $options = null
    ): Response;
}
