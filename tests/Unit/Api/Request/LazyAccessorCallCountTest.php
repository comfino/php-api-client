<?php

/**
 * ComfinoPay PHP API client
 *
 * Backend routines for communication with the ComfinoPay payment gateway REST API.
 *
 * @package Comfino\Tests\Unit\Api\Request
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-api-client
 */

declare(strict_types=1);

namespace Comfino\Tests\Unit\Api\Request;

use Comfino\Api\Request\CreateOrder;
use Comfino\Api\Serializer\Json;
use Comfino\Shop\Order\Cart\CartItemInterface;
use Comfino\Shop\Order\Cart\ProductInterface;
use Comfino\Shop\Order\CartInterface;
use Comfino\Shop\Order\Customer\AddressInterface;
use Comfino\Shop\Order\CustomerInterface;
use Comfino\Shop\Order\LoanParametersInterface;
use Comfino\Shop\Order\OrderInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * How many times building one order request calls back into the shop's own objects.
 *
 * `src/Shop/Order/` is a set of interfaces a plugin implements, and a plugin's implementation is routinely a lazy
 * accessor that loads from the database on each call. Reading `$this->order->getCustomer()` fourteen times and
 * `$cartItem->getProduct()` eleven times per line therefore multiplied the queries behind a single checkout, invisibly
 * from this side - the mocks in the rest of the suite answer instantly and never notice.
 *
 * These counts are the contract: fetch each collaborator once, then read from it.
 */
final class LazyAccessorCallCountTest extends TestCase
{
    public function testTheOrderIsAskedForEachCollaboratorExactlyOnce(): void
    {
        $order = $this->createMock(OrderInterface::class);

        $order->expects($this->once())->method('getCustomer')->willReturn($this->customer());
        $order->expects($this->once())->method('getLoanParameters')->willReturn($this->loanParameters());
        $order->expects($this->once())->method('getCart')->willReturn($this->cart(1));

        $this->buildRequest($order);
    }

    public function testTheCustomerIsAskedForItsAddressExactlyOnce(): void
    {
        $customer = $this->createMock(CustomerInterface::class);

        $this->stubCustomerFields($customer);
        $customer->expects($this->once())->method('getAddress')->willReturn($this->address());

        $this->buildRequest($this->order($customer, $this->cart(1)));
    }

    /**
     * Once per line, not once per field read from it - so the cost stays linear in the cart size.
     */
    public function testEachCartLineIsAskedForItsProductExactlyOnce(): void
    {
        $items = [];

        for ($line = 0; $line < 5; $line++) {
            $item = $this->createMock(CartItemInterface::class);

            $item->expects($this->once())->method('getProduct')->willReturn($this->product());
            $item->method('getQuantity')->willReturn(1);

            $items[] = $item;
        }

        $cart = $this->createMock(CartInterface::class);

        $cart->method('getItems')->willReturn($items);
        $cart->method('getTotalAmount')->willReturn(5000);
        $cart->method('getDeliveryCost')->willReturn(0);
        $cart->method('getDeliveryNetCost')->willReturn(null);
        $cart->method('getDeliveryCostTaxRate')->willReturn(null);
        $cart->method('getDeliveryCostTaxValue')->willReturn(null);
        $cart->method('getCategory')->willReturn(null);

        $this->buildRequest($this->order($this->customer(), $cart));
    }

    private function buildRequest(OrderInterface $order): void
    {
        (new CreateOrder($order, 'api-key'))->setSerializer(new Json());
    }

    private function order(CustomerInterface $customer, CartInterface $cart): OrderInterface
    {
        $order = $this->createMock(OrderInterface::class);

        $order->method('getId')->willReturn('ORD-1');
        $order->method('getNotifyUrl')->willReturn('https://shop.test/notify');
        $order->method('getReturnUrl')->willReturn('https://shop.test/return');
        $order->method('getLoanParameters')->willReturn($this->loanParameters());
        $order->method('getCart')->willReturn($cart);
        $order->method('getCustomer')->willReturn($customer);
        $order->method('getSeller')->willReturn(null);
        $order->method('getAccountNumber')->willReturn(null);
        $order->method('getTransferTitle')->willReturn(null);
        $order->method('getAllowedProductsConfig')->willReturn(null);

        return $order;
    }

    private function customer(): CustomerInterface
    {
        $customer = $this->createMock(CustomerInterface::class);

        $this->stubCustomerFields($customer);
        $customer->method('getAddress')->willReturn($this->address());

        return $customer;
    }

    /**
     * @param CustomerInterface&MockObject $customer Customer mock to fill in
     */
    private function stubCustomerFields(CustomerInterface $customer): void
    {
        $customer->method('getFirstName')->willReturn('Jan');
        $customer->method('getLastName')->willReturn('Kowalski');
        $customer->method('getEmail')->willReturn('jan@example.com');
        $customer->method('getPhoneNumber')->willReturn('500000000');
        $customer->method('getTaxId')->willReturn(null);
        $customer->method('getIp')->willReturn('127.0.0.1');
        $customer->method('isRegular')->willReturn(false);
        $customer->method('isLogged')->willReturn(false);
    }

    private function address(): AddressInterface
    {
        $address = $this->createMock(AddressInterface::class);

        $address->method('getStreet')->willReturn('Kwiatowa');
        $address->method('getBuildingNumber')->willReturn('1');
        $address->method('getApartmentNumber')->willReturn(null);
        $address->method('getPostalCode')->willReturn('00-950');
        $address->method('getCity')->willReturn('Warszawa');
        $address->method('getCountryCode')->willReturn('PL');

        return $address;
    }

    private function loanParameters(): LoanParametersInterface
    {
        $loanParameters = $this->createMock(LoanParametersInterface::class);

        $loanParameters->method('getAmount')->willReturn(5000);
        $loanParameters->method('getTerm')->willReturn(12);
        $loanParameters->method('getType')->willReturn(null);
        $loanParameters->method('getAllowedProductTypes')->willReturn(null);

        return $loanParameters;
    }

    private function product(): ProductInterface
    {
        $product = $this->createMock(ProductInterface::class);

        $product->method('getName')->willReturn('Test product');
        $product->method('getPrice')->willReturn(1000);
        $product->method('getId')->willReturn('PROD-1');
        $product->method('getPhotoUrl')->willReturn(null);
        $product->method('getEan')->willReturn(null);
        $product->method('getCategory')->willReturn(null);
        $product->method('getNetPrice')->willReturn(null);
        $product->method('getTaxRate')->willReturn(null);
        $product->method('getTaxValue')->willReturn(null);

        return $product;
    }

    private function cart(int $lines): CartInterface
    {
        $items = [];

        for ($line = 0; $line < $lines; $line++) {
            $item = $this->createMock(CartItemInterface::class);

            $item->method('getProduct')->willReturn($this->product());
            $item->method('getQuantity')->willReturn(1);

            $items[] = $item;
        }

        $cart = $this->createMock(CartInterface::class);

        $cart->method('getItems')->willReturn($items);
        $cart->method('getTotalAmount')->willReturn(1000 * $lines);
        $cart->method('getDeliveryCost')->willReturn(0);
        $cart->method('getDeliveryNetCost')->willReturn(null);
        $cart->method('getDeliveryCostTaxRate')->willReturn(null);
        $cart->method('getDeliveryCostTaxValue')->willReturn(null);
        $cart->method('getCategory')->willReturn(null);

        return $cart;
    }
}
