<a href="https://developers.comfino.pl">
  <img src="assets/comfino_logo.svg" alt="Comfino" width="220">
</a>

# Comfino PHP API client

[![Latest Version](https://img.shields.io/badge/release-3.0.0-blue.svg)](https://github.com/comfino/php-api-client/releases)
[![PHP Version](https://img.shields.io/packagist/dependency-v/comfino/php-api-client/php.svg)](https://packagist.org/packages/comfino/php-api-client)
[![Build Status](https://github.com/comfino/php-api-client/actions/workflows/tests.yml/badge.svg)](https://github.com/comfino/php-api-client/actions/workflows/tests.yml)
[![Software License](https://img.shields.io/badge/license-BSD%203--Clause-orange.svg)](LICENSE)
[![Total Downloads](https://img.shields.io/packagist/dt/comfino/php-api-client.svg)](https://packagist.org/packages/comfino/php-api-client)
[![API Documentation](https://img.shields.io/badge/API-documentation-5a9e33)](https://developers.comfino.pl)

**Comfino PHP API client library**

A portable, PSR-compliant PHP protocol layer for the Comfino payment gateway REST API.
This library handles all HTTP communication with the Comfino API: creating and managing loan applications (orders), querying available financial products, verifying webhook signatures, and generating paywall iframe authentication tokens for the shop checkout page.
It imposes no concrete HTTP client, serializer, or framework dependency — bring your own PSR-18 client and PSR-17 factories.

## Features

- PSR-18 HTTP Client / PSR-7 Messages / PSR-17 Factories support.
- Production and sandbox environment support.
- Exponential backoff retry for transient API errors.
- Secure webhook handling with CR-Signature (SHA3-256) verification.
- Time-limited HMAC-signed auth token generation for paywall iframe embedded at the shop checkout page.
- Typed exception hierarchy mapped to HTTP status codes.
- Forward-compatible enums: unknown API values are represented as flyweights rather than thrown as errors.

## Requirements

- PHP 8.1 or higher
- Extensions: `ext-json`, `ext-sodium`, `ext-zlib`
- PSR-18 HTTP Client and PSR-17 HTTP Factories implementations
- Composer

## Installation

```bash
composer require comfino/php-api-client
```

Suggested companion packages:

```bash
composer require nyholm/psr7               # PSR-7/17 message and factory implementation
composer require sunrise/http-client-curl  # PSR-18 cURL client implementation
```

## Quick start

The **API key** is issued by Comfino when your shop signs a merchant contract. It authenticates all server-to-server API calls and must be kept secret — never expose it in frontend code, browser requests, or public repositories.

```php
use Comfino\Api\Client;
use Nyholm\Psr7\Factory\Psr17Factory;
use Sunrise\Http\Client\Curl\Client as CurlClient;

$psr17Factory = new Psr17Factory();

$client = new Client(
    httpClient: new CurlClient($psr17Factory),
    requestFactory: $psr17Factory,
    streamFactory: $psr17Factory,
    apiKey: 'your-api-key' // Private - keep server-side only.
);

$client->enableSandboxMode(); // Omit or call disableSandboxMode() for production.

// Submit a loan application.
$response = $client->createOrder($order); // $order implements OrderInterface
header('Location: ' . $response->applicationUrl);
```

## Usage

### Client configuration

```php
// Override the default user agent.
$client->setCustomUserAgent('my-plugin/1.0.0');

// Set the API language (ISO 639-1) and currency (ISO 4217).
$client->setApiLanguage('pl');
$client->setApiCurrency('PLN');

// Add a custom HTTP header (e.g., for platform identification).
$client->addCustomHeader('X-Shop-Platform', 'WooCommerce/8.5');

// Use a different API endpoint (e.g., staging).
$client->setCustomApiBaseUrl('https://staging-api.example.com');
```

### Querying financial products

```php
use Comfino\Api\Dto\Payment\LoanQueryCriteria;
use Comfino\Enum\LoanType;
use Comfino\Enum\ProductListType;

// List all products for a 1 500 PLN cart (amounts in grosz).
$criteria = new LoanQueryCriteria(loanAmount: 150000);
$response = $client->getFinancialProducts($criteria);

foreach ($response->financialProducts as $product) {
    echo $product->name . ' - ' . $product->instalmentAmount . " grosz/month\n";
}

// Filter by product type.
$criteria = new LoanQueryCriteria(
    loanAmount: 150000,
    loanType: LoanType::INSTALLMENTS_ZERO_PERCENT
);

// Get detailed information about a specific financial product (e.g., for a product detail page).
$details = $client->getFinancialProductDetails($criteria, $cart); // $cart implements CartInterface

// Get available product types configured for this shop account (for promotional banner widget at shop product page).
$types = $client->getProductTypes(ProductListType::WIDGET);
```

### Order management

```php
// Create a loan application - $order implements Comfino\Shop\Order\OrderInterface.
$createResponse = $client->createOrder($order);
$applicationUrl = $createResponse->applicationUrl;

// Validate an order without submitting it.
$validateResponse = $client->validateOrder($order);

// Cancel an order.
$client->cancelOrder('ORDER-123');
```

See [request-signing.md](docs/request-signing.md) for details on how request signatures are computed automatically for order creation.

### Account and widget

The **widget key** is a public identifier associated with the Comfino merchant account pointed to by the API key. Unlike the API key, it is safe to embed in frontend scripts — it is used by the Comfino Web Frontend SDK to render the promotional banner widget and the paywall iframe at the shop checkout page.

```php
// Check that the API key belongs to an active account.
$isActive = $client->isShopAccountActive();

// Retrieve the widget key (public) for use in frontend scripts (e.g., promotional banner).
$widgetKey = $client->getWidgetKey();

// List available widget types.
$widgetTypes = $client->getWidgetTypes();
```

### Notifications (fire-and-forget)

These methods catch all exceptions internally and return `bool`. They are safe to call without a try/catch block.

```php
use Comfino\Api\Dto\Plugin\ShopPluginError;

// Report a plugin error for remote diagnostics (e.g., from an exception handler).
$client->sendLoggedError(new ShopPluginError(
    host: 'myshop.example.com',
    platform: 'ExampleEcommercePlatform',
    environment: ['php' => PHP_VERSION, 'plugin' => '2.0.0'],
    errorCode: 'API_ERROR',
    errorMessage: 'Unexpected API response.',
    stackTrace: $exception->getTraceAsString()
));

// Notify Comfino when the payment plugin is uninstalled.
$client->notifyPluginRemoval();

// Notify Comfino of an abandoned cart event.
$client->notifyAbandonedCart('checkout_abandoned');
```

### Webhook signature verification

Comfino signs status-update webhook requests with a `CR-Signature` header. Verify it before processing:

```php
use Comfino\Auth\WebhookSignatureVerifier;

$verifier = new WebhookSignatureVerifier();

$signature = $_SERVER['HTTP_CR_SIGNATURE'] ?? '';
$payload = file_get_contents('php://input');

if (!$verifier->verify($signature, 'your-api-key', $payload)) {
    http_response_code(401);
    exit;
}

// Process verified payload.
$data = json_decode($payload, true);
```

See [webhook-verification.md](docs/webhook-verification.md) for comprehensive webhook handling patterns, including framework integration, multiple API keys, replay attack prevention, and troubleshooting.

### Paywall authentication token

The Comfino paywall iframe embedded at the shop checkout page requires a short-lived signed token. Generate one server-side per page render using the public widget key and the private API key, then pass only the resulting token to the frontend — the API key never leaves the server:

```php
use Comfino\Auth\PaywallAuthKeyGenerator;

$generator = new PaywallAuthKeyGenerator();
// $widgetKey - public, obtained via $client->getWidgetKey() and stored in shop config
// $apiKey - private, never sent to the browser
$authKey = $generator->generateAuthKey(widgetKey: $widgetKey, apiKey: $apiKey);

// Pass only $authKey to the frontend widget initialization script served from the Comfino CDN (part of the official Comfino Web Frontend SDK).
```

Tokens are valid for 15 minutes (enforced server-side).

### Serving many merchants from one process

`Comfino\Api\Client` holds one merchant's credentials as mutable state, which is right for a shop plugin and wrong for
a long-lived service that handles many merchants in sequence. For that, use `SharedClient`: it stores no credential at
all, so a single instance — one transport, one connection pool, one retry executor — can serve every tenant.

```php
use Comfino\Api\ApiContext;
use Comfino\Api\RequestOptions;
use Comfino\Api\SharedClient;

// Register this once, as a service. It is stateless and safe to share.
$client = new SharedClient(
    httpClient: $httpClient,
    requestFactory: $requestFactory,
    streamFactory: $streamFactory,
    retryExecutor: new RetryExecutor($retryPolicy)
);

// Build one context per merchant, per request. Immutable, so it cannot be mutated out from under a call.
$context = new ApiContext(
    apiKey: $merchant->apiKey,
    sandboxMode: $merchant->isSandbox,
    tenantKey: $merchant->id // Partitions the limiter, the breaker, and your own metrics.
);

$products = $client->getFinancialProducts($context, $criteria, RequestOptions::attempts(2));
$order = $client->createOrder($context, $order, RequestOptions::failFast());
```

Keep the PSR-18 transport shared. The credential is a header, not a connection property, so one TLS pool serving every
tenant is both safe and the point — giving each merchant its own transport costs a handshake per merchant per request.

Already have code written against the mutable surface? `SharedClient::bind($context)` returns a `BoundClient`
implementing the same `ClientInterface`, one binding per tenant, with the credential still off anything shared.

### Retry, backoff and timeout escalation

Wrap the client with a `RetryExecutor` to retry transient failures with exponentially growing, jittered delays:

```php
use Comfino\Api\Client;
use Comfino\Api\Retry\ExponentialBackoffRetryPolicy;
use Comfino\Api\Retry\RetryExecutor;
use Comfino\Api\Retry\TimeoutConfig;

$retryPolicy = new ExponentialBackoffRetryPolicy(
    timeoutConfig: new TimeoutConfig(connectionTimeout: 5, transferTimeout: 15),
    maxAttempts: 3,
    maxTotalTransferTimeout: 15, // Budget shared by every attempt; null restores unbounded escalation.
    baseDelayMs: 100, // 0 disables the delay entirely
    maxDelayMs: 2000
);

$client = new Client(
    httpClient: $httpClient,
    requestFactory: $requestFactory,
    streamFactory: $streamFactory,
    apiKey: 'your-api-key',
    retryExecutor: new RetryExecutor($retryPolicy)
);
```

Two schedules grow per attempt, and they answer different failures. The **timeout** doubles, which is the right answer
to a slow far side. The **delay** doubles with full jitter, which is the right answer to a refused connection — and to
not turning every tenant on a node into one synchronized retry wave. Both are bounded: the escalation by
`maxTotalTransferTimeout`, the delay by `maxDelayMs`. They bound different clocks, so
`getWorstCaseWallClockMs()` reports the sum, which is the number to size a request latency budget against.

On a path that cannot absorb a sleep, say so rather than sleeping in it:

```php
// One attempt, no delay: surface the failure and let the shopper pick another method.
$policy = ExponentialBackoffRetryPolicy::failFast(new TimeoutConfig(1, 3));

// Retries, but never sleeping.
$policy = ExponentialBackoffRetryPolicy::withoutDelay(new TimeoutConfig(1, 3), maxAttempts: 2);
```

Retryable failures are transport errors, HTTP 429, 502, 503 and 504, plus 500 for requests that are safe to replay.
A `Retry-After` header is honored in both of its RFC 9110 forms and clamped, so a wrong header cannot park a worker.

**Order creation is safe to retry and needs no idempotency key.** The API deduplicates a replayed `POST /orders` by
`orderId` — mandatory, and unique per shop — plus the hash of the request body: a request for an id that already exists
with the same body is answered with the **existing** order at `201 Created` rather than creating a second loan
application, and a differing body under the same id is rejected as a validation error. The one thing this asks of a
caller is not to vary the body between attempts; the client builds each request once and reuses it across attempts, so
that holds automatically.

If you write a `Request` of your own against an endpoint with no such key, say so and it will be sent exactly once:

```php
final class RegisterSomething extends Request
{
    public function isIdempotent(): bool
    {
        return false; // No dedup key server-side, so never replay it.
    }

    // ...
}
```

For per-request timeouts to reach the wire, the transport has to accept them. Implement
`TimeoutConfigurableClientInterface::withTimeouts()`, which returns a configured copy, or wrap a transport whose
timeouts are construction options:

```php
use Comfino\Api\Retry\CallbackTimeoutAwareClient;
use Comfino\Api\Retry\TimeoutConfig;
use Symfony\Component\HttpClient\Psr18Client;

$transport = new CallbackTimeoutAwareClient(
    fn (TimeoutConfig $t) => new Psr18Client(
        $symfonyHttpClient->withOptions(['timeout' => $t->connectionTimeout, 'max_duration' => $t->transferTimeout])
    ),
    new TimeoutConfig(connectionTimeout: 1, transferTimeout: 3)
);
```

The older `TimeoutAwareClientInterface::updateTimeouts()` is still honored, but it mutates the transport in place and
never restores it — on transport shared between tenants, one tenant's escalated budget stays applied to the next
tenant's call. Prefer the copy-returning interface.

### Circuit breaker and outbound rate limiting

Both are optional and off by default. A breaker stops a Comfino outage from becoming your outage: instead of every
worker paying the full timeout on a dead socket, calls fail immediately once a host looks unhealthy.

```php
use Comfino\Api\CircuitBreaker\CircuitBreaker;
use Comfino\Api\RateLimit\TokenBucketRateLimiter;
use Comfino\Api\RateLimit\TwoTierRateLimiter;

$client = new SharedClient(
    httpClient: $httpClient,
    requestFactory: $requestFactory,
    streamFactory: $streamFactory,
    retryExecutor: new RetryExecutor($retryPolicy),
    rateLimiter: new TwoTierRateLimiter(
        perTenantLimiter: new TokenBucketRateLimiter(capacity: 20, refillTokensPerSecond: 5),
        globalLimiter: new TokenBucketRateLimiter(capacity: 200, refillTokensPerSecond: 50)
    ),
    circuitBreaker: new CircuitBreaker()
);
```

The breaker is keyed by `(tenantKey, host)`, and only transport failures and 5xx feed it: one merchant's wrong API key
produces 401s, and a breaker opened by those would block every healthy merchant on the same host. The limiter is
non-blocking by contract — what happens on rejection is a call-site decision:

```php
use Comfino\Api\OnLimit;

// Checkout: surface ServiceUnavailable and let the shopper choose another method.
$client->getFinancialProducts($context, $criteria, (new RequestOptions())->andOnLimit(OnLimit::FailFast));

// Worker: catch RateLimitExceeded, read getRetryAfterMs(), and put the call back on your queue.
$client->cancelOrder($context, $orderId, (new RequestOptions())->andOnLimit(OnLimit::Queue));
```

Pass a shared store to either one (both take a store interface) when several workers need to agree on what they have
learned; the in-memory defaults are per process.

### Observing requests and retries

Implement `RequestObserverInterface` or `RetryObserverInterface` to emit per-tenant metrics without patching anything.
Both receive the tenant, so nothing has to be inferred:

```php
final class MetricsObserver implements RequestObserverInterface
{
    public function onResponse(ApiContext $c, RequestInterface $rq, ResponseInterface $rs, float $durationMs): void
    {
        $this->histogram->observe($durationMs, ['tenant' => $c->tenantKey, 'status' => $rs->getStatusCode()]);
    }

    // onRequest() and onFailure() omitted for brevity.
}
```

### Custom requests

Call an endpoint that doesn't have a dedicated client method yet with `sendCustomRequest()`. It reuses the same authentication, track ID, and error-mapping infrastructure as every built-in method:

```php
use Comfino\Api\Request\CustomRequest;

// Free-form JSON in, free-form JSON out - no Request/Response subclass needed.
$response = $client->sendCustomRequest(
    new CustomRequest(method: 'POST', endpointPath: 'orders/ORDER-123/notes', body: ['note' => 'Called back, will retry payment.'])
);

$response->body; // Deserialized response body, verbatim (array|string|bool|int|float|null)
```

For a typed response, pass your own `Response` subclass (see `src/Api/Response/GetProductTypes.php` for a minimal example) as the second argument:

```php
$response = $client->sendCustomRequest(new CustomRequest('GET', 'orders/ORDER-123/notes'), MyNotesResponse::class);
```

## Error handling

All API errors are thrown as typed exceptions that implement `HttpErrorExceptionInterface` and preserve the original request and response bodies for debugging:

| HTTP status             | Exception                                      | Description                                       |
|-------------------------|------------------------------------------------|---------------------------------------------------|
| 400                     | `Comfino\Api\Exception\RequestValidationError` | Invalid request data.                             |
| 401                     | `Comfino\Api\Exception\AuthorizationError`     | Missing or invalid API key.                       |
| 403                     | `Comfino\Api\Exception\Forbidden`              | Permission issues.                                |
| 404                     | `Comfino\Api\Exception\NotFound`               | Resource not found.                               |
| 405                     | `Comfino\Api\Exception\MethodNotAllowed`       | HTTP method not allowed.                          |
| 409                     | `Comfino\Api\Exception\Conflict`               | Resource state conflict.                          |
| 5xx                     | `Comfino\Api\Exception\ServiceUnavailable`     | Server-side error.                                |
| timeout/retry exhausted | `Comfino\Api\Exception\ConnectionTimeout`      | HTTP client timeout or all retry attempts failed. |

```php
use Comfino\Api\Exception\AuthorizationError;
use Comfino\Api\Exception\RequestValidationError;
use Comfino\Api\Exception\ServiceUnavailable;

try {
    $response = $client->createOrder($order);
} catch (RequestValidationError $e) {
    // $e->errors contains field-level validation messages from the API.
} catch (AuthorizationError $e) {
    // Invalid or missing API key.
} catch (ServiceUnavailable $e) {
    // Comfino API is temporarily unavailable.
}
```

## Development

The `bin/` wrappers delegate to Docker containers when `docker-compose` is available, or fall back to the host PHP. Two containers are used:

- **`php-api-client`** — standard container, no Xdebug. Start it once with `docker-compose up -d`.
- **`php-api-client-coverage`** — built with Xdebug (`XDEBUG_MODE=coverage`). Started on demand automatically by `bin/phpunit` whenever a `--coverage*` flag is detected; no manual `up` needed.

```bash
# Start the standard development container.
docker-compose up -d

# Install dependencies.
./bin/composer install

# Run all tests.
./bin/composer test

# Run unit tests only.
./bin/phpunit --testsuite Unit

# Run integration tests against the sandbox (requires a sandbox API key).
COMFINO_SANDBOX_API_KEY=your-key ./bin/phpunit --testsuite Integration

# Generate HTML coverage report (Xdebug container starts automatically).
./bin/phpunit --coverage-html coverage

# Check PSR-12 code style.
./bin/composer cs

# Auto-fix PSR-12 violations.
./bin/composer cs-fix

# Run PHPStan static analysis (level 6).
./bin/composer analyse
```

## PSR standards

* **PSR-4** autoloading
* **PSR-7** HTTP messages
* **PSR-17** HTTP factories
* **PSR-18** HTTP client
* **PSR-12** coding style

## Changelog

See [CHANGELOG](CHANGELOG.md) for recent changes.

## License

BSD 3-Clause License. See [LICENSE](LICENSE) for details.

## Support

Bug reports and feature requests: [GitHub issue tracker](https://github.com/comfino/php-api-client/issues).

## Contributing

The [GitHub repository](https://github.com/comfino/php-api-client) is a read-only public mirror that receives automated clean-snapshot releases. Please report bugs and suggest improvements via the [issue tracker](https://github.com/comfino/php-api-client/issues).
