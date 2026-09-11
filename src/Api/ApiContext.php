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
 * Immutable per-tenant call context: everything a request needs to know about who is calling.
 *
 * The API key, sandbox flag, custom headers, and correlation ID travel per call instead of living as a mutable instance state,
 * which is what makes a cross-tenant credential leak impossible rather than merely avoided - one client can be a container
 * service shared by every tenant. `tenantKey` is the partition key for the limiter, breaker, and any host-side cache or metric.
 */
final class ApiContext
{
    /** Stand-in printed instead of a credential by {@see __debugInfo()}. */
    public const REDACTED = '[REDACTED]';

    /**
     * Custom header names whose values {@see __debugInfo()} replaces. Lower-case; compared case-insensitively.
     *
     * @var string[]
     */
    private const SENSITIVE_HEADER_NAMES = [
        'api-key', 'authorization', 'proxy-authorization', 'cookie', 'set-cookie',
        'x-api-key', 'x-auth-token', 'x-access-token', 'x-csrf-token',
    ];

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
                "API base URL '{$this->apiBaseUrl}' is not allowed. Accepted: " . UrlValidator::describeAllowedDestinations()
            );
        }

        foreach ($this->customHeaders as $headerName => $headerValue) {
            self::assertValidHeader($headerName, $headerValue);
        }

        /* Every one of these becomes a header value in SharedClient::buildPsrRequest(), so each needs the same CR/LF
           check the custom headers already got. Validating only the custom ones was arbitrary: these four are just as
           caller-supplied, and `apiLanguage` in particular is commonly wired straight to a shop's locale setting. PSR-7
           implementations mostly reject a malformed value themselves, but "mostly" is not a control - and the exception
           a transport raises deep inside a send is far harder to place than one from the constructor that took the bad
           value. */
        self::assertValidHeaderValue('API key', $this->apiKey);
        self::assertValidHeaderValue('API language', $this->apiLanguage);
        self::assertValidHeaderValue('API currency', $this->apiCurrency);

        if ($this->userAgent !== null) {
            self::assertValidHeaderValue('User agent', $this->userAgent);
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
     * Always produces the context you asked for. The "never swap the ID mid-request" rule is preserved by immutability:
     * the context a request was built from cannot change under it.
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
     * Redacted view of this context for `var_dump()` and `print_r()`.
     *
     * A context is the one object in the library holding a live merchant credential, and dumping one is the most ordinary
     * thing a developer does while debugging a failing call - into a terminal, an error page, or, via output buffering, a
     * log file that outlives the session. Without this, `$apiKey` prints in full.
     *
     * `var_export()` and `json_encode()` are not covered: neither consults `__debugInfo()`, and `$apiKey` is public
     * readonly, so short of a breaking API change, nothing can intercept them.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'apiKey' => $this->apiKey === '' ? '' : self::REDACTED,
            'sandboxMode' => $this->sandboxMode,
            'apiBaseUrl' => $this->apiBaseUrl,
            'apiLanguage' => $this->apiLanguage,
            'apiCurrency' => $this->apiCurrency,
            'customHeaders' => self::redactSensitiveHeaders($this->customHeaders),
            'userAgent' => $this->userAgent,
            'trackId' => $this->trackId,
            'tenantKey' => $this->tenantKey,
        ];
    }

    /**
     * Redacts the values of custom headers whose names are well-known credential carriers, leaving the rest readable.
     *
     * Plugins do add their own `Authorization` or token headers here, and a dump that hid every custom header would take
     * `X-Shop-Platform` with it - which is exactly the header someone is dumping the context to look at. Matching on the
     * name keeps the useful ones visible and the secrets out.
     *
     * @param array<string, string> $headers Custom headers to filter
     *
     * @return array<string, string> The same headers, with credential values replaced
     */
    public static function redactSensitiveHeaders(array $headers): array
    {
        foreach ($headers as $headerName => $headerValue) {
            if (in_array(strtolower($headerName), self::SENSITIVE_HEADER_NAMES, true)) {
                $headers[$headerName] = self::REDACTED;
            }
        }

        return $headers;
    }

    /**
     * Rejects a header name that is not a valid HTTP token, and a value carrying a CR or LF - the shape of a header injection attempt.
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

        self::assertValidHeaderValue("Header '$headerName'", $headerValue);
    }

    /**
     * Rejects a value that cannot legally be sent as an HTTP field value.
     *
     * A CR or LF is the shape of a response-splitting or header-injection attempt and is what this is really for. The
     * other C0 controls and DEL are rejected with them: RFC 9110 does not permit them in a field value either, and a
     * NUL in particular truncates the header at the C level in some transports, which turns a value the caller believes
     * they set into a shorter one nobody reviewed. HTAB and space stay legal, as does obs-text (0x80-0xFF), so a UTF-8
     * User-Agent or a non-ASCII currency name is not caught by this.
     *
     * @param string $description What is being validated, for the exception message
     * @param string $value Candidate header value
     *
     * @throws InvalidArgumentException If the value cannot be sent as a header
     */
    private static function assertValidHeaderValue(string $description, string $value): void
    {
        if (preg_match('/[\x00-\x08\x0A-\x1F\x7F]/', $value) === 1) {
            throw new InvalidArgumentException(
                "$description contains a character that is not allowed in an HTTP header value: header injection attempt detected."
            );
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
