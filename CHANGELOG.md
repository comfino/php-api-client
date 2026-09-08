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

## [3.2.0] - 2026-09-08

Adds the shop owner self-registration flow, and makes the outbound rate limiter actually limit.

### Added

- **`fetchAgreements()`** - `GET fetch-agreements`. Returns the legal agreements (`Comfino\Api\Dto\Account\Agreement`:
  `id`, `content`, `required`) a shop owner must see, and where `required` is true must accept, before registering.
  Meant to be rendered as a list of consent checkboxes ahead of `createUser()`.
- **`createUser()`** - `POST user`. Registers a new shop owner account from a
  `Comfino\Api\Dto\Account\UserRegistration` (shop name, website URL, contact details, platform ID, and the IDs of the
  accepted agreements) and returns the `apiKey`/`widgetKey` pair the shop stores from that point on.

  Both calls are reachable with no API key - the account does not exist yet at this point in the flow - by passing an
  `ApiContext` whose `apiKey` is `''` (`SharedClient`/`BoundClient`) or a `Client`/`AbstractClient` constructed with a
  `null` key. A shop already registered under `webSiteUrl` is rejected with HTTP 409
  (`Comfino\Api\Exception\Conflict`), with the field-level reason in the exception message - not a bespoke error
  shape, the same mapping every other endpoint's validation failure goes through.

  `createUser()` is **not idempotent**: the endpoint has no key to deduplicate a replay by, so a retry that lands
  after an earlier attempt already succeeded cannot recover the original `apiKey`/`widgetKey` - it only surfaces as a
  409 the caller must treat as "already registered", not as a transient failure worth a second attempt.
  `Request::isIdempotent()` reflects this so retry logic built on it does the right thing automatically.

### Fixed

- **The limiter key was the request URI, not the endpoint.** `RateLimitKey::build()` took whatever the caller passed
  and used it whole, and `SharedClient` passes `Request::getRequestUri()` - query string included. So
  `GET /financial-products?loanAmount=130000` and `?loanAmount=130100` were two keys, hence two buckets, each with its
  own full capacity. *Why it matters:* the availability probe is the only call an integration issues at volume - once
  per checkout render, once per cart edit - and its query parameter is the cart total, which is about as unique per
  call as a parameter gets. A merchant sending ten thousand probes spent one token from each of ten thousand full
  buckets: the limiter was not loose, it was inert, and inert on precisely the endpoint whose traffic is the reason to
  have one. Two further consequences went with it - a shared store grew a row per distinct cart total per merchant
  rather than a bounded row per tenant, and `X-RateLimit`-style diagnostics described a bucket nobody could exhaust.
  `RateLimitKey::build()` now drops the query and fragment before assembling the key, so every caller gets the
  normalization rather than each host rediscovering the need for it. Scheme, host and path survive: production and
  sandbox are different hosts and a merchant on one must not spend the other's budget. An endpoint name that is not a
  URL (`'orders'`, a class name) is keyed exactly as given, so no existing wiring changes meaning.

  Nothing else changes. A host that had worked around this with a normalizing `OutboundRateLimiterInterface`
  decorator can drop it; leaving it in place is harmless, since the normalization is idempotent.

## [3.1.0] - 2026-08-25

Makes the two resilience features added in 3.0.0 correct when their state is actually shared. Both shipped with a
get-then-set store interface, which is exact in one process and quietly wrong across several — the deployment they were
built for. Nothing in this release changes behavior for a store that is process-local, which is every plugin.

### Fixed

- **The half-open probe was not one probe.** `CircuitBreaker` recorded an elapsed open window by clearing
  `CircuitBreakerState::$openedAt`, which reads as *closed* to every later caller — so the breaker admitted every
  concurrent call until the probe returned and failed. On a busy host that is the thundering herd the breaker exists to
  prevent, arriving on a timer. The probe is now claimed and stamped (`CircuitBreakerState::$probeStartedAt`) while
  `$openedAt` is kept, so the breaker stays open to everyone except the one caller holding the probe. A probe older than
  the open window is treated as lost and re-claimed, so a caller that dies mid-probe cannot wedge the breaker open.
  Sequential behavior is unchanged, which is why no plugin sees a difference.

### Added

