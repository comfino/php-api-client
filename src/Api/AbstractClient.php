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
use Comfino\Api\Exception\AccessDenied;
use Comfino\Api\Exception\AuthorizationError;
use Comfino\Api\Exception\RequestValidationError;
use Comfino\Api\Exception\ResponseValidationError;
use Comfino\Api\Exception\ServiceUnavailable;
use Comfino\Api\Request\CancelOrder as CancelOrderRequest;
use Comfino\Api\Request\CreateOrder as CreateOrderRequest;
use Comfino\Api\Request\GetFinancialProductDetails as GetFinancialProductDetailsRequest;
use Comfino\Api\Request\GetFinancialProducts as GetFinancialProductsRequest;
use Comfino\Api\Request\GetOrder as GetOrderRequest;
use Comfino\Api\Request\GetCreditors as GetCreditorsRequest;
use Comfino\Api\Request\GetProductTypes as GetProductTypesRequest;
use Comfino\Api\Request\GetLatestPluginRelease as GetLatestPluginReleaseRequest;
use Comfino\Api\Request\GetSupportedPlatforms as GetSupportedPlatformsRequest;
use Comfino\Api\Request\ClaimErrorLoggingToken as ClaimErrorLoggingTokenRequest;
use Comfino\Api\Request\GetWidgetKey as GetWidgetKeyRequest;
use Comfino\Api\Request\GetWidgetTypes as GetWidgetTypesRequest;
use Comfino\Api\Request\IsShopAccountActive as IsShopAccountActiveRequest;
use Comfino\Api\Response\Base as BaseApiResponse;
use Comfino\Api\Response\ClaimErrorLoggingToken as ClaimErrorLoggingTokenResponse;
use Comfino\Api\Response\CreateOrder as CreateOrderResponse;
use Comfino\Api\Response\GetFinancialProductDetails as GetFinancialProductDetailsResponse;
use Comfino\Api\Response\GetFinancialProducts as GetFinancialProductsResponse;
use Comfino\Api\Response\GetOrder as GetOrderResponse;
use Comfino\Api\Response\GetCreditors as GetCreditorsResponse;
use Comfino\Api\Response\GetProductTypes as GetProductTypesResponse;
use Comfino\Api\Response\GetLatestPluginRelease as GetLatestPluginReleaseResponse;
use Comfino\Api\Response\GetSupportedPlatforms as GetSupportedPlatformsResponse;
use Comfino\Api\Response\GetWidgetKey as GetWidgetKeyResponse;
use Comfino\Api\Response\GetWidgetTypes as GetWidgetTypesResponse;
use Comfino\Api\Response\IsShopAccountActive as IsShopAccountActiveResponse;
use Comfino\Api\Response\ValidateOrder as ValidateOrderResponse;
use Comfino\Api\Serializer\Json as JsonSerializer;
use Comfino\Api\Validation\UrlValidator;
use Comfino\Enum\ProductListType;
use Comfino\Shop\Order\CartInterface;
use Comfino\Shop\Order\OrderInterface;
use InvalidArgumentException;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface as HttpClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Throwable;

/**
 * Abstract base class for Comfino API clients.
 */
abstract class AbstractClient implements ClientInterface
{
    public const PRODUCTION_API_BASE_URL = 'https://api-ecommerce.comfino.pl';
    public const SANDBOX_API_BASE_URL = 'https://api-ecommerce.craty.pl';

    /**
     * Allowlist pattern for a track ID accepted from untrusted input (e.g., a client-writable cookie). Mirrors the
     * pattern enforced server-side on cookie read and client-side in the web frontend SDK.
     */
    public const TRACK_ID_PATTERN = '/^[A-Za-z0-9_.:-]{1,128}$/';

    protected const CLIENT_VERSION = '';

    /** @var string[] */
    private const ALLOWED_API_DOMAINS = UrlValidator::ALLOWED_DOMAINS;

    protected string $apiLanguage = 'pl';
    protected string $apiCurrency = 'PLN';
    protected ?string $customApiBaseUrl = null;
    protected ?string $customUserAgent = null;
    /** @var array<string, string> */
    protected array $customHeaders = [];
    protected string $clientHostname = '';
    protected bool $isSandboxMode = false;
    protected ?Request $request = null;
    protected ?ResponseInterface $response = null;
    protected ?string $trackId = null;

