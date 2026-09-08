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

use Comfino\Api\CircuitBreaker\CircuitBreakerInterface;
use Comfino\Api\CircuitBreaker\CircuitBreakerKey;
use Comfino\Api\CircuitBreaker\NullCircuitBreaker;
use Comfino\Api\Dto\Account\UserRegistration;
use Comfino\Api\Dto\Payment\LoanQueryCriteria;
use Comfino\Api\Dto\Plugin\ShopEnvironmentReport;
use Comfino\Api\Dto\Plugin\ShopPluginError;
use Comfino\Api\Exception\ConnectionTimeout;
use Comfino\Api\Exception\RateLimitExceeded;
use Comfino\Api\Exception\RequestValidationError;
use Comfino\Api\Exception\RetryExhaustedException;
use Comfino\Api\Exception\ServiceUnavailable;
use Comfino\Api\RateLimit\NullRateLimiter;
use Comfino\Api\RateLimit\OutboundRateLimiterInterface;
use Comfino\Api\RateLimit\RateLimitKey;
use Comfino\Api\Request\CancelOrder as CancelOrderRequest;
use Comfino\Api\Request\ClaimErrorLoggingToken as ClaimErrorLoggingTokenRequest;
use Comfino\Api\Request\CreateOrder as CreateOrderRequest;
use Comfino\Api\Request\CreateUser as CreateUserRequest;
use Comfino\Api\Request\FetchAgreements as FetchAgreementsRequest;
use Comfino\Api\Request\GetCreditors as GetCreditorsRequest;
use Comfino\Api\Request\GetFinancialProductDetails as GetFinancialProductDetailsRequest;
use Comfino\Api\Request\GetFinancialProducts as GetFinancialProductsRequest;
use Comfino\Api\Request\GetLatestPluginRelease as GetLatestPluginReleaseRequest;
use Comfino\Api\Request\GetProductTypes as GetProductTypesRequest;
use Comfino\Api\Request\GetSupportedPlatforms as GetSupportedPlatformsRequest;
use Comfino\Api\Request\GetUserSettings as GetUserSettingsRequest;
use Comfino\Api\Request\GetWidgetKey as GetWidgetKeyRequest;
use Comfino\Api\Request\GetWidgetTypes as GetWidgetTypesRequest;
use Comfino\Api\Request\IsShopAccountActive as IsShopAccountActiveRequest;
use Comfino\Api\Request\NotifyAbandonedCart;
use Comfino\Api\Request\NotifyShopPluginRemoval;
use Comfino\Api\Request\ReportShopEnvironment;
use Comfino\Api\Request\ReportShopPluginError;
use Comfino\Api\Response\Base as BaseApiResponse;
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
use Comfino\Api\Response\GetWidgetKey as GetWidgetKeyResponse;
use Comfino\Api\Response\GetWidgetTypes as GetWidgetTypesResponse;
use Comfino\Api\Response\IsShopAccountActive as IsShopAccountActiveResponse;
use Comfino\Api\Response\ValidateOrder as ValidateOrderResponse;
use Comfino\Api\Retry\RetryContext;
use Comfino\Api\Retry\RetryExecutor;
use Comfino\Api\Retry\RetryableResponse;
use Comfino\Api\Retry\TimeoutAwareClientInterface;
use Comfino\Api\Retry\TimeoutConfig;
use Comfino\Api\Retry\TimeoutConfigurableClientInterface;
use Comfino\Api\Serializer\Json as JsonSerializer;
use Comfino\Enum\ProductListType;
use Comfino\Shop\Order\CartInterface;
use Comfino\Shop\Order\OrderInterface;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface as HttpClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Throwable;

/**
 * Stateless, container-scoped ComfinoPay API client.
 *
 * One instance serves every tenant. Nothing about *who* is calling lives on the object - the credential, the sandbox
 * flag, the custom headers, and the correlation ID travel in an {@see ApiContext} passed per call, and the per-call
 * site knobs travel in {@see RequestOptions}:
 *
 *     $products = $client->getFinancialProducts($context, $criteria, RequestOptions::attempts(2));
 *     $order    = $client->createOrder($context, $order, RequestOptions::idempotent($paymentId));
 *
 * What that buys, beyond the obvious one that a merchant's key can no longer be observed by another tenant because it
 * is never stored on the shared object:
 *
 *  - **No allocation per call.** One client, one retry executor, one policy, for the process lifetime.
 *  - **Per-call-site attempts and timeouts.** Previously the only way to send one call with two attempts and another
 *    with one was to rebuild the whole client.
 *  - **A shared connection pool that stays shared.** The credential is a header, not a connection property, so one TLS
 *    pool serving every tenant is both safe and the point - giving each tenant its own transport, the obvious "safe"
 *    reaction to a credential bug, would cost a TLS handshake per merchant per request.
 *  - **Safety under any runtime.** Nothing mutates, so php-fpm, a Messenger worker, RoadRunner and Swoole behave alike.
 *
 * The mutable-looking surface the four existing plugins are written against is still available: {@see BoundClient}
 * pairs this client with one context and implements {@see ClientInterface} unchanged.
 */
