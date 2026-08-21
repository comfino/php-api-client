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
use Comfino\Api\Retry\RetryExecutor;
use Comfino\Api\Serializer\Json as JsonSerializer;
use Comfino\Api\Validation\UrlValidator;
use Comfino\Enum\ProductListType;
use Comfino\Shop\Order\CartInterface;
use Comfino\Shop\Order\OrderInterface;
use InvalidArgumentException;
use Psr\Http\Client\ClientInterface as HttpClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Abstract base class for Comfino API clients.
 *
 * Since 3.0.0 this is the *single-tenant* surface: it holds one tenant's credentials as a mutable instance state and
 * forwards every call to a {@see SharedClient} through a {@see BoundClient} built from those fields. The forwarding is
 * what keeps the four existing plugins working unchanged while the actual request pipeline - retry, backoff, limiter,
 * breaker, observers - lives in one stateless place.
 *
 * A host that serves more than one merchant from one process should use {@see SharedClient} directly and pass an
 * {@see ApiContext} per call. The mutable state here makes any shared instance unsafe: the API key, the sandbox flag,
 * the accumulated custom headers and the minted correlation ID all belong to whoever configured the instance last.
 *
 * `getRequest()` was removed in 3.0.0 along with the `$request` / `$response` fields behind it. Reading the last
 * request back off the client returned whatever the *previous* caller sent, which in a process serving several
 * merchants is a cross-tenant read of a request body from any error-reporting path that used it - and the getter was
 * an API-shaped invitation to exactly that statefulness. A failed request is carried on the exception
 * ({@see HttpErrorExceptionInterface::getRequestBody()}); a successful one is observed as it happens through
 * {@see RequestObserverInterface}, which also carries the tenant the old getter could never identify.
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
    protected ?string $trackId = null;

    private ?SharedClient $sharedClient = null;

    // -------------------------------------------------------------------------
    // Non-API state
    // -------------------------------------------------------------------------

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
        $this->sharedClient = null;
    }

    /**
     * Sets the serializer for the client.
     *
     * @param SerializerInterface $serializer The serializer to use for API requests and responses
     */
    public function setSerializer(SerializerInterface $serializer): void
    {
        $this->serializer = $serializer;
        $this->sharedClient = null;
    }

    /**
     * Sets the API version for the client.
     *
     * @param int $version The API version to use (default is 1)
     */
    public function setApiVersion(int $version): void
    {
        $this->apiVersion = $version;
        $this->sharedClient = null;
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
     * Removes a custom HTTP header previously added with {@see addCustomHeader()}.
     *
     * @param string $headerName The name of the header to remove
     */
    public function removeCustomHeader(string $headerName): void
    {
        unset($this->customHeaders[$headerName]);
    }

    /**
     * Removes every custom HTTP header previously added with {@see addCustomHeader()}.
     */
    public function clearCustomHeaders(): void
    {
        $this->customHeaders = [];
    }

    /**
     * Returns the custom HTTP headers currently configured.
     *
     * @return array<string, string> Header name to value
     */
    public function getCustomHeaders(): array
    {
        return $this->customHeaders;
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
        $this->sharedClient = null;
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
        return $this->trackId ??= ApiContext::generateTrackId($this->clientHostname);
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

    // -------------------------------------------------------------------------
    // API calls
    // -------------------------------------------------------------------------

    /** @inheritDoc */
    public function isShopAccountActive(?string $cacheInvalidateUrl = null, ?string $configurationUrl = null): bool
    {
        return $this->boundClient()->isShopAccountActive($cacheInvalidateUrl, $configurationUrl);
    }

    /** @inheritDoc */
    public function getFinancialProductDetails(
        LoanQueryCriteria $queryCriteria,
        CartInterface $cart
    ): GetFinancialProductDetailsResponse {
        return $this->boundClient()->getFinancialProductDetails($queryCriteria, $cart);
    }

    /** @inheritDoc */
    public function getFinancialProducts(LoanQueryCriteria $queryCriteria): GetFinancialProductsResponse
    {
        return $this->boundClient()->getFinancialProducts($queryCriteria);
    }

    /** @inheritDoc */
    public function createOrder(OrderInterface $order): CreateOrderResponse
    {
        return $this->boundClient()->createOrder($order);
    }

    /** @inheritDoc */
    public function validateOrder(OrderInterface $order): ValidateOrderResponse
    {
        return $this->boundClient()->validateOrder($order);
    }

    /** @inheritDoc */
    public function cancelOrder(string $orderId): void
    {
        $this->boundClient()->cancelOrder($orderId);
    }

    /** @inheritDoc */
    public function getProductTypes(ProductListType $listType): GetProductTypesResponse
    {
        return $this->boundClient()->getProductTypes($listType);
    }

    /** @inheritDoc */
    public function getUserSettings(): GetUserSettingsResponse
    {
        return $this->boundClient()->getUserSettings();
    }

    /** @inheritDoc */
    public function getCreditors(): GetCreditorsResponse
    {
        return $this->boundClient()->getCreditors();
    }

    /** @inheritDoc */
    public function getWidgetKey(): string
    {
        return $this->boundClient()->getWidgetKey();
    }

    /** @inheritDoc */
    public function getWidgetTypes(): GetWidgetTypesResponse
    {
        return $this->boundClient()->getWidgetTypes();
    }

    /** @inheritDoc */
    public function claimErrorLoggingToken(): ClaimErrorLoggingTokenResponse
    {
        return $this->boundClient()->claimErrorLoggingToken();
    }

    /** @inheritDoc */
    public function getSupportedPlatforms(): GetSupportedPlatformsResponse
    {
        return $this->boundClient()->getSupportedPlatforms();
    }

    /** @inheritDoc */
    public function getLatestPluginRelease(string $platform): GetLatestPluginReleaseResponse
    {
        return $this->boundClient()->getLatestPluginRelease($platform);
    }

    /** @inheritDoc */
    public function sendCustomRequest(
        Request $request,
        string $responseClass = CustomResponse::class,
        ?int $apiVersion = null
    ): Response {
        return $this->boundClient()->sendCustomRequest($request, $responseClass, $apiVersion);
    }

    /**
     * Returns the retry executor requests are sent through, or null for no retry at all.
     *
     * Overridden by {@see Client}, which accepts one through its constructor.
     */
    protected function getRetryExecutor(): ?RetryExecutor
    {
        return null;
    }

    /**
     * Builds the {@see ApiContext} describing this instance's current configuration.
     *
     * A snapshot: the fields it reads are mutable, so the context is rebuilt per call rather than cached. That is the
     * price of the mutable surface, and the reason a multi-tenant host should hold contexts of its own instead.
     */
    protected function buildContext(): ApiContext
    {
        return new ApiContext(
            $this->apiKey ?? '',
            $this->isSandboxMode,
            $this->customApiBaseUrl,
            $this->apiLanguage,
            $this->apiCurrency,
            $this->customHeaders,
            $this->customUserAgent ?? "Comfino API client {$this->getVersion()}",
            $this->trackId ??= ApiContext::generateTrackId($this->clientHostname)
        );
    }

    /**
     * Returns the stateless client every call is forwarded to, building it on first use.
     *
     * Cached and invalidated by the setters that change how requests are transported ({@see setHttpClient()},
     * {@see setSerializer()}, {@see setApiVersion()}, {@see setClientHostname()}). Built lazily rather than in the
     * constructor because {@see Client} supplies its retry executor as a promoted property, which is not assigned until
     * after `parent::__construct()` returns.
     */
    protected function sharedClient(): SharedClient
    {
        return $this->sharedClient ??= new SharedClient(
            $this->httpClient,
            $this->requestFactory,
            $this->streamFactory,
            $this->apiVersion,
            $this->serializer,
            $this->getRetryExecutor(),
            clientHostname: $this->clientHostname
        );
    }

    /**
     * Pairs the stateless client with a context snapshot of this instance's current configuration.
     */
    protected function boundClient(): BoundClient
    {
        return new BoundClient($this->sharedClient(), $this->buildContext());
    }
}
