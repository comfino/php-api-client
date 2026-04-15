<?php

/**
 * Comfino PHP API client
 *
 * Backend routines for communication with the Comfino payment gateway REST API.
 *
 * @package Comfino\Api\Response
 * @author Artur Kozubski <akozubski@comperia.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-api-client
 */

declare(strict_types=1);

namespace Comfino\Api\Response;

use Comfino\Api\Dto\Order\Cart;
use Comfino\Api\Dto\Order\Customer;
use Comfino\Api\Dto\Order\LoanParameters;
use Comfino\Enum\LoanType;
use Comfino\Enum\LoanTypeInterface;

/**
 * Response to order details retrieval request.
 */
class GetOrder extends Base
{
    /** @var string Order ID (shop order ID sent as external ID in the order creation request) */
    public readonly string $orderId;
    /** @var string Order status */
    public readonly string $status;
    /** @var \DateTime|null Order creation date */
    public readonly ?\DateTime $createdAt;
    /** @var string Order application URL received from Comfino */
    public readonly string $applicationUrl;
    /** @var string Order notification URL sent in the order creation request */
    public readonly string $notifyUrl;
    /** @var string Order return URL sent in the order creation request */
    public readonly string $returnUrl;
    /** @var LoanParameters Loan parameters sent in the order creation request */
    public readonly LoanParameters $loanParameters;
    /** @var Cart Cart data sent in the order creation request */
    public readonly Cart $cart;
    /** @var Customer Customer data sent in the order creation request */
    public readonly Customer $customer;

    /** @inheritDoc */
    protected function processResponseBody(array|string|bool|null|float|int $deserializedResponseBody): void
    {
        $this->checkResponseType($deserializedResponseBody, 'array');
        $this->checkResponseStructure(
            $deserializedResponseBody,
            [
                'orderId', 'status', 'createdAt', 'applicationUrl', 'notifyUrl', 'returnUrl',
                'loanParameters', 'cart', 'customer',
            ]
        );
        $this->checkResponseType($deserializedResponseBody['loanParameters'], 'array', 'loanParameters');
        $this->checkResponseType($deserializedResponseBody['cart'], 'array', 'cart');
        $this->checkResponseType($deserializedResponseBody['customer'], 'array', 'customer');

        try {
            $createdAt = new \DateTime($deserializedResponseBody['createdAt']);
        } catch (\Exception) {
            $createdAt = null;
        }

        $this->orderId = $deserializedResponseBody['orderId'];
        $this->status = $deserializedResponseBody['status'];
        $this->createdAt = $createdAt;
        $this->applicationUrl = $deserializedResponseBody['applicationUrl'];
        $this->notifyUrl = $deserializedResponseBody['notifyUrl'];
        $this->returnUrl = $deserializedResponseBody['returnUrl'];

        $this->checkResponseStructure(
            $deserializedResponseBody['loanParameters'],
            ['amount', 'maxAmount', 'term', 'type', 'allowedProductTypes']
        );

        $this->loanParameters = new LoanParameters(
            $deserializedResponseBody['loanParameters']['amount'],
            $deserializedResponseBody['loanParameters']['maxAmount'],
            $deserializedResponseBody['loanParameters']['term'],
            LoanType::fromApiValue($deserializedResponseBody['loanParameters']['type']),
            $deserializedResponseBody['loanParameters']['allowedProductTypes'] !== null ? array_map(
                static fn (string $productType): LoanTypeInterface => LoanType::fromApiValue($productType),
                $deserializedResponseBody['loanParameters']['allowedProductTypes']
            ) : null
        );

        $this->checkResponseStructure(
            $deserializedResponseBody['cart'],
            ['totalAmount', 'deliveryCost', 'category', 'products']
        );
        $this->checkResponseType($deserializedResponseBody['cart']['products'], 'array', 'cart.products');

        $this->cart = new Cart(
            $deserializedResponseBody['cart']['totalAmount'],
            $deserializedResponseBody['cart']['deliveryCost'],
            $deserializedResponseBody['cart']['category'],
            array_map(
                function ($cartItem): Cart\CartItem {
                    $this->checkResponseType($cartItem, 'array');
                    $this->checkResponseStructure(
                        $cartItem,
                        ['name', 'price', 'quantity', 'externalId', 'photoUrl', 'ean', 'category']
                    );

                    return new Cart\CartItem(
                        $cartItem['name'],
                        $cartItem['price'],
                        $cartItem['quantity'],
                        $cartItem['externalId'],
                        $cartItem['photoUrl'],
                        $cartItem['ean'],
                        $cartItem['category'],
                        $cartItem['netPrice'] ?? null,
                        $cartItem['vatRate'] ?? null,
                        $cartItem['vatAmount'] ?? null
                    );
                },
                $deserializedResponseBody['cart']['products']
            )
        );

        $this->checkResponseStructure(
            $deserializedResponseBody['customer'],
            ['firstName', 'lastName', 'email', 'phoneNumber', 'ip', 'taxId', 'regular', 'logged', 'address']
        );

        if (is_array($deserializedResponseBody['customer']['address'])) {
            $this->checkResponseStructure(
                $deserializedResponseBody['customer']['address'],
                ['street', 'buildingNumber', 'apartmentNumber', 'postalCode', 'city', 'countryCode']
            );
        }

        $this->customer = new Customer(
            $deserializedResponseBody['customer']['firstName'],
            $deserializedResponseBody['customer']['lastName'],
            $deserializedResponseBody['customer']['email'],
            $deserializedResponseBody['customer']['phoneNumber'],
            $deserializedResponseBody['customer']['ip'],
            $deserializedResponseBody['customer']['taxId'],
            $deserializedResponseBody['customer']['regular'],
            $deserializedResponseBody['customer']['logged'],
            $deserializedResponseBody['customer']['address'] !== null ? new Customer\Address(
                $deserializedResponseBody['customer']['address']['street'],
                $deserializedResponseBody['customer']['address']['buildingNumber'],
                $deserializedResponseBody['customer']['address']['apartmentNumber'],
                $deserializedResponseBody['customer']['address']['postalCode'],
                $deserializedResponseBody['customer']['address']['city'],
                $deserializedResponseBody['customer']['address']['countryCode']
            ) : null
        );
    }
}
