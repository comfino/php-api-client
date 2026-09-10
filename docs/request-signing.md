# Request Signing & Authentication

The ComfinoPay REST API requires cryptographic request signing for order creation to ensure data integrity and prevent tampering. This document explains the signing mechanism and how the client handles it automatically.

## Overview

When you create or validate an order, the client automatically computes three security headers:

1. **`Comfino-Cart-Hash`** — SHA3-256 hash of cart data
2. **`Comfino-Customer-Hash`** — SHA3-256 hash of customer data
3. **`Comfino-Order-Signature`** — SHA3-256 HMAC of cart + customer hashes

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

```php
use Comfino\Api\Request\CreateOrder;
use Comfino\Shop\Order\Order;
use Comfino\Shop\Order\Cart;
use Comfino\Shop\Order\Customer;

$order = new Order(
    orderId: '12345',
    cart: new Cart(...), // See below
    customer: new Customer(...), // See below
);

// The client automatically computes and injects the three security headers.
try {
    $response = $client->createOrder($order);
    // Order created successfully.
} catch (RequestValidationError $e) {
    // Cart or customer data didn't pass validation.
}
```

### Understanding the signatures

**Cart Hash:**
```
SHA3-256(JSON-serialized cart data)
```

The cart is serialized to JSON (PSR-12 style, consistent), then hashed with SHA3-256. Example:

```
{
  "description": "Wireless headphones x2",
  "totalAmount": 19999,
  "deliveryAddress": { ... },
  "itemsCount": 2
}
```

**Customer Hash:**
```
SHA3-256(JSON-serialized customer data)
```

Similarly for customer.

**Order Signature:**
```
SHA3-256_HMAC(cartHash + customerHash, apiKey)
```

The two hashes are concatenated (no separator), then an HMAC-SHA3-256 is computed with your API key as the secret. This proves the shop (who possesses the API key) authorized the order.

## Implementation details

### Request class flow

`CreateOrder` extends `Request` and implements the signing logic in its constructor:

```php
class CreateOrder extends Request
{
    public function __construct(OrderInterface $order)
    {
        parent::__construct(
            method: 'POST',
            path: '/orders',
            body: $order // Serialized to JSON in base Request.
        );

        // Compute hashes from the order's cart and customer.
        $cartHash = hash('sha3-256', json_encode($order->getCart(), self::JSON_FLAGS));
        $customerHash = hash('sha3-256', json_encode($order->getCustomer(), self::JSON_FLAGS));

        // Combine hashes and compute signature.
        $signature = hash_hmac(
            'sha3-256',
            $cartHash . $customerHash,
            $apiKey, // Passed from client
            false // Return hex string
        );

        // Inject security headers.
        $this->setHeader('Comfino-Cart-Hash', $cartHash);
        $this->setHeader('Comfino-Customer-Hash', $customerHash);
        $this->setHeader('Comfino-Order-Signature', $signature);
    }
}
```

### Cryptographic requirements

- **Algorithm:** SHA3-256 (requires `ext-sodium` or `ext-hash`).
- **Integrity:** Prevents man-in-the-middle attacks (attacker cannot modify cart data without knowing the API key).
- **Authentication:** Proves the request came from the shop (API key holder).
- **Timing safety:** Not applicable here; server uses simple comparison (no secrets are verified by client).

## Edge cases and best practices

### Serialization consistency

Hashes must match on both client and server, so serialization must be **byte-for-byte identical**.

The client uses:
```php
json_encode(
    $data,
    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK
);
```

Ensure your `Cart` and `Customer` implementations serialize to the same JSON format. The `CartTrait` provided in this library handles this automatically.

### Hidden fields and data validation

Do **not** add extra fields to `Cart` or `Customer` objects at runtime:

```php
// BAD — modifies data after serialization.
$cart = new Cart(...);
$cart->extraField = 'something'; // This will hash differently!
```

Instead, pass all data in the constructor:

```php
// GOOD — all data known upfront.
$cart = new Cart(
    description: 'Item 1 + Item 2',
    totalAmount: 9999,
    // ... other fields
);
```

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

When testing order creation, use fixtures with known hashes:

```php
use Comfino\Tests\Fixture\OrderFixture;

$order = OrderFixture::createOrder();
// Hash computation is tested separately; focus on business logic.
```

## Troubleshooting

### "Cart hash mismatch" (400 Bad Request)

The server computed a different cart hash than your client sent. Likely causes:

1. **Cart data changed after request preparation** — Ensure all data is finalized before calling `createOrder()`.
2. **JSON serialization differs** — Verify `Cart::jsonSerialize()` matches the expected format.
3. **API key mismatch** — Confirm you're using the correct sandbox/production key.

**Fix:**
```php
// Verify the order data before sending.
echo json_encode($order->getCart(), JSON_PRETTY_PRINT);

// Check API key configuration.
echo $client->getApiKey(); // Should not be empty/default.
```

### "Request signature invalid" (401 Unauthorized)

The server couldn't verify the HMAC signature. Possible causes:

1. **API key changed mid-request** — Don't update the client's API key between request preparation and sending.
2. **Request body modified in transit** — Rare; indicates network layer tampering or a middleware issue.

**Fix:** Check that your HTTP client isn't modifying request headers or body.

## References

- [Sodium extension documentation](https://www.php.net/manual/en/book.sodium.php) — for cryptographic operations
- [ComfinoPay API documentation](https://developers.comfino.pl/docs/api) — Server-side signature validation details
