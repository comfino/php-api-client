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
 * Used to guard custom API base URLs and dev-environment URL overrides so that production deployments can never be
 * steered at a spoofed host.
 */
final class UrlValidator
{
    /** @var string[] */
    public const ALLOWED_DOMAINS = ['comfino.pl', 'craty.pl', 'koszulawcraty.pl'];

    /**
     * Reserved TLDs used by local development stacks. `.test` is reserved by RFC 6761 for testing and is the suffix
     * the ComfinoPay dev environment uses (e.g. `widget-comfino.test`, `api-ecommerce.comfino.test`). Multi-label hosts
     * ending in one of these are accepted over plain http, since local dev environments rarely terminate TLS.
     *
     * @var string[]
     */
    public const DEV_TLD_SUFFIXES = ['.test'];

    /**
     * Returns true when $url is an acceptable ComfinoPay destination.
     *
     * Rules (in order):
     * 1. Scheme must be http or https.
     * 2. Raw IP hosts: allowed only for private/reserved ranges (RFC 1918, loopback, link-local, Docker).
     * 3. Single-label hostnames (no dot): always allowed — covers localhost and Docker service names.
     * 4. Dev TLDs (e.g. `.test`): allowed over http or https — local development domains.
     * 5. Multi-label hostnames: HTTPS only, and must end with an allowed ComfinoPay domain.
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

        $host = $parsedUrl['host'];

        // Strip IPv6 brackets so filter_var can validate the address.
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $host = substr($host, 1, -1);
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            // Allow only private/reserved ranges (RFC 1918, loopback 127.x, link-local, Docker 172.17.x).
            return filter_var(
                $host,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            ) === false;
        }

        // Single-label hostname (no dot) — localhost, Docker service names, bare container names.
        if (!str_contains($host, '.')) {
            return true;
        }

        $host = strtolower($host);

        // Dev TLDs (e.g. `.test`) — local development domains, reachable over plain http.
        foreach (self::DEV_TLD_SUFFIXES as $suffix) {
            if (str_ends_with($host, $suffix)) {
                return true;
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
}
