# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

> **Package lineage note**
> This package (`comfino/php-api-client`) is the direct continuation of the now-abandoned
> [`comfino/api-client`](https://packagist.org/packages/comfino/api-client) (last release: 1.1.2).
> Versioning starts at 2.0.0 to signal the intentional break in package identity and the
> significant additions made since the previous package.
> If you are upgrading from `comfino/api-client` 1.x, replace the package name in your
> `composer.json` and review the migration notes in the 2.0.0 entry below.

## [Unreleased]

## [2.1.0] - 2026-07-22

### Added
- **Product-level filtering on financial products queries** — new optional `allowedProductsConfig` parameter on `GetFinancialProducts` and order endpoints (`createOrder()`, `validateOrder()`) allows per-product term constraints (min/max term, specific allowed terms). Fully backward-compatible.
- **MessagePack serialization support** — new optional `Comfino\Api\Serializer\MsgPack` class for MessagePack request/response payloads. Requires the optional `ext-msgpack` PHP extension. Content negotiation automatically selects the serializer based on server response headers.
- **Shop environment reporting** — new `reportShopEnvironment()` method sends structured shop environment data (plugin version, platform, theme, capabilities) server-to-server to the Comfino API for fingerprinting, auto-detection recommendations, and version tracking. Uses `ShopEnvironmentReport` and `ShopTheme` DTOs with comprehensive validation (max string lengths, theme parent depth limits, capability matrix bounds).
- **Creditors map endpoint** — new `getCreditors()` method returns a map of financial product type codes to arrays of creditor codes (`array<string, string[]>`) for the authenticated shop account. Used to pass the `creditors` option to the Comfino Paywall SDK so it can render creditor logos next to each payment method.
- **`UrlValidator` utility class** — `Comfino\Api\Validation\UrlValidator::isAllowedUrl(string $url): bool` is now a standalone public utility you can call directly to validate that a URL targets a safe Comfino destination (allowed domains, private-range IPs, single-label Docker hostnames). The `UrlValidator::ALLOWED_DOMAINS` constant lists the accepted apex domains. Useful for integrators who need to gate widget SDK or CDN URLs with the same rules the client applies to custom API base URLs.
- **Structured shop plugin error reporting** — `sendLoggedError()` payloads (`ReportShopPluginError`) now carry typed `ErrorCategory`, `ErrorSeverity`, and `OperationContext` enums plus `pluginVersion`, `platformVersion`, `phpVersion`, `apiEndpoint`, and `occurredAt` fields, replacing free-form message-prefix parsing on the API side. Payloads are tagged with a `Comfino-Message-Version` header so the API can evolve the schema without breaking already-installed plugins.
- **Frontend error-logging token exchange** — new `claimErrorLoggingToken()` method fetches a short-lived (24 h, hex-encoded) plugin access token that replaces the permanent API key as the signing key for browser-side error reports sent by `FrontendLogAuthKeyGenerator::generateToken()`. `FrontendLogAuthKeyGenerator::generateToken()`'s second parameter is renamed from `$apiKey` to `$signingKey` to reflect this — pass the claimed access token instead of the API key.
- **Plugin release notices** — new `getSupportedPlatforms()` and `getLatestPluginRelease(string $platform)` methods expose the centralized release-notice API, returning supported platform codes/names and the latest version, download URL, minimum requirements, and a sanitized "what's new" description per platform.
- **Track ID injection** — new `AbstractClient::setTrackId()` lets a shop plugin reuse a track ID minted elsewhere (e.g. carried over from a checkout-scoped cookie) instead of generating a new one; validated against an allowlist pattern and a no-op once a track ID has already been set for the client instance.

### Changed
- **Automatic Content-Type negotiation** — client now automatically negotiates request and response serialization formats based on `Content-Type` headers. JSON remains the default; MessagePack is automatically selected if configured. Fully backward-compatible.
- **Track ID generation** — the auto-generated fallback track ID now includes a random suffix in addition to the microtime component, reducing collision risk under high request concurrency.

## [2.0.0] - 2026-04-15

### Added
- Initial release of a redesigned PSR-compliant PHP client for the Comfino payment gateway REST API.
- PSR-7 / PSR-17 / PSR-18 compliant HTTP layer with no framework dependencies.
- `Client` class with full Comfino REST API coverage: `createOrder()`, `validateOrder()`, `getOrder()`, `cancelOrder()`, `getFinancialProducts()`, `getFinancialProductDetails()`, `getProductTypes()`, `getWidgetKey()`, `getWidgetTypes()`, `isShopAccountActive()`.
- Fire-and-forget notification methods: `sendLoggedError()`, `notifyPluginRemoval()`, `notifyAbandonedCart()`.
- `RetryExecutor` with `ExponentialBackoffRetryPolicy` for automatic retry with exponential backoff on transient network errors.
- `TimeoutAwareClientInterface` support for per-attempt timeout escalation during retries.
- Typed exception hierarchy mapped to HTTP status codes: `RequestValidationError` (400), `AuthorizationError` (401), `Forbidden` (403), `NotFound` (404), `MethodNotAllowed` (405), `Conflict` (409), `ServiceUnavailable` (5xx), `ConnectionTimeout` (retry exhausted).
- SHA3-256 request signing for order creation (`Comfino-Cart-Hash`, `Comfino-Customer-Hash`, `Comfino-Order-Signature` headers).
- `WebhookSignatureVerifier` for timing-safe CR-Signature verification of incoming webhook requests.
- `PaywallAuthKeyGenerator` for time-limited HMAC-SHA3-256 auth token generation for the Paywall V3 iframe.
- Forward-compatible enum handling via `LoanType::fromApiValue()` and `Unknown*` flyweights for unrecognized API values.
- Shop domain integration interfaces: `OrderInterface`, `CartInterface`, `CustomerInterface`, `LoanParametersInterface`, `SellerInterface`.
- Docker development environment (PHP 8.1-cli-alpine) and `bin/` wrapper scripts.
- PHPUnit 10.5 test suite with unit and integration test suites.
- GitHub Actions CI matrix across PHP 8.1–8.4 with Codecov coverage upload.

[Unreleased]: https://github.com/comfino/php-api-client/compare/2.1.0...HEAD
[2.1.0]: https://github.com/comfino/php-api-client/compare/2.0.0...2.1.0
[2.0.0]: https://github.com/comfino/php-api-client/releases/tag/2.0.0