class SharedClient implements SharedClientInterface
{
    /** Client library version reported in the User-Agent and by {@see getVersion()}. */
    public const CLIENT_VERSION = '3.2.0';

    private readonly SerializerInterface $serializer;
    private readonly RequestObserverInterface $observer;
    private readonly OutboundRateLimiterInterface $rateLimiter;
    private readonly CircuitBreakerInterface $circuitBreaker;

    /**
     * @param HttpClientInterface $httpClient PSR-18 transport, shared and long-lived - see the note on connection
     *                                        reuse above
     * @param RequestFactoryInterface $requestFactory PSR-17 request factory
     * @param StreamFactoryInterface $streamFactory PSR-17 stream factory
     * @param int $apiVersion Default API version
     * @param SerializerInterface|null $serializer Request/response serializer; defaults to JSON
     * @param RetryExecutor|null $retryExecutor Retry executor, or null to send each request exactly once
     * @param RequestObserverInterface|null $observer Per-request observation hook for host-side metrics
     * @param OutboundRateLimiterInterface|null $rateLimiter Outbound limiter applied before the transport call
     * @param CircuitBreakerInterface|null $circuitBreaker Fast-fail gate in front of a failing API host
     * @param string $clientHostname Hostname used to seed generated correlation IDs
     */
    public function __construct(
        protected readonly HttpClientInterface $httpClient,
        protected readonly RequestFactoryInterface $requestFactory,
        protected readonly StreamFactoryInterface $streamFactory,
        protected readonly int $apiVersion = 1,
        ?SerializerInterface $serializer = null,
        protected readonly ?RetryExecutor $retryExecutor = null,
        ?RequestObserverInterface $observer = null,
        ?OutboundRateLimiterInterface $rateLimiter = null,
        ?CircuitBreakerInterface $circuitBreaker = null,
        protected readonly string $clientHostname = ''
    ) {
        $this->serializer = $serializer ?? new JsonSerializer();
        $this->observer = $observer ?? new NullRequestObserver();
        $this->rateLimiter = $rateLimiter ?? new NullRateLimiter();
        $this->circuitBreaker = $circuitBreaker ?? new NullCircuitBreaker();
    }

    /** @inheritDoc */
    public function getVersion(): string
    {
        return static::CLIENT_VERSION;
    }

    /** @inheritDoc */
    public function bind(ApiContext $context): BoundClient
    {
        return new BoundClient($this, $context);
    }

    /** @inheritDoc */
    public function getSerializer(): SerializerInterface
    {
        return $this->serializer;
    }

    /** @inheritDoc */
    public function isShopAccountActive(
        ApiContext $context,
        ?string $cacheInvalidateUrl = null,
        ?string $configurationUrl = null,
        ?RequestOptions $options = null
    ): bool {
        $request = $this->prepare(new IsShopAccountActiveRequest($cacheInvalidateUrl, $configurationUrl));

        return (new IsShopAccountActiveResponse(
            $request,
            $this->send($context, $request, $options),
            $this->serializer
        ))->isActive;
    }

    /** @inheritDoc */
    public function getFinancialProductDetails(
        ApiContext $context,
        LoanQueryCriteria $queryCriteria,
        CartInterface $cart,
        ?RequestOptions $options = null
    ): GetFinancialProductDetailsResponse {
        $request = $this->prepare(new GetFinancialProductDetailsRequest($queryCriteria, $cart));

        return new GetFinancialProductDetailsResponse(
            $request,
            $this->send($context, $request, $options),
            $this->serializer
        );
    }