    /**
     * Constructs a new instance of the AbstractClient.
     *
     * @param HttpClientInterface $httpClient The HTTP client to use for API requests (PSR-18 compatible)
     * @param RequestFactoryInterface $requestFactory The request factory to use for creating API requests
     *                                                (PSR-17 compatible)
     * @param StreamFactoryInterface $streamFactory The stream factory to use for creating request bodies
     *                                              (PSR-17 compatible)
     * @param ?string $apiKey The API key to use for authentication (optional)
     * @param int $apiVersion The API version to use (default: 1)
     * @param ?SerializerInterface $serializer The serializer to use for request and response data
     *                                         (optional, default: JsonSerializer)
     */
    public function __construct(
        protected HttpClientInterface $httpClient,
        protected readonly RequestFactoryInterface $requestFactory,
        protected readonly StreamFactoryInterface $streamFactory,
        protected ?string $apiKey,
        protected int $apiVersion = 1,
        protected ?SerializerInterface $serializer = null
    ) {
        $this->serializer ??= new JsonSerializer();
    }

    /**
     * Sets the HTTP client for the client.
     *
     * @param HttpClientInterface $client The HTTP client to use for API requests
     */
    public function setHttpClient(HttpClientInterface $client): void
    {
        $this->httpClient = $client;
    }

    /**
     * Sets the serializer for the client.
     *
     * @param SerializerInterface $serializer The serializer to use for API requests and responses
     */
    public function setSerializer(SerializerInterface $serializer): void
    {
        $this->serializer = $serializer;
    }

    /**
     * Sets the API version for the client.
     *
     * @param int $version The API version to use (default is 1)
     */
    public function setApiVersion(int $version): void
    {
        $this->apiVersion = $version;
    }

    /**
     * Gets the API key for the client.
     *
     * @return string The API key for requests authentication
     */
    public function getApiKey(): string
    {
        return $this->apiKey ?? '';
    }

    /**
     * Sets the API key for the client.
     *
     * @param string $apiKey The API key for requests authentication
     */
    public function setApiKey(string $apiKey): void
    {
        $this->apiKey = $apiKey;
    }

    /**
     * Gets the API language for the client.
     *
     * @return string The API language code (ISO 639-1, e.g., 'en')
     */
    public function getApiLanguage(): string
    {
        return $this->apiLanguage;
    }

    /**
     * Sets the API language for the client.
     *
     * @param string $language The API language code (ISO 639-1, e.g., 'en')
     */
    public function setApiLanguage(string $language): void
    {
        $this->apiLanguage = $language;
    }

    /**
     * Gets the API currency for the client.
     *
     * @return string The API currency code (ISO 4217, e.g., 'PLN')
     */
    public function getApiCurrency(): string
    {
        return $this->apiCurrency;
    }

    /**
     * Sets the API currency for the client.
     *
     * @param string $apiCurrency The API currency code (ISO 4217, e.g., 'PLN')
     */
    public function setApiCurrency(string $apiCurrency): void
    {
        $this->apiCurrency = $apiCurrency;
    }

    /**
     * Gets the API base URL for the client.
     *
     * @return string The API base URL
     */
    public function getApiBaseUrl(): string
    {
        return $this->customApiBaseUrl ??
            ($this->isSandboxMode ? self::SANDBOX_API_BASE_URL : self::PRODUCTION_API_BASE_URL);
    }

    /**
     * Sets the custom API base URL for the client.
     *
     * Accepted destinations:
     * - HTTPS URLs on allowed Comfino domains (comfino.pl, craty.pl, koszulawcraty.pl) and their subdomains.
     * - Any URL pointing to a private/reserved IP address (RFC 1918, loopback, link-local) — covers Docker containers.
     * - Single-label hostnames without dots (Docker service names such as "comfino-api", "localhost").
     *
     * @param string|null $baseUrl The custom API base URL, or null to clear the override
     *
     * @throws InvalidArgumentException When the URL does not resolve to an allowed destination
     */
    public function setCustomApiBaseUrl(?string $baseUrl): void
    {
        if ($baseUrl !== null && !UrlValidator::isAllowedUrl($baseUrl)) {
            throw new InvalidArgumentException(
                "API base URL '$baseUrl' is not allowed. Accepted: Comfino domains (" .
                implode(', ', self::ALLOWED_API_DOMAINS) .
                ") over HTTPS, private/loopback IP addresses, and single-label hostnames."
            );
        }

        $this->customApiBaseUrl = $baseUrl;
    }

