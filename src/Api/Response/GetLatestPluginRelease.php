<?php

/**
 * ComfinoPay PHP API client
 *
 * Backend routines for communication with the ComfinoPay payment gateway REST API.
 *
 * @package Comfino\Api\Response
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-api-client
 */

declare(strict_types=1);

namespace Comfino\Api\Response;

/**
 * Response to the latest plugin release notice request.
 */
class GetLatestPluginRelease extends Base
{
    /** @var string Canonical platform slug the release belongs to */
    public readonly string $platform;
    /** @var string Normalized semantic version, leading 'v' stripped (e.g. "5.0.0") */
    public readonly string $version;
    /** @var string|null Download URL of the release asset (null when the asset was not found) */
    public readonly ?string $downloadUrl;
    /** @var string GitHub release page URL */
    public readonly string $releaseUrl;
    /** @var bool Whether the release is marked as a pre-release */
    public readonly bool $prerelease;
    /** @var string|null Release publication date (ISO 8601), if available */
    public readonly ?string $publishedAt;
    /** @var string|null Minimum required host platform version (e.g. PrestaShop "1.7.7") */
    public readonly ?string $minPlatformVersion;
    /** @var string|null Minimum required PHP version (e.g. "8.1") */
    public readonly ?string $minPhpVersion;
    /** @var string|null Pre-sanitized, user-friendly HTML "what's new" description */
    public readonly ?string $descriptionHtml;

    /** @inheritDoc */
    protected function processResponseBody(array|string|bool|null|float|int $deserializedResponseBody): void
    {
        $this->checkResponseType($deserializedResponseBody, 'array');
        $this->checkResponseStructure($deserializedResponseBody, ['platform', 'version', 'release_url']);

        $this->platform = $deserializedResponseBody['platform'];
        $this->version = $deserializedResponseBody['version'];
        $this->downloadUrl = $deserializedResponseBody['download_url'] ?? null;
        $this->releaseUrl = $deserializedResponseBody['release_url'];
        $this->prerelease = (bool) ($deserializedResponseBody['prerelease'] ?? false);
        $this->publishedAt = $deserializedResponseBody['published_at'] ?? null;
        $this->minPlatformVersion = $deserializedResponseBody['min_platform_version'] ?? null;
        $this->minPhpVersion = $deserializedResponseBody['min_php_version'] ?? null;
        $this->descriptionHtml = $deserializedResponseBody['description_html'] ?? null;
    }
}