- **`AtomicTokenBucketStoreInterface`** — `compareAndSet(string $key, ?TokenBucket $expected, TokenBucket $new): bool`.
  `TokenBucketRateLimiter` detects it and reserves through a bounded swap loop (three attempts, then a rejection),
  instead of get-then-set. *Why it matters:* reserving is a read-modify-write, so two workers sharing a store both read
  the same bucket and both write back their own result — the second write erases the first and a limiter configured for
  one burst allows one burst **per worker**. A rate limiter that over-admits does so exactly under load, which is the
  only time it was doing anything. The atomicity has to live in the store because only the backend can make the read
  and the write one operation (`WATCH`/Lua on Redis, `UPDATE … WHERE tokens = ?` on SQL, `apcu_cas()`).
- **`AtomicCircuitBreakerStoreInterface`** — the same swap for breaker state. `CircuitBreaker` uses it to *claim* the
  half-open probe, so exactly one worker probes however their reads interleave; with a plain store the claim is a blind
  write, which is exact in one process and loses a genuine race between two.
- **`TokenBucketRateLimiter::isExact()` and `CircuitBreaker::isExact()`** — whether the injected store can swap. A host
  can assert this in its wiring test, which is the only way to notice: a shared store that cannot swap looks identical
  to one that can, right up to the load that breaks it.
- **`InMemoryTokenBucketStore` and `InMemoryCircuitBreakerStore` now implement the atomic interfaces.** Honest rather
  than aspirational — PHP does not preempt between the read and the write of an array element, and the array is private
  to the process. It also lets the swap paths be tested without a Redis.
- **A guarantee table on both plain store interfaces**, stating which combinations of shared-ness and exactness each
  one delivers. The previous docblocks discussed *where* to keep the state and never mentioned that the interface
  cannot make a shared store correct.

### Changed

- `CircuitBreakerState` takes an optional third constructor argument (`$probeStartedAt`). Additive; a host that
  serializes the state should carry the new field, and one that does not degrades to a probe that is re-claimed each
  window rather than held.
- `recordFailure()` writes through a bounded swap loop when the store supports it, and falls back to an unconditional
  write when every swap is lost. Deliberately asymmetric with the limiter, which rejects instead: an undercounted
  failure only delays opening, while a discarded one can keep a dead host looking healthy.

## [3.0.0] - 2026-08-21

This release makes the client safe to share between tenants, fixes a retry defect that silently disabled timeout
handling in every plugin, and gives the retry stack the backoff its name has been promising. Existing single-tenant
integrations keep working: `Client` and `ClientInterface` keep their shape, and now forward to the stateless
implementation underneath.

> **Supersedes the unreleased 2.3.0.** Work that had been accumulating for 2.3.0 - the user-settings endpoint, the v2
> product-types migration, the total transfer budget and the webhook signature fix - was never tagged, so it ships here
> instead of as its own release. There is no 2.3.0: **2.2.0 upgrades directly to 3.0.0**, and everything below is part
> of that single step. Anyone who pinned a development snapshot calling itself 2.3.0 should read the whole entry, not
> only the parts marked new.

### Fixed
- **`RetryExhaustedException` was unreachable, so `ConnectionTimeout` was never produced** — `RetryExecutor` left through `throw $error` on every path, because `RetryPolicyInterface::shouldRetry()` answered three different questions with one boolean: "this error is not retryable", "the attempts are spent" and "the transfer budget is spent" were indistinguishable. The exhaustion exception after the loop was dead code, and with it the `ConnectionTimeout` that carries the attempt count, the final timeouts, the request URI and the request body — the exception every plugin's timeout handling is written against. The policy now answers the three questions separately (`isRetryable()`, `hasAttemptsLeft()`, `hasTimeBudgetLeft()`) and the executor names the exit it took through via `RetryExhaustedException::getReason()`. Regression-tested with the **real** `ExponentialBackoffRetryPolicy`, one case per exit — an always-retry mock is what hid the defect in the first place.
- **Retries had no delay at all** — `ExponentialBackoffRetryPolicy` escalated the *timeout* per attempt but never slept, so three attempts against a refused connection fired within microseconds of each other. `delayFor()` now returns a full-jitter delay (`baseDelayMs = 100`, `maxDelayMs = 2000` by default), slept through an injected `SleeperInterface`. `getWorstCaseWallClockMs()` reports the transfer budget plus the delays, which is the number a caller sizing a request-level latency budget actually needs.