    /** @inheritDoc */
    public function getFinancialProducts(
        ApiContext $context,
        LoanQueryCriteria $queryCriteria,
        ?RequestOptions $options = null
    ): GetFinancialProductsResponse {
        $request = $this->prepare(new GetFinancialProductsRequest($queryCriteria));

        return new GetFinancialProductsResponse($request, $this->send($context, $request, $options), $this->serializer);
    }

    /** @inheritDoc */
    public function createOrder(
        ApiContext $context,
        OrderInterface $order,
        ?RequestOptions $options = null
    ): CreateOrderResponse {
        $request = $this->prepare(new CreateOrderRequest($order, $context->apiKey, false));

        return new CreateOrderResponse($request, $this->send($context, $request, $options), $this->serializer);
    }

    /** @inheritDoc */
    public function validateOrder(
        ApiContext $context,
        OrderInterface $order,
        ?RequestOptions $options = null
    ): ValidateOrderResponse {
        $request = $this->prepare(new CreateOrderRequest($order, $context->apiKey, true));

        try {
            return new ValidateOrderResponse($request, $this->send($context, $request, $options), $this->serializer);
        } catch (Throwable $e) {
            /* The failed request is carried on the exception rather than read back off the client afterwards - the
               removed `getRequest()` returned whatever the previous caller sent, which in a shared process is another
               tenant's request body. */
            return new ValidateOrderResponse(
                $request,
                $e instanceof RequestValidationError ? $e->getResponse() : null,
                $this->serializer,
                $e
            );
        }
    }

    /** @inheritDoc */
    public function cancelOrder(ApiContext $context, string $orderId, ?RequestOptions $options = null): void
    {
        $request = $this->prepare(new CancelOrderRequest($orderId));

        new BaseApiResponse($request, $this->send($context, $request, $options), $this->serializer);
    }

    /** @inheritDoc */
    public function getProductTypes(
        ApiContext $context,
        ProductListType $listType,
        ?RequestOptions $options = null
    ): GetProductTypesResponse {
        $request = $this->prepare(new GetProductTypesRequest($listType));

        return new GetProductTypesResponse($request, $this->send($context, $request, $options, 2), $this->serializer);
    }

    /** @inheritDoc */
    public function getUserSettings(ApiContext $context, ?RequestOptions $options = null): GetUserSettingsResponse
    {
        $request = $this->prepare(new GetUserSettingsRequest());

        return new GetUserSettingsResponse($request, $this->send($context, $request, $options), $this->serializer);
    }

    /** @inheritDoc */
    public function getCreditors(ApiContext $context, ?RequestOptions $options = null): GetCreditorsResponse
    {
        $request = $this->prepare(new GetCreditorsRequest());

        return new GetCreditorsResponse($request, $this->send($context, $request, $options), $this->serializer);
    }

    /** @inheritDoc */
    public function getWidgetKey(ApiContext $context, ?RequestOptions $options = null): string
    {
        $request = $this->prepare(new GetWidgetKeyRequest());

        return (new GetWidgetKeyResponse($request, $this->send($context, $request, $options), $this->serializer))->widgetKey;
    }

    /** @inheritDoc */
    public function getWidgetTypes(ApiContext $context, ?RequestOptions $options = null): GetWidgetTypesResponse
    {
        $request = $this->prepare(new GetWidgetTypesRequest());

        return new GetWidgetTypesResponse($request, $this->send($context, $request, $options), $this->serializer);
    }

    /** @inheritDoc */
    public function claimErrorLoggingToken(
        ApiContext $context,
        ?RequestOptions $options = null
    ): ClaimErrorLoggingTokenResponse {
        $request = $this->prepare(new ClaimErrorLoggingTokenRequest());

        return new ClaimErrorLoggingTokenResponse(
            $request,
            $this->send($context, $request, $options),
            $this->serializer
        );
    }

    /** @inheritDoc */
    public function getSupportedPlatforms(
        ApiContext $context,
        ?RequestOptions $options = null
    ): GetSupportedPlatformsResponse {
        $request = $this->prepare(new GetSupportedPlatformsRequest());

        return new GetSupportedPlatformsResponse(
            $request,
            $this->send($context, $request, $options),
            $this->serializer
        );
    }

    /** @inheritDoc */
    public function getLatestPluginRelease(
        ApiContext $context,
        string $platform,
        ?RequestOptions $options = null
    ): GetLatestPluginReleaseResponse {
        $request = $this->prepare(new GetLatestPluginReleaseRequest($platform));

        return new GetLatestPluginReleaseResponse(
            $request,
            $this->send($context, $request, $options),
            $this->serializer
        );
    }

