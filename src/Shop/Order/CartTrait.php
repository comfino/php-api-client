<?php

/**
 * ComfinoPay PHP API client
 *
 * Backend routines for communication with the ComfinoPay payment gateway REST API.
 *
 * @package Comfino\Shop\Order
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-api-client
 */

declare(strict_types=1);

namespace Comfino\Shop\Order;

/**
 * Provides common methods for working with shop carts.
 */
trait CartTrait
{
    /**
     * Converts cart to an array for the ComfinoPay API request. Adds a discount or correction item when cart items total doesn't match the order total.
     *
     * @return array{
     *     products: array<int, array<string, mixed>>,
     *     totalAmount: int,
     *     deliveryCost?: int,
     *     deliveryNetCost?: int,
     *     deliveryCostVatRate?: int,
     *     deliveryCostVatAmount?: int,
     *     category?: string
     * }
     */
    protected function getCartAsArray(CartInterface $cart): array
    {
        $products = [];
        $cartTotal = 0;

        foreach ($cart->getItems() as $cartItem) {
            $cartProduct = $cartItem->getProduct();
            $productPrice = $cartProduct->getPrice();
            $quantity = $cartItem->getQuantity();

            // Prepare product data for array filtering.
            $product = [
                'name' => $cartProduct->getName(),
                'quantity' => $quantity,
                'price' => $productPrice,
                'photoUrl' => $cartProduct->getPhotoUrl(),
                'ean' => $cartProduct->getEan(),
                'externalId' => $cartProduct->getId(),
                'category' => $cartProduct->getCategory(),
                'netPrice' => $cartProduct->getNetPrice(),
                'vatRate' => $cartProduct->getTaxRate(),
                'vatAmount' => $cartProduct->getTaxValue(),
            ];

            // Add product to the list.
            $products[] = array_filter($product, static fn ($value): bool => $value !== null);

            // Calculate cart total.
            $cartTotal += ($productPrice * $quantity);
        }

        // Check if cart total and order total values are equal.
        $deliveryCost = $cart->getDeliveryCost();
        $totalAmount = $cart->getTotalAmount();
        $cartTotalWithDelivery = $cartTotal + ($deliveryCost ?? 0);
        $cartTotalItemsSumDifference = (int) ($totalAmount - $cartTotalWithDelivery);

        if ($cartTotalWithDelivery > $totalAmount) {
            // Add discount item to the list - problems with cart items value and order total value inconsistency.
            $products[] = [
                'name' => 'Rabat',
                'quantity' => 1,
                'price' => $cartTotalItemsSumDifference,
                'netPrice' => $cartTotalItemsSumDifference,
                'vatRate' => null,
                'vatAmount' => 0,
                'category' => 'DISCOUNT',
            ];
        } elseif ($cartTotalWithDelivery < $totalAmount) {
            // Add correction item to the list - problems with cart items value and order total value inconsistency.
            $products[] = [
                'name' => 'Korekta',
                'quantity' => 1,
                'price' => $cartTotalItemsSumDifference,
                'netPrice' => $cartTotalItemsSumDifference,
                'vatRate' => null,
                'vatAmount' => 0,
                'category' => 'ADDITIONAL_FEE',
            ];
        }

        return array_filter(
            [
                'products' => $products,
                'totalAmount' => $totalAmount,
                'deliveryCost' => $deliveryCost,
                'deliveryNetCost' => $cart->getDeliveryNetCost(),
                'deliveryCostVatRate' => $cart->getDeliveryCostTaxRate(),
                'deliveryCostVatAmount' => $cart->getDeliveryCostTaxValue(),
                'category' => $cart->getCategory(),
            ],
            static fn ($value): bool => $value !== null
        );
    }
}