    /**
     * Sets the custom user agent for the client.
     *
     * @param string|null $userAgent The custom user agent string
     */
    public function setCustomUserAgent(?string $userAgent): void
    {
        $this->customUserAgent = $userAgent;
    }

    /**
     * Adds a custom HTTP header to the client.
     *
     * @param string $headerName The name of the header
     * @param string $headerValue The value of the header
     */
    public function addCustomHeader(string $headerName, string $headerValue): void
    {
        if (!preg_match('/^[!#$%&\'*+\-.^_`|~0-9A-Za-z]+$/', $headerName)) {
            throw new InvalidArgumentException("Invalid HTTP header name: '$headerName'");
        }

        if (preg_match('/[\r\n]/', $headerValue)) {
            throw new InvalidArgumentException("Invalid HTTP header value: header injection attempt detected.");
        }

        $this->customHeaders[$headerName] = $headerValue;
    }

    /**
     * Sets the client hostname.
     *
     * @param string $host The hostname to set
     */
    public function setClientHostname(string $host): void
    {
        if (($filteredHost = filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) === false) {
            $filteredHost = gethostname();
        }

        $this->clientHostname = $filteredHost !== false ? $filteredHost : '';
    }

    /**
     * Enables API sandbox mode.
     */
    public function enableSandboxMode(): void
    {
        $this->isSandboxMode = true;
    }

    /**
     * Disables API sandbox mode.
     */
    public function disableSandboxMode(): void
    {
        $this->isSandboxMode = false;
    }

    /**
     * Returns the version of the Comfino API client.
     *
     * @return string The version of the client
     */
    public function getVersion(): string
    {
        return static::CLIENT_VERSION;
    }

    /**
     * Returns the current request object.
     *
     * @return Request|null The current request object or null if no request has been made
     */
    public function getRequest(): ?Request
    {
        return $this->request;
    }

    /**
     * Checks if the shop account is active.
     *
     * @param string|null $cacheInvalidateUrl URL to invalidate the plugin cache at shop side (optional)
     * @param string|null $configurationUrl URL to retrieve plugin configuration details at shop side (optional)
     *
     * @return bool True if the shop account is active, false otherwise
     *
     * @throws RequestValidationError
     * @throws ResponseValidationError
     * @throws AuthorizationError
     * @throws AccessDenied
     * @throws ServiceUnavailable
     * @throws ClientExceptionInterface
     */
    public function isShopAccountActive(?string $cacheInvalidateUrl = null, ?string $configurationUrl = null): bool
    {
        $this->request = (new IsShopAccountActiveRequest($cacheInvalidateUrl, $configurationUrl))
            ->setSerializer($this->serializer);

        return (new IsShopAccountActiveResponse($this->request, $this->sendRequest($this->request), $this->serializer))
            ->isActive;
    }

    /**
     * Retrieves detailed information about a specific financial product based on the provided query criteria and
     * shopping cart.
     *
     * @param LoanQueryCriteria $queryCriteria The criteria for filtering financial products
     * @param CartInterface $cart The shopping cart containing product details
     *
     * @return GetFinancialProductDetailsResponse The response containing detailed product information
     *
     * @throws RequestValidationError
     * @throws ResponseValidationError
     * @throws AuthorizationError
     * @throws AccessDenied
     * @throws ServiceUnavailable
     * @throws ClientExceptionInterface
     */
    public function getFinancialProductDetails(
        LoanQueryCriteria $queryCriteria,
        CartInterface $cart
    ): GetFinancialProductDetailsResponse {
        $this->request = (new GetFinancialProductDetailsRequest($queryCriteria, $cart))
            ->setSerializer($this->serializer);

        return new GetFinancialProductDetailsResponse(
            $this->request,
            $this->sendRequest($this->request),
            $this->serializer
        );
    }