    /** @inheritDoc */
    public function sendLoggedError(
        ApiContext $context,
        ShopPluginError $shopPluginError,
        ?RequestOptions $options = null
    ): void {
        $request = $this->prepare(new ReportShopPluginError($shopPluginError, $this->getUserAgent($context)));

        // API version 2 is used for logged errors.
        new BaseApiResponse($request, $this->send($context, $request, $options, 2), $this->serializer);
    }

    /** @inheritDoc */
    public function notifyPluginRemoval(ApiContext $context, ?RequestOptions $options = null): bool
    {
        return $this->fireAndForget($context, new NotifyShopPluginRemoval(), $options);
    }

    /** @inheritDoc */
    public function notifyAbandonedCart(ApiContext $context, string $type, ?RequestOptions $options = null): bool
    {
        return $this->fireAndForget($context, new NotifyAbandonedCart($type), $options);
    }

    /** @inheritDoc */
    public function reportShopEnvironment(
        ApiContext $context,
        ShopEnvironmentReport $report,
        ?RequestOptions $options = null
    ): bool {
        return $this->fireAndForget($context, new ReportShopEnvironment($report), $options);
    }

    /** @inheritDoc */
    public function createUser(
        ApiContext $context,
        UserRegistration $registration,
        ?RequestOptions $options = null
    ): CreateUserResponse {
        $request = $this->prepare(new CreateUserRequest($registration));

        return new CreateUserResponse($request, $this->send($context, $request, $options), $this->serializer);
    }

    /** @inheritDoc */
    public function fetchAgreements(ApiContext $context, ?RequestOptions $options = null): FetchAgreementsResponse
    {
        $request = $this->prepare(new FetchAgreementsRequest());

        return new FetchAgreementsResponse($request, $this->send($context, $request, $options), $this->serializer);
    }

    /** @inheritDoc */
    public function sendCustomRequest(
        ApiContext $context,
        Request $request,
        string $responseClass = CustomResponse::class,
        ?RequestOptions $options = null
    ): Response {
        $request = $this->prepare($request);

        return new $responseClass($request, $this->send($context, $request, $options), $this->serializer);
    }

    /**
     * Sends a prepared request in the given context and returns the raw PSR-7 response.
     *
     * The pipeline, in order: breaker, limiter, then the retry loop. The breaker comes first because an open breaker
     * means the call must not cost anything at all, and the limiter comes before the loop because a rejected call must
     * not consume an attempt.
     *
     * @param ApiContext $context Tenant context the call is made in
     * @param Request $request Request to send, already carrying a serializer
     * @param RequestOptions|null $options Per-call options
     * @param int|null $apiVersion API version override applied when the options carry none
     *
     * @throws ConnectionTimeout When the retry budget is spent
     * @throws RateLimitExceeded When the outbound limiter rejects the call and the call site chose to queue it
     * @throws ServiceUnavailable When the breaker is open, or the limiter rejected a fail-fast call
     * @throws RequestValidationError
     * @throws ClientExceptionInterface
     * @throws Throwable
     */
    protected function send(
        ApiContext $context,
        Request $request,
        ?RequestOptions $options = null,
        ?int $apiVersion = null
    ): ResponseInterface {
        $options ??= new RequestOptions();
        $context = $context->withGeneratedTrackId($this->clientHostname);

        // Built once and reused by every attempt, so a retry puts the identical bytes on the wire.
        $psrRequest = $this->buildPsrRequest($context, $request, $options, $apiVersion);
        $breakerKey = CircuitBreakerKey::build($context->tenantKey, $context->getApiHost());

        if ($this->circuitBreaker->isOpen($breakerKey)) {
            throw new ServiceUnavailable(
                sprintf('ComfinoPay API circuit breaker is open for %s.', $context->getApiHost()),
                503,
                null,
                $request->getRequestUri() ?? '',
                $request->getRequestBody() ?? ''
            );
        }

        $this->reserveCapacity($context, $request, $options);

        if ($this->retryExecutor === null) {
            return $this->attempt($context, $psrRequest, $request, 1, $options->timeouts, $breakerKey);
        }

        $executor = $options->maxAttempts !== null
            ? $this->retryExecutor->withMaxAttempts($options->maxAttempts)
            : $this->retryExecutor;

        try {
            return $executor->execute(
                fn (int $attempt, TimeoutConfig $timeouts): ResponseInterface => $this->attempt(
                    $context,
                    $psrRequest,
                    $request,
                    $attempt,
                    $options->timeouts ?? $timeouts,
                    $breakerKey
                ),
                null,
                new RetryContext($request->isIdempotent(), $context->tenantKey, $psrRequest)
            );
        } catch (RetryExhaustedException $e) {
            $originalError = $e->getOriginalError();

            if ($originalError instanceof RetryableResponse) {
                /* The far side kept answering 429 or 5xx. Hand the last response back so the normal status-to-exception
                   mapping produces the exception the caller expects rather than a timeout that misreports the cause. */
                return $originalError->getResponse();
            }

            /* Converted to ConnectionTimeout so every plugin can keep handling one exception type without knowing about
               retry internals - and so the attempt count, the final timeouts, the URI and the body survive. */
            throw new ConnectionTimeout(
                $e->getMessage(),
                $e->getCode(),
                $originalError,
                $e->getAttemptCount(),
                $e->getLastTimeoutConfig()->connectionTimeout
                    ?? $executor->getRetryPolicy()->getBaseConnectionTimeout(),
                $e->getLastTimeoutConfig()->transferTimeout
                    ?? $executor->getRetryPolicy()->getBaseTransferTimeout(),
                $e->getRequestUri() ?? $request->getRequestUri() ?? '',
                $e->getRequestBody() ?? $request->getRequestBody() ?? ''
            );
        }
    }