### Security
- **Empty API key no longer verifies a webhook signature** *(was slated for 2.3.0)* — `WebhookSignatureVerifier::verify()` returns `false` when the API key or the signature is an empty string. With an empty key, the expected signature is derived from the payload alone, so any caller could compute it.
- **A merchant's API key can no longer reach another merchant's request** — the credential is no longer stored on the client at all (see `ApiContext` below). Previously the only safe usage was "construct one client per credential and throw it away"; the property is now structural rather than a matter of discipline.
- **Requests that cannot be replayed safely are no longer retried blindly** — a timeout or a 500 can arrive *after* the server accepted a request, so replaying one whose effect nothing deduplicates applies it twice. `Request::isIdempotent()` now gates both the retry loop and the classifier's 500 verdict; a request answering `false` is sent exactly once and the failure is surfaced. Every request in this library answers `true`, including order creation — `POST /orders` is deduplicated API-side by `orderId` plus the hash of the request body, so a replay is answered with the **existing** order at `201 Created`. Override it on a `CustomRequest` whose endpoint has no such key.

### Added
- **User settings endpoint** *(was slated for 2.3.0)* — new `getUserSettings()` method returns the authenticated shop's feature flags with their per-flag attributes, keyed by flag name (`array<string, array<string, mixed>>`), via `GetUserSettingsResponse::$flags` plus `hasFlag()`/`getFlagAttributes()` helpers.
- **`ApiContext` / `RequestOptions` and a stateless `SharedClient`** — everything about *who* is calling now travels per call in an immutable `ApiContext` (API key, sandbox flag, base URL, language, currency, custom headers, User-Agent, track ID, tenant key), and everything about *how* the call should be sent travels in `RequestOptions` (timeouts, attempt ceiling, API version, limiter behavior). One `SharedClient` instance can therefore be a container service shared by every tenant, over one transport and one TLS connection pool, with no per-call object churn — where before an attempt count or a timeout per call site meant rebuilding the client, the retry executor and the policy.
- **`BoundClient`** — pairs a `SharedClient` with one `ApiContext` and implements the existing `ClientInterface` unchanged, so a plugin's `setApiKey()` / `enableSandboxMode()` / `getFinancialProducts()` code keeps working while the credential stays off anything shared. Obtain one with `SharedClient::bind($context)`. Two bindings can serve two merchants over one transport safely.
- **`ErrorClassifier`** — one shared verdict on what "transient" means, replacing a boolean detector that answered transport failures only. HTTP 429, 502, 503 and 504 are now retryable, 500 only for a request the caller declared idempotent, and `Retry-After` is honored in both its delta-seconds and HTTP-date forms, clamped so a hostile or buggy header cannot park a worker for an hour. Previously the inline path gave up on a 503 exactly where the SDK's queue-side classifier would have kept going, and nothing in either library said so.
- **`TimeoutConfigurableClientInterface` and `CallbackTimeoutAwareClient`** — per-request timeouts that reach the wire. The client derives a per-attempt transport copy instead of reconfiguring a shared one, and the callback adapter carries a `TimeoutConfig` into transports that take their timeouts as construction or per-request options (Symfony's `Psr18Client` being the case that matters), where every escalated timeout this library computed was previously dropped on the floor.
- **`CircuitBreakerInterface` and a consecutive-failure `CircuitBreaker`** — keyed by `(tenantKey, host)` with a pluggable store, open after five consecutive failures, half-open probe after thirty seconds. When open, calls fail immediately with `ServiceUnavailable` instead of every worker paying the full timeout on a dead socket. Only transport failures and 5xx feed it: a 401 means one merchant's key is wrong, and a breaker opened by those would block every healthy tenant on the same host.
- **`OutboundRateLimiterInterface`, `TokenBucketRateLimiter` and `TwoTierRateLimiter`** — the outbound side of rate limiting, which did not exist. Non-blocking by contract; a per-tenant bucket for fairness nested inside a global bucket that protects the account's quota. What happens on rejection is a call-site decision via `RequestOptions::$onLimit`: `OnLimit::FailFast` surfaces `ServiceUnavailable` (the shopper-facing answer), `OnLimit::Queue` surfaces the new `RateLimitExceeded` carrying a retry-after hint (the worker-facing answer).
- **`RequestObserverInterface` and `RetryObserverInterface`** — per-request and per-retry hooks carrying the `ApiContext`, so a host can emit per-tenant latency and retry metrics without patching the library or reading state back off the client.
- **Named policy constructors** — `ExponentialBackoffRetryPolicy::withoutDelay()` for paths that must not sleep, and `::failFast()` for a single attempt with no delay, which is the honest configuration for a checkout render.
- **Header removal on the client** — `AbstractClient::removeCustomHeader()` / `clearCustomHeaders()`, the counterpart `addCustomHeader()` never had. Without it headers could only accumulate.

### Changed
- **BREAKING** *(was slated for 2.3.0)*: `getProductTypes()` now calls the v2 endpoint and `GetProductTypesResponse::$productTypesWithNames` holds only the internal display name; a new `$productTypesWithPublicNames` property exposes the customer-facing display name. Each product type is now returned as a `[internalName, publicName]` pair instead of a single name string.
- **Retry escalation is bounded by a total transfer budget** *(was slated for 2.3.0)* — `ExponentialBackoffRetryPolicy` now caps the transfer time of all attempts of one API call taken together at 15 seconds by default (`DEFAULT_MAX_TOTAL_TRANSFER_TIMEOUT`), where previously only single attempts were capped and an unresponsive API cost the escalated sum of every attempt. Pass `null` as the new third constructor argument to restore unlimited escalation; `getMaxTotalTransferTimeout()` reports the effective budget.
- **`AbstractClient` and `Client` are now facades over `SharedClient`** — they keep their fields, setters and method signatures, and forward every call through a `BoundClient` built from a snapshot of those fields. All the behavior above therefore reaches existing integrations unchanged. `AbstractClient::sendRequest()` is gone as a protected extension point; the request pipeline lives in `SharedClient::send()`.
- **`Unknown*` flyweight caches are capped** — `UnknownLoanType`, `UnknownOrderStatus` and `UnknownWidgetType` hold at most `MAX_CACHED_INSTANCES` (128) entries, evicting oldest-first. The cached values are immutable and tenant-independent, so a stale entry is harmless, but the cache grew with the number of *distinct* unknown values the API had ever returned — in a long-lived worker, an unbounded slow leak.

### Deprecated
- **`RetryPolicyInterface::shouldRetry()`** — kept as the conjunction of `isRetryable()`, `hasAttemptsLeft()` and `hasTimeBudgetLeft()`, but the executor no longer uses it: it cannot express *which* of the three failed. Note that the three new methods, plus `classify()` and `delayFor()`, are additions to the interface, so a custom policy implementation must add them.
- **`Psr18ErrorDetector`** — delegates to `ErrorClassifier`, which is what new code should use. Its verdict is deliberately left unchanged (transport failures only), so upgrading cannot change what an existing caller of it retries.
- **`TimeoutAwareClientInterface`** — implement `TimeoutConfigurableClientInterface` instead. `updateTimeouts()` mutates the transport in place and never restores it, so on a shared transport one tenant's escalated budget stays applied to the next tenant's call. Still honored when the newer interface is absent, so existing adapters keep escalating.

### Removed
- **BREAKING:** `AbstractClient::getRequest()`, and the `$request` / `$response` fields behind it. The getter returned whatever the *previous* caller sent, so in a process serving several merchants any error-reporting path that read it performed a cross-tenant read of a request body — and its existence was an invitation to exactly that statefulness. A failed request is carried on the exception (`HttpErrorExceptionInterface::getRequestBody()`); a successful one is observed as it happens through `RequestObserverInterface`, which also carries the tenant the getter could never identify.
- **BREAKING:** `AbstractClient::sendRequest()` as a protected extension point. The request pipeline lives in `SharedClient::send()`; a subclass that overrode it is no longer consulted.

## [2.2.0] - 2026-08-07

### Added
- **Custom request escape hatch** — new `sendCustomRequest(Request $request, string $responseClass = CustomResponse::class, ?int $apiVersion = null)` method lets integrators call an endpoint that has no dedicated client method yet, while still getting the same auth/track-ID/error-mapping infrastructure as the built-in methods. `Comfino\Api\Request\CustomRequest` covers free-form JSON requests without writing a `Request` subclass; `Comfino\Api\Response\CustomResponse` (the default `$responseClass`) exposes the deserialized body verbatim, or pass your own `Response` subclass for typed parsing.

### Removed
- **BREAKING:** `getOrder()` removed from `ClientInterface`/`AbstractClient`, along with the `GetOrder` request/response classes. Order status should be reconciled via the status webhook instead of polling.

## [2.1.0] - 2026-07-22

### Added
- **Product-level filtering on financial products queries** — new optional `allowedProductsConfig` parameter on `GetFinancialProducts` and order endpoints (`createOrder()`, `validateOrder()`) allows per-product term constraints (min/max term, specific allowed terms). Fully backward-compatible.
- **MessagePack serialization support** — new optional `Comfino\Api\Serializer\MsgPack` class for MessagePack request/response payloads. Requires the optional `ext-msgpack` PHP extension. Content negotiation automatically selects the serializer based on server response headers.
- **Shop environment reporting** — new `reportShopEnvironment()` method sends structured shop environment data (plugin version, platform, theme, capabilities) server-to-server to the ComfinoPay API for fingerprinting, auto-detection recommendations, and version tracking. Uses `ShopEnvironmentReport` and `ShopTheme` DTOs with comprehensive validation (max string lengths, theme parent depth limits, capability matrix bounds).
- **Creditors map endpoint** — new `getCreditors()` method returns a map of financial product type codes to arrays of creditor codes (`array<string, string[]>`) for the authenticated shop account. Used to pass the `creditors` option to the ComfinoPay Paywall SDK so it can render creditor logos next to each payment method.
- **`UrlValidator` utility class** — `Comfino\Api\Validation\UrlValidator::isAllowedUrl(string $url): bool` is now a standalone public utility you can call directly to validate that a URL targets a safe ComfinoPay destination (allowed domains, private-range IPs, single-label Docker hostnames). The `UrlValidator::ALLOWED_DOMAINS` constant lists the accepted apex domains. Useful for integrators who need to gate widget SDK or CDN URLs with the same rules the client applies to custom API base URLs.
- **Structured shop plugin error reporting** — `sendLoggedError()` payloads (`ReportShopPluginError`) now carry typed `ErrorCategory`, `ErrorSeverity`, and `OperationContext` enums plus `pluginVersion`, `platformVersion`, `phpVersion`, `apiEndpoint`, and `occurredAt` fields, replacing free-form message-prefix parsing on the API side. Payloads are tagged with a `Comfino-Message-Version` header so the API can evolve the schema without breaking already-installed plugins.
- **Frontend error-logging token exchange** — new `claimErrorLoggingToken()` method fetches a short-lived (24 h, hex-encoded) plugin access token that replaces the permanent API key as the signing key for browser-side error reports sent by `FrontendLogAuthKeyGenerator::generateToken()`. `FrontendLogAuthKeyGenerator::generateToken()`'s second parameter is renamed from `$apiKey` to `$signingKey` to reflect this — pass the claimed access token instead of the API key.
- **Plugin release notices** — new `getSupportedPlatforms()` and `getLatestPluginRelease(string $platform)` methods expose the centralized release-notice API, returning supported platform codes/names and the latest version, download URL, minimum requirements, and a sanitized "what's new" description per platform.
- **Track ID injection** — new `AbstractClient::setTrackId()` lets a shop plugin reuse a track ID minted elsewhere (e.g. carried over from a checkout-scoped cookie) instead of generating a new one; validated against an allowlist pattern and a no-op once a track ID has already been set for the client instance.

### Changed
- **Automatic Content-Type negotiation** — client now automatically negotiates request and response serialization formats based on `Content-Type` headers. JSON remains the default; MessagePack is automatically selected if configured. Fully backward-compatible.
- **Track ID generation** — the auto-generated fallback track ID now includes a random suffix in addition to the microtime component, reducing collision risk under high request concurrency.

## [2.0.0] - 2026-04-15

### Added
- Initial release of a redesigned PSR-compliant PHP client for the ComfinoPay payment gateway REST API.
- PSR-7 / PSR-17 / PSR-18 compliant HTTP layer with no framework dependencies.
- `Client` class with full ComfinoPay REST API coverage: `createOrder()`, `validateOrder()`, `getOrder()`, `cancelOrder()`, `getFinancialProducts()`, `getFinancialProductDetails()`, `getProductTypes()`, `getWidgetKey()`, `getWidgetTypes()`, `isShopAccountActive()`.
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

[Unreleased]: https://github.com/comfino/php-api-client/compare/3.2.0...HEAD
[3.2.0]: https://github.com/comfino/php-api-client/compare/3.1.0...3.2.0
[3.1.0]: https://github.com/comfino/php-api-client/compare/3.0.0...3.1.0
[3.0.0]: https://github.com/comfino/php-api-client/compare/2.2.0...3.0.0
[2.2.0]: https://github.com/comfino/php-api-client/compare/2.1.0...2.2.0
[2.1.0]: https://github.com/comfino/php-api-client/compare/2.0.0...2.1.0
[2.0.0]: https://github.com/comfino/php-api-client/releases/tag/2.0.0
