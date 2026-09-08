<?php

/**
 * ComfinoPay PHP API client
 *
 * Backend routines for communication with the ComfinoPay payment gateway REST API.
 *
 * @package Comfino\Api\Request
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-api-client
 */

declare(strict_types=1);

namespace Comfino\Api\Request;

use Comfino\Api\Dto\Plugin\ShopEnvironmentReport;
use Comfino\Api\Exception\RequestValidationError;
use Comfino\Api\Request;

/**
 * Shop environment reporting request.
 *
 * Sends the full structured shop environment server-to-server to the ComfinoPay API. The API uses the report to build a
 * per-theme selector knowledge base, return auto-detection recommendations, and track installed plugin / platform
 * versions for support purposes.
 *
 * The payload mirrors the {@see ShopEnvironmentReport} DTO and intentionally carries fingerprinting-grade fields
 * (exact versions, edition, raw theme code, capability matrix) that are NOT exposed to the browser-loaded
 * widget/paywall init script. Plugins consume the complementary browser-safe subset via the SDK's runtime
 * ShopEnvironment payload.
 *
 * Endpoint path uses the same `log-*` naming as {@see ReportShopPluginError} and {@see NotifyShopPluginRemoval} for
 * routing consistency on the API server side.
 */
class ReportShopEnvironment extends Request
{
    /** Maximum length of any single string field (characters). */
    private const MAX_STRING_FIELD_LENGTH = 256;

    /** Maximum number of theme parent entries accepted. */
    private const MAX_THEME_PARENTS = 10;

    /** Maximum number of capability hints accepted. */
    private const MAX_CAPABILITIES = 32;

    /** Maximum number of meta-entries accepted. */
    private const MAX_META_ENTRIES = 32;

    /** Maximum length of a single meta-value (characters). */
    private const MAX_META_VALUE_LENGTH = 200;

    /** Maximum length of the optional test product URL (characters). */
    private const MAX_URL_LENGTH = 2048;

    public function __construct(private readonly ShopEnvironmentReport $report)
    {
        $this->setRequestMethod('POST');
        $this->setApiEndpointPath('log-shop-environment');
    }

    protected function prepareRequestBody(): ?array
    {
        $report = $this->report;

        if ($report->platform === '' || $report->platformDomain === '' || $report->pluginVersion === '') {
            throw new RequestValidationError('ShopEnvironmentReport requires non-empty platform, platformDomain, and pluginVersion.');
        }

        $theme = $report->theme;

        $themeFamily = $theme->family !== ''
            ? self::truncate($theme->family, self::MAX_STRING_FIELD_LENGTH)
            : 'custom';

        $themeParents = [];

        foreach ($theme->parents as $parent) {
            if (count($themeParents) >= self::MAX_THEME_PARENTS) {
                break;
            }

            if ($parent !== '') {
                $themeParents[] = self::truncate($parent, self::MAX_STRING_FIELD_LENGTH);
            }
        }

        $themePayload = [
            'code' => self::truncate($theme->code, self::MAX_STRING_FIELD_LENGTH),
            'family' => $themeFamily,
            'parents' => $themeParents,
        ];

        if ($theme->isPwa !== null) {
            $themePayload['is_pwa'] = $theme->isPwa;
        }

        return [
            'platform' => self::truncate($report->platform, self::MAX_STRING_FIELD_LENGTH),
            'platform_name' => self::truncate($report->platformName, self::MAX_STRING_FIELD_LENGTH),
            'platform_version' => self::truncate($report->platformVersion, self::MAX_STRING_FIELD_LENGTH),
            'platform_edition' => $report->platformEdition !== null
                ? self::truncate($report->platformEdition, self::MAX_STRING_FIELD_LENGTH)
                : null,
            'platform_domain' => self::truncate($report->platformDomain, self::MAX_STRING_FIELD_LENGTH),
            'plugin_version' => self::truncate($report->pluginVersion, self::MAX_STRING_FIELD_LENGTH),
            'theme' => $themePayload,
            'language' => self::truncate($report->language, self::MAX_STRING_FIELD_LENGTH),
            'currency' => self::truncate($report->currency, self::MAX_STRING_FIELD_LENGTH),
            'capabilities' => self::sanitizeCapabilities($report->capabilities),
            'test_product_url' => $report->testProductUrl !== null
                ? self::truncate($report->testProductUrl, self::MAX_URL_LENGTH)
                : null,
            'meta' => self::sanitizeMeta($report->meta),
        ];
    }

    private static function truncate(string $value, int $maxLength): string
    {
        if (strlen($value) <= $maxLength) {
            return $value;
        }

        return substr($value, 0, $maxLength - 3) . '...';
    }

    /**
     * @param array<string, bool> $capabilities
     *
     * @return array<string, bool>
     */
    private static function sanitizeCapabilities(array $capabilities): array
    {
        $sanitized = [];

        foreach ($capabilities as $key => $value) {
            if (count($sanitized) >= self::MAX_CAPABILITIES) {
                break;
            }

            if (is_string($key) && $key !== '') {
                $sanitized[$key] = (bool) $value;
            }
        }

        return $sanitized;
    }

    /**
     * @param array<string, mixed> $meta
     *
     * @return array<string, string|int|float|bool>
     */
    private static function sanitizeMeta(array $meta): array
    {
        $sanitized = [];

        foreach ($meta as $key => $value) {
            if (count($sanitized) >= self::MAX_META_ENTRIES) {
                break;
            }

            if (!is_string($key) || $key === '') {
                continue;
            }

            if (is_bool($value) || is_int($value) || is_float($value)) {
                $sanitized[$key] = $value;
            } elseif (is_string($value)) {
                $sanitized[$key] = self::truncate($value, self::MAX_META_VALUE_LENGTH);
            }
        }

        return $sanitized;
    }
}