    /**
     * Performs one transport attempt: picks transport configured for this attempt's timeouts, notifies the observer,
     * feeds the breaker, and lifts a retryable status code into the retry loop.
     *
     * @param ApiContext $context Tenant context the call is made in
     * @param RequestInterface $psrRequest PSR-7 request to send
     * @param Request $request Library request the PSR-7 one was built from
     * @param int $attempt Attempt number, counting from 1
     * @param TimeoutConfig|null $timeouts Timeouts for this attempt, when they can reach the transport
     * @param string $breakerKey Circuit breaker key for this tenant and host
     *
     * @throws RetryableResponse When the response is worth another attempt
     * @throws ClientExceptionInterface|Throwable
     */
    private function attempt(
        ApiContext $context,
        RequestInterface $psrRequest,
        Request $request,
        int $attempt,
        ?TimeoutConfig $timeouts,
        string $breakerKey
    ): ResponseInterface {
        $transport = $this->transportFor($timeouts);
        $startedAt = microtime(true);

        $this->observer->onRequest($context, $psrRequest, $attempt);

        try {
            $response = $transport->sendRequest($psrRequest);
        } catch (Throwable $error) {
            $this->circuitBreaker->recordFailure($breakerKey);
            $this->observer->onFailure($context, $psrRequest, $error, $attempt);

            throw $error;
        }

        $this->observer->onResponse($context, $psrRequest, $response, (microtime(true) - $startedAt) * 1000);

        $statusCode = $response->getStatusCode();

        if ($statusCode >= 500) {
            /* Only transport failures and 5xx feed the breaker: a 401 means one tenant's key is wrong, not that the
               host is down, and a breaker opened by those would block every healthy tenant sharing it. */
            $this->circuitBreaker->recordFailure($breakerKey);
        } else {
            $this->circuitBreaker->recordSuccess($breakerKey);
        }

        if ($this->retryExecutor === null) {
            return $response;
        }

        /* A status code is mapped to a typed exception only when the Response object is built, which happens after this
           method returns - so a 429 or a 503 would never reach the retry loop on its own. Wrapping it here is what puts
           the response in front of the classifier. */
        $retryableResponse = new RetryableResponse($response);

        if ($this->retryExecutor->getRetryPolicy()->classify($retryableResponse, $request->isIdempotent())->isRetryable()) {
            throw $retryableResponse;
        }

        return $response;
    }

