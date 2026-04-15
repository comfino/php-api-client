<?php

/**
 * Comfino PHP API client
 *
 * Backend routines for communication with the Comfino payment gateway REST API.
 *
 * @package Comfino\Api
 * @author Artur Kozubski <akozubski@comperia.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-api-client
 */

declare(strict_types=1);

namespace Comfino\Api;

use Comfino\Api\Dto\Payment\LoanQueryCriteria;
use Comfino\Api\Dto\Plugin\ShopPluginError;
use Comfino\Api\Response\CreateOrder as CreateOrderResponse;
use Comfino\Api\Response\GetFinancialProductDetails as GetFinancialProductDetailsResponse;
use Comfino\Api\Response\GetFinancialProducts as GetFinancialProductsResponse;
use Comfino\Api\Response\GetOrder as GetOrderResponse;
use Comfino\Api\Response\GetProductTypes as GetProductTypesResponse;
use Comfino\Api\Response\GetWidgetTypes as GetWidgetTypesResponse;
use Comfino\Api\Response\ValidateOrder as ValidateOrderResponse;
use Comfino\Enum\ProductListType;
use Comfino\Shop\Order\CartInterface;
use Comfino\Shop\Order\OrderInterface;

/**
 * Comfino API client interface.
 */
interface ClientInterface
{
    /**
     * Checks if the shop account is active.
     *
     * @param ?string $cacheInvalidateUrl URL to invalidate the plugin cache at shop side (optional)
     * @param ?string $configurationUrl URL to retrieve plugin configuration details at shop side (optional)
     *
     * @return bool True if the shop account is active, false otherwise
     */
    public function isShopAccountActive(?string $cacheInvalidateUrl = null, ?string $configurationUrl = null): bool;

    /**
     * Retrieves detailed information about a specific financial product based on the provided query criteria and
     * shopping cart.
     *
     * @param LoanQueryCriteria $queryCriteria The criteria for filtering financial products
     * @param CartInterface $cart The shopping cart containing product details
     *
     * @return GetFinancialProductDetailsResponse The response containing detailed product information
     */
    public function getFinancialProductDetails(
        LoanQueryCriteria $queryCriteria,
        CartInterface $cart
    ): GetFinancialProductDetailsResponse;

    /**
     * Retrieves a list of available financial products filtered by the given query criteria.
     *
     * @param LoanQueryCriteria $queryCriteria The criteria for filtering financial products
     *
     * @return GetFinancialProductsResponse The response containing the list of financial products
     */
    public function getFinancialProducts(LoanQueryCriteria $queryCriteria): GetFinancialProductsResponse;

    /**
     * Creates a new order (loan application) with the provided details.
     *
     * @param OrderInterface $order The order details to create
     *
     * @return CreateOrderResponse The response containing the created order details (201 Created response if the order
     * is created successfully, 400 Bad Request response if the order is invalid)
     */
    public function createOrder(OrderInterface $order): CreateOrderResponse;

    /**
     * Validates the provided order (loan application) based on the given criteria and the cart content.
     *
     * @param OrderInterface $order The order details to validate
     *
     * @return ValidateOrderResponse The response containing validation results (200 OK response if the order is valid,
     *                               400 Bad Request response if the order is invalid)
     */
    public function validateOrder(OrderInterface $order): ValidateOrderResponse;

    /**
     * Retrieves detailed information about a specific order based on the provided order ID (external ID sent in the
     * order creation request).
     *
     * @param string $orderId The ID of the order to retrieve
     *
     * @return GetOrderResponse The response containing the order details
     */
    public function getOrder(string $orderId): GetOrderResponse;

    /**
     * Cancels the order based on the given order ID (external ID sent in the order creation request).
     *
     * @param string $orderId The ID of the order to cancel
     *
     * @return void
     */
    public function cancelOrder(string $orderId): void;

    /**
     * Retrieves a list of available financial product types for integration (depends on the shop contract).
     *
     * @param ProductListType $listType The type of product list to retrieve
     *
     * @return GetProductTypesResponse The response containing available product types (depends on the shop contract)
     */
    public function getProductTypes(ProductListType $listType): GetProductTypesResponse;

    /**
     * Retrieves the key for the shop promotional banner widget.
     *
     * @return string Unique widget key for the shop
     */
    public function getWidgetKey(): string;

    /**
     * Retrieves a list of available widget types for integration.
     *
     * @return GetWidgetTypesResponse The response containing list of available widget types
     */
    public function getWidgetTypes(): GetWidgetTypesResponse;

    /**
     * Sends a logged payment plugin error to the API.
     *
     * @param ShopPluginError $shopPluginError The error object to send
     *
     * @return bool True if the error was successfully sent, false otherwise
     */
    public function sendLoggedError(ShopPluginError $shopPluginError): bool;

    /**
     * Notifies the API that a shop payment plugin has been removed.
     *
     * @return bool True if the removal notification was successfully sent, false otherwise
     */
    public function notifyPluginRemoval(): bool;

    /**
     * Notifies the API that a shop abandoned cart has been detected.
     *
     * @param string $type Type of abandoned cart event
     *
     * @return bool True if the notification was successful, false otherwise
     */
    public function notifyAbandonedCart(string $type): bool;
}
