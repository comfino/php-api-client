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

[Unreleased]: https://github.com/comfino/php-api-client/compare/2.0.0...HEAD
[2.0.0]: https://github.com/comfino/php-api-client/releases/tag/2.0.0