    /**
     * Builds the PSR-7 request, applying the context's headers.
     *
     * @param ApiContext $context Tenant context the call is made in
     * @param Request $request Request to build
     * @param RequestOptions $options Per-call options
     * @param int|null $apiVersion API version override applied when the options carry none
     *
     * @throws RequestValidationError
     */
    private function buildPsrRequest(
        ApiContext $context,
        Request $request,
        RequestOptions $options,
        ?int $apiVersion
    ): RequestInterface {
        $psrRequest = $request->getPsrRequest(
            $this->requestFactory,
            $this->streamFactory,
            $context->getApiBaseUrl(),
            $options->apiVersion ?? $apiVersion ?? $this->apiVersion
        )
        ->withHeader('Content-Type', $this->serializer->getContentType())
        ->withHeader('Accept', $this->serializer->getContentType())
        ->withHeader('Api-Language', $context->apiLanguage)
        ->withHeader('Api-Currency', $context->apiCurrency)
        ->withHeader('User-Agent', $this->getUserAgent($context))
        ->withHeader('Comfino-Track-Id', (string) $context->trackId);

        foreach ($context->customHeaders as $headerName => $headerValue) {
            $psrRequest = $psrRequest->withHeader($headerName, $headerValue);
        }

        return $context->apiKey !== '' ? $psrRequest->withHeader('Api-Key', $context->apiKey) : $psrRequest;
    }

    /**
     * Asks the outbound limiter for capacity and turns a rejection into whatever the call site asked for.
     *
     * The full request URI is handed to {@see RateLimitKey::build()}, which reduces it to the endpoint it addresses -
     * the query string must not reach the key, or a parameterized `GET` gets a fresh full bucket per parameter value.
     *
     * @param ApiContext $context Tenant context the call is made in
     * @param Request $request Request being sent
     * @param RequestOptions $options Per-call options
     *
     * @throws RateLimitExceeded When the call site chose to queue the rejected call
     * @throws ServiceUnavailable When the call site chose to fail fast
     */
    private function reserveCapacity(ApiContext $context, Request $request, RequestOptions $options): void
    {
        $reservation = $this->rateLimiter->reserve(
            RateLimitKey::build($context->tenantKey, $request->getRequestUri() ?? static::class),
            $options->limiterTokens
        );

        if ($reservation->accepted) {
            return;
        }

        $message = sprintf('Outbound rate limit exceeded; capacity expected in %d ms.', $reservation->retryAfterMs);

        throw $options->onLimit === OnLimit::Queue
            ? new RateLimitExceeded(
                $message,
                $reservation->retryAfterMs,
                null,
                $request->getRequestUri() ?? '',
                $request->getRequestBody() ?? ''
            )
            : new ServiceUnavailable(
                $message,
                503,
                null,
                $request->getRequestUri() ?? '',
                $request->getRequestBody() ?? ''
            );
    }

    /**
     * Returns transport configured for the given timeouts, without ever reconfiguring the shared one.
     *
     * A transport implementing {@see TimeoutConfigurableClientInterface} yields a per-attempt copy. The deprecated
     * {@see TimeoutAwareClientInterface} is still honored so existing adapters keep escalating, but it mutates in
     * place: on a transport shared between tenants the last escalation stays applied to whoever calls next.
     *
     * @param TimeoutConfig|null $timeouts Timeouts for this attempt, or null to leave the transport as configured
     */
    private function transportFor(?TimeoutConfig $timeouts): HttpClientInterface
    {
        if ($timeouts === null) {
            return $this->httpClient;
        }

        if ($this->httpClient instanceof TimeoutConfigurableClientInterface) {
            return $this->httpClient->withTimeouts($timeouts);
        }

        if ($this->httpClient instanceof TimeoutAwareClientInterface) {
            $this->httpClient->updateTimeouts($timeouts->connectionTimeout, $timeouts->transferTimeout);
        }

        return $this->httpClient;
    }

    /**
     * Attaches the client's serializer to a request.
     *
     * @param Request $request Request to prepare
     */
    private function prepare(Request $request): Request
    {
        return $request->setSerializer($this->serializer);
    }

    /**
     * Sends a notification whose failure must never affect anything the shopper sees.
     *
     * @param ApiContext $context Tenant context the call is made in
     * @param Request $request Request to send
     * @param RequestOptions|null $options Per-call options
     *
     * @return bool True when the notification was accepted
     */
    private function fireAndForget(ApiContext $context, Request $request, ?RequestOptions $options): bool
    {
        try {
            $this->send($context, $this->prepare($request), $options);
        } catch (Throwable) {
            return false;
        }

        return true;
    }

    /**
     * Returns the User-Agent for a call: the context's own, or one naming this library and its version.
     *
     * @param ApiContext $context Tenant context the call is made in
     */
    protected function getUserAgent(ApiContext $context): string
    {
        return $context->userAgent ?? "ComfinoPay API client {$this->getVersion()}";
    }
}
