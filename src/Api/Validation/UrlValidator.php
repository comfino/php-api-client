<?php

/**
 * ComfinoPay PHP API client
 *
 * Backend routines for communication with the ComfinoPay payment gateway REST API.
 *
 * @package Comfino\Api\Validation
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-api-client
 */

declare(strict_types=1);

namespace Comfino\Api\Validation;

/**
 * Single source of truth for "is this a safe ComfinoPay destination URL".
 *
 * Used to guard custom API base URLs and dev-environment URL overrides so that production deployments can never be steered at a spoofed host.
 *
 * Cleartext http is off unless {@see DEV_ENV_VAR} marks a development environment: the API key travels as a request header, so an http
 * destination hands it to anyone on the path. Local dev stacks set the variable; a production deployment has no reason to.
 */
final class UrlValidator
{
    /** @var string[] */
    public const ALLOWED_DOMAINS = ['comfino.pl', 'craty.pl', 'koszulawcraty.pl'];

    /**
     * Reserved TLDs used by local dev stacks (`.test` per RFC 6761, e.g. `widget-comfino.test`). Multi-label hosts ending
     * in one of these are accepted, over plain http only when {@see DEV_ENV_VAR} marks a development environment.
     *
     * @var string[]
     */
    public const DEV_TLD_SUFFIXES = ['.test'];

    /**
     * Server-set environment variable marking a ComfinoPay development environment, and the gate for plain-http
     * destinations.
     *
     * Cleartext http is a dev-stack affordance, and leaving it on by default meant a production deployment steered at a
     * private IP, a single-label host or a `.test` domain would send the merchant's `Api-Key` header over the wire in the
     * clear - and expose the response body to whoever answered. This is the same variable every ComfinoPay plugin
     * (PrestaShop, WooCommerce, Magento 2) already gates its dev overrides on, so the library agrees with the host it
     * runs inside rather than inventing a second switch an operator has to know about.
     *
     * Must equal exactly `TRUE`, matching `getenv('COMFINO_DEV_ENV') === 'TRUE'` as the plugins spell it. Anything else,
     * including unset, a different case, or a different truthy word, keeps http off.
     */
    public const DEV_ENV_VAR = 'COMFINO_DEV_ENV';

    /** The only accepted value of {@see DEV_ENV_VAR}, spelled as every ComfinoPay plugin spells it. */
    public const DEV_ENV_ENABLED_VALUE = 'TRUE';

    /**
     * Returns true when $url is an acceptable ComfinoPay destination: the scheme must be http or https, a raw IP host must
     * be in a private/reserved range, a single-label host (localhost, Docker service names) is accepted, and a dev TLD is
     * accepted; any other multi-label host must use HTTPS and an allowed domain.
     *
     * Plain http narrows all of that: it is accepted only for the three dev destination classes above, and only while
     * {@see DEV_ENV_VAR} marks a development environment. An allowed ComfinoPay domain always requires HTTPS, gate or
     * no gate - there is no deployment that needs to reach the real API in the clear.
     *
     * @param string $url The URL to validate
     *
     * @return bool True when the URL resolves to an allowed destination
     */
    public static function isAllowedUrl(string $url): bool
    {
        $parsedUrl = parse_url($url);

        if ($parsedUrl === false || !isset($parsedUrl['host'], $parsedUrl['scheme'])) {
            return false;
        }

        $scheme = strtolower($parsedUrl['scheme']);

        if (!in_array($scheme, ['http', 'https'], true)) {
            return false;
        }

        // A dev destination reached over http is allowed only when the deployment opted in; https needs no gate.
        $schemeAllowed = $scheme === 'https' || self::devEnvActive();
        $host = $parsedUrl['host'];

        // Strip IPv6 brackets so filter_var can validate the address.
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $host = substr($host, 1, -1);
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            // Allow only private/reserved ranges (RFC 1918, loopback 127.x, link-local, Docker 172.17.x).
            return $schemeAllowed && filter_var(
                $host,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            ) === false;
        }

        // Single-label hostname (no dot) — localhost, Docker service names, bare container names.
        if (!str_contains($host, '.')) {
            return $schemeAllowed;
        }

        $host = strtolower($host);

        // Dev TLDs (e.g. `.test`) — local development domains.
        foreach (self::DEV_TLD_SUFFIXES as $suffix) {
            if (str_ends_with($host, $suffix)) {
                return $schemeAllowed;
            }
        }

        // Multi-label hostname: HTTPS only, must be an allowed ComfinoPay domain or its subdomain.
        if ($scheme !== 'https') {
            return false;
        }

        foreach (self::ALLOWED_DOMAINS as $domain) {
            if ($host === $domain || str_ends_with($host, '.' . $domain)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Returns true when the host of $url equals or is a subdomain of one of {@see ALLOWED_DOMAINS}. Unlike {@see isAllowedUrl()}
     * it says nothing about the scheme and never accepts a development host: it answers "is this ComfinoPay's own address".
     *
     * @param string $url The URL to check
     */
    public static function isComfinoHost(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);

        if (!is_string($host) || $host === '') {
            return false;
        }

        $host = strtolower(rtrim($host, '.'));

        foreach (self::ALLOWED_DOMAINS as $domain) {
            if ($host === $domain || str_ends_with($host, '.' . $domain)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether this process is running in a ComfinoPay development environment, which is what permits plain-http
     * destinations.
     *
     * Read on every call rather than cached, so a test - or a CLI process that sets the variable after the container is
     * built - sees the current value. Deliberately `getenv()` and an exact `=== 'TRUE'`, character for character what the
     * plugins do: a looser parser here would accept a value the host rejects, leaving the library and its plugin
     * disagreeing about whether dev mode is on.
     */
    public static function devEnvActive(): bool
    {
        return getenv(self::DEV_ENV_VAR) === self::DEV_ENV_ENABLED_VALUE;
    }

    /**
     * Human-readable description of what {@see isAllowedUrl()} currently accepts, for an exception message that has to
     * tell an integrator why their URL was refused - which depends on whether the http gate is open.
     */
    public static function describeAllowedDestinations(): string
    {
        return sprintf(
            'ComfinoPay domains (%s) over HTTPS, plus private/loopback IP addresses, single-label hostnames and %s domains%s.',
            implode(', ', self::ALLOWED_DOMAINS),
            implode(', ', self::DEV_TLD_SUFFIXES),
            self::devEnvActive()
                ? ' over either scheme (plain http is enabled by ' . self::DEV_ENV_VAR . '=' . self::DEV_ENV_ENABLED_VALUE . ')'
                : ' over HTTPS (set ' . self::DEV_ENV_VAR . '=' . self::DEV_ENV_ENABLED_VALUE . ' to allow plain http for these)'
        );
    }
}
