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
 * Describes what the plugin was doing when an error occurred.
 *
 * This is a structured replacement for the call-site label the plugin used to embed in the error message prefix (e.g.
 * "Widget script endpoint:", "Settings error on page ..."). The API-side classification pipeline reads it as a typed
 * field instead of parsing the message.
 */
enum OperationContext: string
{
    /** The operation context could not be determined (default). */
    case Unknown = 'unknown';

    /** Rendering or serving the widget initialization script. */
    case WidgetRendering = 'widget_rendering';

    /** Rendering or serving the paywall. */
    case PaywallRendering = 'paywall_rendering';

    /** Fetching or rendering available financial products / payment offers. */
    case PaymentProcessing = 'payment_processing';

    /** Creating an order in the Comfino system. */
    case OrderCreation = 'order_creation';

    /** Cancelling an order. */
    case OrderCancellation = 'order_cancellation';

    /** Reacting to an order status change. */
    case OrderStatusChange = 'order_status_change';

    /** Processing an inbound webhook / notification. */
    case WebhookProcessing = 'webhook_processing';

    /** Low-level communication with the Comfino API. */
    case ApiCommunication = 'api_communication';

    /** Reading or saving plugin configuration / settings. */
    case Configuration = 'configuration';

    /** Synchronizing shop state (catalogue, environment) with Comfino. */
    case ShopSynchronization = 'shop_synchronization';
}
