# Request Signing & Authentication

The ComfinoPay REST API requires cryptographic request signing for order creation to ensure data integrity and prevent tampering. This document explains the signing mechanism and how the client handles it automatically.

## Overview

When you create or validate an order, the client automatically computes three security headers:

1. **`Comfino-Cart-Hash`** — SHA3-256 hash of the prepared cart data
2. **`Comfino-Customer-Hash`** — SHA3-256 hash of the prepared customer data
3. **`Comfino-Order-Signature`** — SHA3-256 hash of the two hashes above concatenated with the API key

These headers allow the server to verify that:
- Cart and customer data hasn't been tampered with in transit
- The request originates from an authenticated shop (only the shop knows the API key)

## How it works

### Setup

```php
use Comfino\Api\Client;
use Nyholm\Psr7\Factory\Psr17Factory;
use Sunrise\Http\Client\Curl\Client as CurlClient;

$psr17Factory = new Psr17Factory();

$client = new Client(
    httpClient: new CurlClient($psr17Factory),
    requestFactory: $psr17Factory,
    streamFactory: $psr17Factory,
    apiKey: 'your-api-key',
);
```

### Creating an order (signing is automatic)

The library ships no concrete `Order`/`Cart`/`Customer` classes to instantiate — `src/Shop/Order/` only defines
`OrderInterface`, `CartInterface`, `CustomerInterface` and friends. A shop plugin implements these over its own
order/cart/customer entities and hands the result to `createOrder()`:

```php
use Comfino\Api\Exception\RequestValidationError;

// $order implements Comfino\Shop\Order\OrderInterface (getCart(), getCustomer(), getLoanParameters(), ...).
try {
    $response = $client->createOrder($order);
    $applicationUrl = $response->applicationUrl;
} catch (RequestValidationError $e) {
    // Cart or customer data didn't pass validation.
}
```

The client computes and injects the three security headers itself — nothing above is signing-specific.

### Understanding the signatures

**Cart Hash:**
```
SHA3-256(JSON-encoded prepared cart array)
```

**Customer Hash:**
```
SHA3-256(JSON-encoded prepared customer array)
```

Both hashes are computed from the arrays the request body is actually built from — `CreateOrder::prepareRequestBody()`'s
`cart` and `customer` entries (the cart array comes from `CartTrait::getCartAsArray()`) — not from calling
`json_encode()` directly on `$order->getCart()`/`$order->getCustomer()`. `json_encode()` is called with
`JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION`.

**Order Signature:**
```
SHA3-256(cartHash . customerHash . apiKey)
```

The two hex hashes are concatenated with the API key appended (no separators) and hashed once more with plain
SHA3-256 — **not** an HMAC. This still proves the shop (who possesses the API key) authorized the order, since only
the key holder can produce a signature that matches.

## Implementation details

### Request class flow

`CreateOrder` extends `Request`, takes the API key as a constructor argument (it is never stored on the request
beyond signing), and computes the headers right there — the real shape, from `src/Api/Request/CreateOrder.php`:

```php
class CreateOrder extends Request
{
    use CartTrait;

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

    private function generateHash(array $data): string
    {
        return hash('sha3-256', json_encode($data, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    }
}
```

### Cryptographic requirements

- **Algorithm:** SHA3-256 via PHP's built-in `hash()` (bundled `ext-hash`) — a plain hash, not an HMAC.
- **Integrity:** Prevents man-in-the-middle attacks (attacker cannot modify cart data without knowing the API key).
- **Authentication:** Proves the request came from the shop (API key holder), since the signature folds the key in.
- **Timing safety:** Not applicable on the client side — nothing here is compared against a secret locally; the
  server does the comparison on its end (see [webhook-verification.md](./webhook-verification.md) for the direction
  where this client *does* compare against a secret it received).

## Edge cases and best practices

### Serialization consistency

Hashes must match on both client and server, so serialization must be **byte-for-byte identical**.

