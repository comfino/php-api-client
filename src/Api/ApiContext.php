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

use Comfino\Api\Validation\UrlValidator;
use InvalidArgumentException;

/**
 * Immutable per-tenant call context: everything a request needs to know about **who** is calling.
 *
 * This is the object that makes a cross-tenant credential leak impossible rather than merely avoided. The old client
 * held the API key, the sandbox flag, the custom headers, and the correlation ID as mutable instance state with public
 * setters, so the only safe usage was "construct one per credential and throw it away" - correct, but it meant a fresh
 * client, retry executor, and policy allocated for every availability probe, and it left the library no way to amortize
 * anything. With the credential traveling per call instead, one client can be a container service:
 *
 *     $products = $client->getFinancialProducts($context, $criteria);
 *
 * Each field is a per-tenant fact that used to be a per-instance one:
 *
 *  - `apiKey` - an order signed with another merchant's key is the failure this class exists to prevent;
 *  - `sandboxMode` - sandbox is per tenant, so a shared flag sends a production merchant to the sandbox host;
 *  - `customHeaders` - the old `addCustomHeader()` had no counterpart, so headers accumulated across tenants;
 *  - `trackId` - a lazily minted, instance-cached correlation ID welds every later tenant's calls to the first one's;
 *  - `tenantKey` - the partition key for the limiter, the breaker and any host-side cache or metric.
 */
final class ApiContext
{
    /**
     * @param string $apiKey API key authenticating this tenant's calls
     * @param bool $sandboxMode Whether calls go to the sandbox host
     * @param string|null $apiBaseUrl Custom API base URL, or null for the production/sandbox default
     * @param string $apiLanguage API language code (ISO 639-1)
     * @param string $apiCurrency API currency code (ISO 4217)
     * @param array<string, string> $customHeaders Extra headers sent with every call in this context
     * @param string|null $userAgent User-Agent override, or null to let the client build one
     * @param string|null $trackId Correlation ID shared by the calls made in this context
     * @param string|null $tenantKey Stable per-tenant key for limiter, breaker and cache partitioning
     *
     * @throws InvalidArgumentException If the base URL, a header, or the track ID is not acceptable
     */
    public function __construct(
        public readonly string $apiKey,
        public readonly bool $sandboxMode = false,
        public readonly ?string $apiBaseUrl = null,
        public readonly string $apiLanguage = 'pl',
        public readonly string $apiCurrency = 'PLN',
        public readonly array $customHeaders = [],
        public readonly ?string $userAgent = null,
        public readonly ?string $trackId = null,
        public readonly ?string $tenantKey = null
    ) {
        if ($this->apiBaseUrl !== null && !UrlValidator::isAllowedUrl($this->apiBaseUrl)) {
            throw new InvalidArgumentException(
                "API base URL '{$this->apiBaseUrl}' is not allowed. Accepted: ComfinoPay domains (" .
                implode(', ', UrlValidator::ALLOWED_DOMAINS) .
                ") over HTTPS, private/loopback IP addresses, and single-label hostnames."
            );
        }

        foreach ($this->customHeaders as $headerName => $headerValue) {
            self::assertValidHeader($headerName, $headerValue);
        }

        if ($this->trackId !== null && preg_match(AbstractClient::TRACK_ID_PATTERN, $this->trackId) !== 1) {
            throw new InvalidArgumentException('Track ID does not match the accepted format.');
        }
    }

    /**
     * Returns the effective API base URL for this context.
     */
    public function getApiBaseUrl(): string
    {
        return $this->apiBaseUrl ?? ($this->sandboxMode ? AbstractClient::SANDBOX_API_BASE_URL : AbstractClient::PRODUCTION_API_BASE_URL);
    }

    /**
     * Returns the API host, which is what the circuit breaker keys on alongside the tenant.
     */
    public function getApiHost(): string
    {
        return parse_url($this->getApiBaseUrl(), PHP_URL_HOST) ?: $this->getApiBaseUrl();
    }

    /**
     * Returns a copy with a different API key. Use for a key rotation, never to serve a second tenant from a context
     * that already carries another tenant's other fields.
     *
     * @param string $apiKey Replacement API key
     */
    public function withApiKey(string $apiKey): self
    {
        return $this->with(['apiKey' => $apiKey]);
    }

    /**
     * Returns a copy with the sandbox flag set as given.
     *
     * @param bool $sandboxMode Whether calls go to the sandbox host
     */
    public function withSandboxMode(bool $sandboxMode): self
    {
        return $this->with(['sandboxMode' => $sandboxMode]);
    }

    /**
     * Returns a copy pointing at a custom API base URL.
     *
     * @param string|null $apiBaseUrl Custom base URL, or null to fall back to the production/sandbox default
     */
    public function withApiBaseUrl(?string $apiBaseUrl): self
    {
        return $this->with(['apiBaseUrl' => $apiBaseUrl]);
    }

