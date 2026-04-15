<a href="https://developers.comfino.pl">
  <img src="assets/comfino_logo.svg" alt="Comfino" width="220">
</a>

# Comfino PHP API client

[![Latest Version](https://img.shields.io/github/release/comfino/php-api-client.svg)](https://github.com/comfino/php-api-client/releases)
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
    apiKey: 'your-api-key', // Private - keep server-side only.
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

// Retrieve order status.
$orderDetails = $client->getOrder('ORDER-123');

// Cancel an order.
$client->cancelOrder('ORDER-123');
```

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
    stackTrace: $exception->getTraceAsString(),
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

### Retry and timeout escalation

Wrap the client with a `RetryExecutor` to retry transient network failures with exponential backoff:

```php
use Comfino\Api\Client;
use Comfino\Api\Retry\ExponentialBackoffRetryPolicy;
use Comfino\Api\Retry\RetryExecutor;
use Comfino\Api\Retry\TimeoutConfig;

$retryPolicy = new ExponentialBackoffRetryPolicy(
    timeoutConfig: new TimeoutConfig(connectionTimeout: 5, transferTimeout: 15),
    maxAttempts: 3,
);

$client = new Client(
    httpClient: $httpClient,
    requestFactory: $requestFactory,
    streamFactory: $streamFactory,
    apiKey: 'your-api-key',
    retryExecutor: new RetryExecutor($retryPolicy),
);
```

When the HTTP client also implements `TimeoutAwareClientInterface`, the executor automatically escalates connection and transfer timeouts on each retry attempt according to the policy schedule.

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