The client uses:
```php
json_encode($data, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
```

Ensure your `CartInterface`/`CustomerInterface` implementations expose stable, final values — `CartTrait::getCartAsArray()`
(used by `CreateOrder`) handles turning the cart side into the array that gets hashed; your job is to make sure
`getCart()`/`getCustomer()` return consistent data on every call for the same order.

### Hidden fields and data validation

Do **not** mutate your `CartInterface`/`CustomerInterface` implementation between preparing the order and calling
`createOrder()` — any change after the headers are computed makes the hash the server recomputes disagree with the
one already sent:

```php
// BAD — order object is mutated after being handed to createOrder(), racing the hash computation
// against whatever else holds a reference to the same cart/customer instance.
$client->createOrder($order);
$order->getCart()->setExtraField('something'); // Too late to matter here, but a sign the object isn't final.
```

Build the cart and customer with all their data already final before the first call that touches signing.

### API key rotation

If you rotate API keys:

1. Generate a new key in the ComfinoPay dashboard.
2. Update your shop's configuration.
3. Incoming requests with the old key are rejected server-side.

The client doesn't cache API keys — each request uses the currently configured key.

### Sandbox vs. Production

Use different API keys for sandbox and production:

```php
// Development
$sandboxClient = new Client(
    httpClient: new CurlClient($psr17Factory),
    requestFactory: $psr17Factory,
    streamFactory: $psr17Factory,
    apiKey: 'sandbox_key_...',
);
$sandboxClient->enableSandboxMode();

// Production
$prodClient = new Client(
    httpClient: new CurlClient($psr17Factory),
    requestFactory: $psr17Factory,
    streamFactory: $psr17Factory,
    apiKey: 'live_key_...',
);
$prodClient->disableSandboxMode();
```

The signing algorithm is identical; only the API key and endpoint URL differ.

## Webhook signature verification

For the opposite direction (server to client), see [webhook-verification.md](./webhook-verification.md) — the server sends a `CR-Signature` header that you verify using `WebhookSignatureVerifier`.

## Testing

When testing order creation, mock `OrderInterface` (and the cart/customer/loan-parameters interfaces it returns)
rather than hand-rolling fixtures — see `createMockOrder()` in
`tests/Unit/Api/Request/RequestBuildingTest.php` for the pattern this library's own test suite uses:

```php
$order = $this->createMock(OrderInterface::class);
// ... stub getCart(), getCustomer(), getLoanParameters(), etc.

$psrRequest = $this->build(new CreateOrder($order, 'api-key'));
// Hash/signature computation is exercised by the existing request-building tests; focus new tests on business logic.
```

## Troubleshooting

### "Cart hash mismatch" (400 Bad Request)

The server computed a different cart hash than your client sent. Likely causes:

1. **Cart data changed after request preparation** — Ensure all data is finalized before calling `createOrder()`.
2. **JSON serialization differs** — Verify your `CartInterface` implementation's fields match what the server expects.
3. **API key mismatch** — Confirm you're using the correct sandbox/production key.

**Fix:**
```php
// Verify the order data before sending (CartTrait::getCartAsArray() is protected on Request subclasses, so inspect the cart's own getters directly here).
var_dump($order->getCart());

// Check API key configuration.
echo $client->getApiKey(); // Should not be empty/default.
```

### "Request signature invalid" (401 Unauthorized)

The server couldn't verify the signature. Possible causes:

1. **API key changed mid-request** — Don't update the client's API key between request preparation and sending.
2. **Request body modified in transit** — Rare; indicates network layer tampering or a middleware issue.

**Fix:** Check that your HTTP client isn't modifying request headers or body.

## References

- [hash() documentation](https://www.php.net/manual/en/function.hash.php) — order signing uses this, not `ext-sodium`
  (sodium is used elsewhere in this library, e.g. the paywall auth token)
- [ComfinoPay API documentation](https://developers.comfino.pl/docs/api) — Server-side signature validation details