    /**
     * Retrieves a list of available financial products filtered by the given query criteria.
     *
     * @param LoanQueryCriteria $queryCriteria The criteria for filtering financial products
     *
     * @return GetFinancialProductsResponse The response containing the list of financial products
     *
     * @throws RequestValidationError
     * @throws ResponseValidationError
     * @throws AuthorizationError
     * @throws AccessDenied
     * @throws ServiceUnavailable
     * @throws ClientExceptionInterface
     */
    public function getFinancialProducts(LoanQueryCriteria $queryCriteria): GetFinancialProductsResponse
    {
        $this->request = (new GetFinancialProductsRequest($queryCriteria))->setSerializer($this->serializer);

        return new GetFinancialProductsResponse($this->request, $this->sendRequest($this->request), $this->serializer);
    }

    /**
     * Creates a new order (loan application) with the provided details.
     *
     * @param OrderInterface $order The order details to create
     *
     * @return CreateOrderResponse @return CreateOrderResponse The response containing the created order details
     *                                     (201 Created response if the order is created successfully, 400 Bad Request
     *                                     response if the order is invalid)
     *
     * @throws RequestValidationError
     * @throws ResponseValidationError
     * @throws AuthorizationError
     * @throws AccessDenied
     * @throws ServiceUnavailable
     * @throws ClientExceptionInterface
     */
    public function createOrder(OrderInterface $order): CreateOrderResponse
    {
        $this->request = (new CreateOrderRequest($order, $this->apiKey ?? '', false))
            ->setSerializer($this->serializer);

        return new CreateOrderResponse($this->request, $this->sendRequest($this->request), $this->serializer);
    }

    /**
     * Validates the provided order (loan application) based on the given criteria and the cart content.
     *
     * @param OrderInterface $order The order details to validate
     *
     * @return ValidateOrderResponse The response containing validation results (200 OK response if the order is valid,
     *                               400 Bad Request response if the order is invalid)
     */
    public function validateOrder(OrderInterface $order): ValidateOrderResponse
    {
        try {
            $this->request = (new CreateOrderRequest($order, $this->apiKey ?? '', true))
                ->setSerializer($this->serializer);

            return new ValidateOrderResponse($this->request, $this->sendRequest($this->request), $this->serializer);
        } catch (Throwable $e) {
            return new ValidateOrderResponse(
                $this->request,
                $e instanceof RequestValidationError ? $e->getResponse() : $this->response,
                $this->serializer,
                $e
            );
        }
    }

    /**
     * Retrieves detailed information about a specific order based on the provided order ID (external ID sent in the
     * order creation request).
     *
     * @param string $orderId The ID of the order to retrieve
     *
     * @return GetOrderResponse The response containing the order details
     *
     * @throws RequestValidationError
     * @throws ResponseValidationError
     * @throws AuthorizationError
     * @throws AccessDenied
     * @throws ServiceUnavailable
     * @throws ClientExceptionInterface
     */
    public function getOrder(string $orderId): GetOrderResponse
    {
        $this->request = (new GetOrderRequest($orderId))->setSerializer($this->serializer);

        return new GetOrderResponse($this->request, $this->sendRequest($this->request), $this->serializer);
    }

    /**
     * Cancels the order based on the given order ID (external ID sent in the order creation request).
     *
     * @param string $orderId The ID of the order to cancel
     *
     * @throws RequestValidationError
     * @throws ResponseValidationError
     * @throws AuthorizationError
     * @throws AccessDenied
     * @throws ServiceUnavailable
     * @throws ClientExceptionInterface
     */
    public function cancelOrder(string $orderId): void
    {
        $this->request = (new CancelOrderRequest($orderId))->setSerializer($this->serializer);

        new BaseApiResponse($this->request, $this->sendRequest($this->request), $this->serializer);
    }