    /**
     * Returns a copy using the given language.
     *
     * @param string $apiLanguage API language code (ISO 639-1)
     */
    public function withApiLanguage(string $apiLanguage): self
    {
        return $this->with(['apiLanguage' => $apiLanguage]);
    }

    /**
     * Returns a copy using the given currency.
     *
     * @param string $apiCurrency API currency code (ISO 4217)
     */
    public function withApiCurrency(string $apiCurrency): self
    {
        return $this->with(['apiCurrency' => $apiCurrency]);
    }

    /**
     * Returns a copy with one extra header added or replaced.
     *
     * @param string $headerName Header name
     * @param string $headerValue Header value
     */
    public function withCustomHeader(string $headerName, string $headerValue): self
    {
        self::assertValidHeader($headerName, $headerValue);

        return $this->with(['customHeaders' => array_merge($this->customHeaders, [$headerName => $headerValue])]);
    }

    /**
     * Returns a copy without the named header.
     *
     * @param string $headerName Header name to drop
     */
    public function withoutCustomHeader(string $headerName): self
    {
        $headers = $this->customHeaders;

        unset($headers[$headerName]);

        return $this->with(['customHeaders' => $headers]);
    }

    /**
     * Returns a copy carrying no custom headers at all.
     */
    public function withoutCustomHeaders(): self
    {
        return $this->with(['customHeaders' => []]);
    }

    /**
     * Returns a copy with the given User-Agent.
     *
     * @param string|null $userAgent User-Agent string, or null to let the client build one
     */
    public function withUserAgent(?string $userAgent): self
    {
        return $this->with(['userAgent' => $userAgent]);
    }

    /**
     * Returns a copy carrying the given correlation ID.
     *
     * Unlike the old `setTrackId()`, which silently did nothing once an ID had been minted, this always produces the
     * context you asked for. The "never swap the ID mid-request" rule is preserved by immutability instead of by a
     * runtime no-op: the context a request was built from cannot change under it.
     *
     * @param string $trackId Correlation ID matching {@see AbstractClient::TRACK_ID_PATTERN}
     *
     * @throws InvalidArgumentException If the track ID does not match the accepted format
     */
    public function withTrackId(string $trackId): self
    {
        return $this->with(['trackId' => $trackId]);
    }

    /**
     * Returns a copy carrying a freshly minted correlation ID, or this context unchanged when it already has one.
     *
     * @param string|null $hostname Hostname to seed the ID with; defaults to the machine's own
     */
    public function withGeneratedTrackId(?string $hostname = null): self
    {
        return $this->trackId !== null ? $this : $this->with(['trackId' => self::generateTrackId($hostname)]);
    }

    /**
     * Returns a copy with the given tenant partition key.
     *
     * @param string|null $tenantKey Stable per-tenant key
     */
    public function withTenantKey(?string $tenantKey): self
    {
        return $this->with(['tenantKey' => $tenantKey]);
    }

    /**
     * Mints a correlation ID from a hostname plus a timestamp and random suffix.
     *
     * @param string|null $hostname Hostname to seed the ID with; defaults to the machine's own
     */
    public static function generateTrackId(?string $hostname = null): string
    {
        $base = $hostname !== null && $hostname !== '' ? $hostname : gethostname();

        if ($base === false) {
            return 'trid-' . bin2hex(random_bytes(8));
        }

        return $base . '-' . microtime(true) . '-' . bin2hex(random_bytes(4));
    }

    /**
     * Rejects a header name that is not a valid HTTP token, and a value carrying a CR or LF - the shape of a header
     * injection attempt.
     *
     * @param string $headerName Header name
     * @param string $headerValue Header value
     *
     * @throws InvalidArgumentException If either is unacceptable
     */
    private static function assertValidHeader(string $headerName, string $headerValue): void
    {
        if (preg_match('/^[!#$%&\'*+\-.^_`|~0-9A-Za-z]+$/', $headerName) !== 1) {
            throw new InvalidArgumentException("Invalid HTTP header name: '$headerName'");
        }

        if (preg_match('/[\r\n]/', $headerValue) === 1) {
            throw new InvalidArgumentException('Invalid HTTP header value: header injection attempt detected.');
        }
    }

    /**
     * Clones this context with the given fields replaced.
     *
     * @param array<string, mixed> $changes Field name to replacement value
     */
    private function with(array $changes): self
    {
        return new self(
            $changes['apiKey'] ?? $this->apiKey,
            $changes['sandboxMode'] ?? $this->sandboxMode,
            array_key_exists('apiBaseUrl', $changes) ? $changes['apiBaseUrl'] : $this->apiBaseUrl,
            $changes['apiLanguage'] ?? $this->apiLanguage,
            $changes['apiCurrency'] ?? $this->apiCurrency,
            $changes['customHeaders'] ?? $this->customHeaders,
            array_key_exists('userAgent', $changes) ? $changes['userAgent'] : $this->userAgent,
            array_key_exists('trackId', $changes) ? $changes['trackId'] : $this->trackId,
            array_key_exists('tenantKey', $changes) ? $changes['tenantKey'] : $this->tenantKey
        );
    }
}
