<?php

/**
 * Comfino PHP API client
 *
 * Backend routines for communication with the Comfino payment gateway REST API.
 *
 * @package Comfino\Api\Request
 * @author Artur Kozubski <akozubski@comperia.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-api-client
 */

declare(strict_types=1);

namespace Comfino\Api\Request;

use Comfino\Api\Request;
use Comfino\Shop\Order\CartTrait;
use Comfino\Shop\Order\OrderInterface;
use JsonException;

/**
 * Loan application creation request.
 */
class CreateOrder extends Request
{
    use CartTrait;

    /** @var array<string, mixed>|null */
    private ?array $preparedRequestBody = null;

    /**
     * @param OrderInterface $order Full order data (cart, loan details)
     * @param string $apiKey API key used for order validation
     * @param bool $validateOnly Flag used for order validation (if true, order is not created and only validation
     *                           result is returned)
     */
    public function __construct(
        private readonly OrderInterface $order,
        string $apiKey,
        private readonly bool $validateOnly = false
    ) {
        $this->setRequestMethod('POST');
        $this->setApiEndpointPath('orders');

        $preparedRequestBody = $this->prepareRequestBody();
        $cartHash = $this->generateHash($preparedRequestBody['cart']);
        $customerHash = $this->generateHash($preparedRequestBody['customer']);

        $this->setRequestHeaders([
            'Comfino-Cart-Hash' => $cartHash,
            'Comfino-Customer-Hash' => $customerHash,
            'Comfino-Order-Signature' => hash('sha3-256', $cartHash . $customerHash . $apiKey),
        ]);
    }

    /** @inheritDoc */
    protected function prepareRequestBody(): array
    {
        if ($this->preparedRequestBody !== null) {
            return $this->preparedRequestBody;
        }

        // Request body structure with basic order data
        $requestBody = [
            'orderId' => $this->order->getId(),
            'notifyUrl' => $this->order->getNotifyUrl(),
            'returnUrl' => $this->order->getReturnUrl(),
        ];

        // Payment data
        $loanParameters = [
            'amount' => $this->order->getLoanParameters()->getAmount(),
            'term' => $this->order->getLoanParameters()->getTerm(),
            'type' => $this->order->getLoanParameters()->getType(),
            'allowedProductTypes' => $this->order->getLoanParameters()->getAllowedProductTypes(),
        ];

        // Customer data (mandatory)
        $customer = [
            'firstName' => $this->order->getCustomer()->getFirstName(),
            'lastName' => $this->order->getCustomer()->getLastName(),
            'email' => $this->order->getCustomer()->getEmail(),
            'phoneNumber' => $this->order->getCustomer()->getPhoneNumber(),
            'taxId' => $this->order->getCustomer()->getTaxId(),
            'ip' => $this->order->getCustomer()->getIp(),
            'regular' => $this->order->getCustomer()->isRegular(),
            'logged' => $this->order->getCustomer()->isLogged(),

            // Customer address (optional)
            'address' => count(
                $address = array_filter(
                    [
                        'street' => $this->order->getCustomer()->getAddress()?->getStreet(),
                        'buildingNumber' => $this->order->getCustomer()->getAddress()?->getBuildingNumber(),
                        'apartmentNumber' => $this->order->getCustomer()->getAddress()?->getApartmentNumber(),
                        'postalCode' => $this->order->getCustomer()->getAddress()?->getPostalCode(),
                        'city' => $this->order->getCustomer()->getAddress()?->getCity(),
                        'countryCode' => $this->order->getCustomer()->getAddress()?->getCountryCode(),
                    ],
                    static fn ($value): bool => $value !== null
                )
            ) ? $address : null,
        ];

        // Seller data (optional)
        $seller = array_filter(
            ['taxId' => $this->order->getSeller()?->getTaxId()],
            static fn ($value): bool => $value !== null
        );

        $requestBody['loanParameters'] = array_filter($loanParameters, static fn ($value): bool => $value !== null);
        $requestBody['cart'] = $this->getCartAsArray($this->order->getCart());
        $requestBody['customer'] = array_filter($customer, static fn ($value): bool => $value !== null);
        $requestBody['seller'] = !empty($seller) ? $seller : null;

        // Extra data (optional)
        $requestBody['accountNumber'] = $this->order->getAccountNumber();
        $requestBody['transferTitle'] = $this->order->getTransferTitle();
        $requestBody['simulation'] = $this->validateOnly ?: null;

        $this->preparedRequestBody = array_filter($requestBody, static fn ($value): bool => $value !== null);

        return $this->preparedRequestBody;
    }

    /**
     * Generates an SHA-3 hash for the given data.
     *
     * @param array<string, mixed> $data Data to hash
     *
     * @return string Hashed data or empty string on error
     */
    private function generateHash(array $data): string
    {
        try {
            return hash('sha3-256', json_encode($data, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
        } catch (JsonException) {
            return '';
        }
    }
}
