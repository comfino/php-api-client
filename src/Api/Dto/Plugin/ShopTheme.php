<?php

/**
 * Comfino PHP API client
 *
 * Backend routines for communication with the Comfino payment gateway REST API.
 *
 * @package Comfino\Api\Dto\Plugin
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-api-client
 */

declare(strict_types=1);

namespace Comfino\Api\Dto\Plugin;

/**
 * Theme metadata sent as part of the shop environment report.
 */
final class ShopTheme
{
    /**
     * @param string $code Raw platform theme identifier (e.g. 'Hyva_Theme')
     * @param string $family Normalized SDK profile ('hyva'|'luma'|'blank'|'classic'|'storefront'|'custom')
     * @param string[] $parents Parent theme inheritance chain, root-most first
     * @param bool|null $isPwa Whether the theme is a Progressive Web App; null when unknown
     */
    public function __construct(
        public readonly string $code,
        public readonly string $family,
        public readonly array $parents = [],
        public readonly ?bool $isPwa = null
    ) {
    }
}