    /**
     * Retrieves a list of available financial product types for integration (depends on the shop contract).
     *
     * @param ProductListType $listType The type of product list to retrieve
     *
     * @return GetProductTypesResponse The response containing available product types (depends on the shop contract)
     *
     * @throws RequestValidationError
     * @throws ResponseValidationError
     * @throws AuthorizationError
     * @throws AccessDenied
     * @throws ServiceUnavailable
     * @throws ClientExceptionInterface
     */
    public function getProductTypes(ProductListType $listType): GetProductTypesResponse
    {
        $this->request = (new GetProductTypesRequest($listType))->setSerializer($this->serializer);

        return new GetProductTypesResponse($this->request, $this->sendRequest($this->request), $this->serializer);
    }

    /**
     * Returns a map of available creditors grouped by product type for an authorized shop account.
     *
     * Response shape: `{ "PRODUCT_TYPE_CODE": ["creditor_code", ...], ... }`
     *
     * @return GetCreditorsResponse The response containing the creditor map
     *
     * @throws RequestValidationError
     * @throws ResponseValidationError
     * @throws AuthorizationError
     * @throws AccessDenied
     * @throws ServiceUnavailable
     * @throws ClientExceptionInterface
     */
    public function getCreditors(): GetCreditorsResponse
    {
        $this->request = (new GetCreditorsRequest())->setSerializer($this->serializer);

        return new GetCreditorsResponse($this->request, $this->sendRequest($this->request), $this->serializer);
    }

    /**
     * Retrieves the key for the shop promotional banner widget.
     *
     * @return string Unique widget key for the shop
     *
     * @throws RequestValidationError
     * @throws ResponseValidationError
     * @throws AuthorizationError
     * @throws AccessDenied
     * @throws ServiceUnavailable
     * @throws ClientExceptionInterface
     */
    public function getWidgetKey(): string
    {
        $this->request = (new GetWidgetKeyRequest())->setSerializer($this->serializer);

        return (new GetWidgetKeyResponse($this->request, $this->sendRequest($this->request), $this->serializer))
            ->widgetKey;
    }

    /**
     * Retrieves a list of available widget types for integration.
     *
     * @return GetWidgetTypesResponse The response containing list of available widget types
     *
     * @throws RequestValidationError
     * @throws ResponseValidationError
     * @throws AuthorizationError
     * @throws AccessDenied
     * @throws ServiceUnavailable
     * @throws ClientExceptionInterface
     */
    public function getWidgetTypes(): GetWidgetTypesResponse
    {
        $this->request = (new GetWidgetTypesRequest())->setSerializer($this->serializer);

        return new GetWidgetTypesResponse($this->request, $this->sendRequest($this->request), $this->serializer);
    }

    /**
     * Claims a short-lived plugin access token for signing error-logging requests to CETS.
     *
     * The token replaces the permanent API key as the HMAC-SHA3-256 signing key for all error-log requests destined for
     * CETS (POST /v2/log-plugin-error). The plugin must cache the returned $accessToken locally for the TTL duration
     * and re-claim before $expiresAt. When the monolith is temporarily unreachable at renewal time, CETS's grace window
     * keeps the expired token valid.
     *
     * @return ClaimErrorLoggingTokenResponse Response with $accessToken (64-char hex) and $expiresAt (ISO 8601)
     *
     * @throws RequestValidationError
     * @throws ResponseValidationError
     * @throws AuthorizationError
     * @throws AccessDenied
     * @throws ServiceUnavailable
     * @throws ClientExceptionInterface
     */
    public function claimErrorLoggingToken(): ClaimErrorLoggingTokenResponse
    {
        $this->request = (new ClaimErrorLoggingTokenRequest())->setSerializer($this->serializer);

        return new ClaimErrorLoggingTokenResponse(
            $this->request,
            $this->sendRequest($this->request),
            $this->serializer
        );
    }

    /**
     * Retrieves the list of shop plugin platforms supported by the centralized release-notice API.
     *
     * @return GetSupportedPlatformsResponse The response containing the supported platforms (code + display name)
     *
     * @throws RequestValidationError
     * @throws ResponseValidationError
     * @throws AuthorizationError
     * @throws AccessDenied
     * @throws ServiceUnavailable
     * @throws ClientExceptionInterface
     */
    public function getSupportedPlatforms(): GetSupportedPlatformsResponse
    {
        $this->request = (new GetSupportedPlatformsRequest())->setSerializer($this->serializer);

        return new GetSupportedPlatformsResponse($this->request, $this->sendRequest($this->request), $this->serializer);
    }

