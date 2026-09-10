<?php

/**
 * ComfinoPay PHP API client
 *
 * Backend routines for communication with the ComfinoPay payment gateway REST API.
 *
 * @package Comfino\Api\Dto\Plugin
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-api-client
 */

declare(strict_types=1);

namespace Comfino\Api\Dto\Plugin;

/**
 * Structured shop environment report sent server-to-server to the ComfinoPay API.
 *
 * Carries the full set of platform/plugin/theme facts the API uses to:
 *  - Build a knowledge base of theme structures for the auto-selector-detection feature;
 *  - Return platform-specific selector recommendations to the plugin;
 *  - Track installed plugin versions for support / compatibility analysis.
 *
 * This payload deliberately includes fingerprinting-grade fields (exact platform version, edition, plugin version, raw
 * theme code + parent chain, capability matrix) that are NOT exposed to the browser-loaded widget/paywall init script.
 * The browser receives only the reduced ShopEnvironment subset (platform, theme.family, locale, page context) - see
 * docs/SHOP_ENVIRONMENT_REPORTING_PLAN.md in the magento2 module repo for the full split.
 */
final class ShopEnvironmentReport
{
    /**
     * @param string $platform Platform identifier (e.g. 'magento', 'prestashop', 'woocommerce', 'shopware', 'shopify')
     * @param string $platformName Human-readable platform name (e.g. 'Magento', 'PrestaShop', 'WooCommerce')
     * @param string $platformVersion Exact platform version string (e.g. '2.4.8-p4')
     * @param string|null $platformEdition Edition when applicable (e.g. 'community', 'enterprise', 'cloud')
     * @param string $platformDomain Shop hostname
     * @param string $pluginVersion ComfinoPay plugin / module version
     * @param ShopTheme $theme Theme metadata
     * @param string $language Shop default locale (e.g. 'pl', 'pl-PL')
     * @param string $currency Shop default currency (e.g. 'PLN')
     * @param array<string, bool> $capabilities Best-effort framework hints
     *                                          (knockout/alpine/tailwind/react/jquery/requirejs)
     * @param string|null $testProductUrl Optional URL of an active product page the API may crawl for
     *                                    auto-selector detection. When omitted, the API skips the crawler step and
     *                                    returns only registry-based recommendations.
     * @param array<string, mixed> $meta Escape hatch for plugin- or platform-specific metadata that the API may opt
     *                                   into without a schema rev.
     */
    public function __construct(
        public string $platform,
        public string $platformName,
        public string $platformVersion,
        public ?string $platformEdition,
        public string $platformDomain,
        public string $pluginVersion,
        public ShopTheme $theme,
        public string $language,
        public string $currency,
        public array $capabilities = [],
        public ?string $testProductUrl = null,
        public array $meta = []
    ) {
    }
}
