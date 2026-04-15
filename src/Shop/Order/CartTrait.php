<?php

/**
 * Comfino PHP API client
 *
 * Backend routines for communication with the Comfino payment gateway REST API.
 *
 * @package Comfino\Shop\Order
 * @author Artur Kozubski <akozubski@comperia.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
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
     * Converts cart to an array for the Comfino API request.
     * Adds a discount or correction item when cart items total doesn't match the order total.
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
            // Prepare product data for array filtering.
            $product = [
                'name' => $cartItem->getProduct()->getName(),
                'quantity' => $cartItem->getQuantity(),
                'price' => $cartItem->getProduct()->getPrice(),
                'photoUrl' => $cartItem->getProduct()->getPhotoUrl(),
                'ean' => $cartItem->getProduct()->getEan(),
                'externalId' => $cartItem->getProduct()->getId(),
                'category' => $cartItem->getProduct()->getCategory(),
                'netPrice' => $cartItem->getProduct()->getNetPrice(),
                'vatRate' => $cartItem->getProduct()->getTaxRate(),
                'vatAmount' => $cartItem->getProduct()->getTaxValue(),
            ];

            // Add product to the list.
            $products[] = array_filter($product, static fn ($value): bool => $value !== null);

            // Calculate cart total.
            $cartTotal += ($cartItem->getProduct()->getPrice() * $cartItem->getQuantity());
        }

        // Check if cart total and order total values are equal.
        $cartTotalWithDelivery = $cartTotal + ($cart->getDeliveryCost() ?? 0);
        $cartTotalItemsSumDifference = (int) ($cart->getTotalAmount() - $cartTotalWithDelivery);

        if ($cartTotalWithDelivery > $cart->getTotalAmount()) {
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
        } elseif ($cartTotalWithDelivery < $cart->getTotalAmount()) {
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
                'totalAmount' => $cart->getTotalAmount(),
                'deliveryCost' => $cart->getDeliveryCost(),
                'deliveryNetCost' => $cart->getDeliveryNetCost(),
                'deliveryCostVatRate' => $cart->getDeliveryCostTaxRate(),
                'deliveryCostVatAmount' => $cart->getDeliveryCostTaxValue(),
                'category' => $cart->getCategory(),
            ],
            static fn ($value): bool => $value !== null
        );
    }
}