    /**
     * Retrieves the latest published plugin release notice for the given platform (version, download URL, minimum
     * requirements, and a sanitized "what's new" description).
     *
     * @param string $platform Canonical platform slug (e.g. "prestashop", "woocommerce", "magento")
     *
     * @return GetLatestPluginReleaseResponse The response containing the latest release notice for the platform
     *
     * @throws RequestValidationError
     * @throws ResponseValidationError
     * @throws AuthorizationError
     * @throws AccessDenied
     * @throws ServiceUnavailable
     * @throws ClientExceptionInterface
     */
    public function getLatestPluginRelease(string $platform): GetLatestPluginReleaseResponse
    {
        $this->request = (new GetLatestPluginReleaseRequest($platform))->setSerializer($this->serializer);

        return new GetLatestPluginReleaseResponse(
            $this->request,
            $this->sendRequest($this->request),
            $this->serializer
        );
    }

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
    public function getTrackId(): string
    {
        return $this->trackId ??= $this->generateTrackId();
    }

    /**
     * Injects a track ID minted elsewhere (e.g., carried over from a checkout-scoped cookie) so it is reused instead
     * of a freshly generated one.
     *
     * No-op once a track ID has already been minted or injected for this instance - a client must never silently swap
     * its correlation ID mid-request. The value is validated against {@see TRACK_ID_PATTERN} here too, since it may
     * originate from a client-writable cookie: a corrupted or attacker-controlled value must never reach the
     * `Comfino-Track-Id` header or an order record.
     *
     * @param string|null $trackId Track ID to reuse, or null to leave the current state unchanged
     */
    public function setTrackId(?string $trackId): void
    {
        if ($this->trackId !== null || $trackId === null || preg_match(self::TRACK_ID_PATTERN, $trackId) !== 1) {
            return;
        }

        $this->trackId = $trackId;
    }

    /**
     * Sends the given request and returns the response.
     *
     * @param Request $request The request to send
     * @param int|null $apiVersion The API version to use (optional, defaults to the client's API version)
     *
     * @throws RequestValidationError
     * @throws ClientExceptionInterface
     */
    protected function sendRequest(Request $request, ?int $apiVersion = null): ResponseInterface
    {
        // Prepare track ID for debugging purposes.
        $this->trackId ??= $this->generateTrackId();

        // Prepare API request compatible with PSR-7.
        $apiRequest = $request->getPsrRequest(
            $this->requestFactory,
            $this->streamFactory,
            $this->getApiBaseUrl(),
            $apiVersion ?? $this->apiVersion
        )
        ->withHeader('Content-Type', $this->serializer->getContentType())
        ->withHeader('Accept', $this->serializer->getContentType())
        ->withHeader('Api-Language', $this->apiLanguage)
        ->withHeader('Api-Currency', $this->apiCurrency)
        ->withHeader('User-Agent', $this->getUserAgent())
        ->withHeader('Comfino-Track-Id', $this->trackId);

        if (count($this->customHeaders) > 0) {
            foreach ($this->customHeaders as $headerName => $headerValue) {
                $apiRequest = $apiRequest->withHeader($headerName, $headerValue);
            }
        }

        $this->response = $this->httpClient->sendRequest(
            !empty($this->apiKey) ? $apiRequest->withHeader('Api-Key', $this->apiKey) : $apiRequest
        );

        return $this->response;
    }

    /**
     * Retrieves the user agent string for the API client.
     *
     * @return string The user agent string
     */
    protected function getUserAgent(): string
    {
        return $this->customUserAgent ?? "Comfino API client {$this->getVersion()}";
    }

    /**
     * Generates the track ID used to correlate backend API calls with frontend events.
     *
     * @return string The generated track ID
     */
    private function generateTrackId(): string
    {
        $base = !empty($this->clientHostname) ? $this->clientHostname : gethostname();

        if ($base === false) {
            return 'trid-' . bin2hex(random_bytes(8));
        }

        return $base . '-' . microtime(true) . '-' . bin2hex(random_bytes(4));
    }
}
