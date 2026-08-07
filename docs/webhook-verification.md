# Webhook Signature Verification

Comfino sends webhook requests to your shop to notify about order status changes and other events. All webhooks are signed with a CR-Signature header to prove they originate from Comfino and haven't been tampered with.

This document explains how to verify webhook signatures using the `WebhookSignatureVerifier` class provided by the API client.

## Overview

When Comfino sends a webhook to your endpoint:

1. The server computes a CR-Signature header:
   ```
   CR-Signature: SHA3-256_HMAC(requestBody, apiKey)
   ```

2. Your shop receives the request and verifies the signature using the same API key.

3. If the signature matches, you can trust the request came from Comfino.

## Setup

### Create a verifier instance

```php
use Comfino\Api\WebhookSignatureVerifier;

$verifier = new WebhookSignatureVerifier(
    apiKey: 'your-api-key' // Same key used for API requests.
);
```

### Verify an incoming request

```php
// Get the raw POST body (not parsed JSON, but raw bytes).
$rawBody = file_get_contents('php://input');

// Get the signature header.
$signature = $_SERVER['HTTP_CR_SIGNATURE'] ?? null;

// Verify.
try {
    $verified = $verifier->verifySignature(
        requestBody: $rawBody,
        signature: $signature
    );

    if ($verified) {
        // Signature is valid — parse and process the webhook.
        $payload = json_decode($rawBody, associative: true);
        handleWebhook($payload);
    } else {
        // Signature is invalid — reject the request.
        http_response_code(403);
        exit('Forbidden');
    }
} catch (InvalidArgumentException $e) {
    // Missing or malformed signature header.
    http_response_code(400);
    exit('Bad Request');
}
```

## How verification works

The verifier uses **timing-safe comparison** to prevent timing attacks:

```php
public function verifySignature(string $requestBody, ?string $signature): bool
{
    // Compute the expected signature.
    $expected = hash_hmac('sha3-256', $requestBody, $this->apiKey, false);

    // Compare using hash_equals() — O(n) time, constant-time comparison.
    return hash_equals($expected, $signature ?? '');
}
```

**Timing-safe comparison** means the comparison time is independent of where the signatures differ. This prevents attackers from using timing measurements to guess the correct signature byte-by-byte.

## Implementation patterns

### Framework integration (PSR-7)

If your framework provides PSR-7 request objects:

```php
use Psr\Http\Message\ServerRequestInterface;

function handleComfinoWebhook(ServerRequestInterface $request)
{
    // Get raw body.
    $body = (string) $request->getBody();

    // Get signature header (case-insensitive).
    $signature = $request->getHeaderLine('CR-Signature');

    // Verify.
    $verifier = new WebhookSignatureVerifier('your-api-key');
    if (!$verifier->verifySignature($body, $signature)) {
        return $response->withStatus(403);
    }

    // Parse and process.
    $payload = json_decode($body, associative: true);
    // ... process payload ...

    return $response->withStatus(200);
}
```

### Multiple API keys (sandbox + production)

If you run both sandbox and production endpoints on the same server:

```php
$verifyWithMultipleKeys = function(string $body, string $signature, array $apiKeys): bool {
    foreach ($apiKeys as $key) {
        $verifier = new WebhookSignatureVerifier($key);
        if ($verifier->verifySignature($body, $signature)) {
            return true; // Matched one of the keys.
        }
    }
    return false;
};

$apiKeys = [
    'sandbox' => 'sandbox_key_...',
    'production' => 'live_key_...',
];

$verified = $verifyWithMultipleKeys(
    $rawBody,
    $signatureHeader,
    array_values($apiKeys)
);

if (!$verified) {
    http_response_code(403);
    exit('Forbidden');
}
```

## Edge cases and best practices

### Use raw body, not parsed JSON

**Incorrect:**
```php
// WRONG — JSON parsing re-encodes, changing byte sequence.
$parsed = json_decode(file_get_contents('php://input'), assoc: true);
$rawBody = json_encode($parsed); // Different bytes = different hash!
```

**Correct:**
```php
// RIGHT — Use the exact bytes received.
$rawBody = file_get_contents('php://input');
$verifier->verifySignature($rawBody, $signature); // Verifies against original bytes.

// Parse only after verification.
$payload = json_decode($rawBody, assoc: true);
```

### Handle missing signature header

Always check that the signature header is present:

```php
$signature = $_SERVER['HTTP_CR_SIGNATURE'] ?? null;

if ($signature === null) {
    // Reject unsigned requests.
    http_response_code(400);
    exit('Missing CR-Signature header');
}

$verified = $verifier->verifySignature($rawBody, $signature);
```

### Logging

Log webhook events for debugging:

```php
// Log the attempt (before verification for diagnostics).
error_log("Webhook received: {$_SERVER['REQUEST_METHOD']} {$_SERVER['REQUEST_URI']}");

if ($verified) {
    error_log("Webhook verified: " . substr($signature, 0, 16) . '...');
    // Process webhook.
} else {
    error_log("Webhook verification FAILED. Expected signature for body hash: " .
        hash('sha3-256', $rawBody));
}
```

### Replay attack prevention

Webhook signatures alone don't prevent replay attacks (same valid request sent multiple times). For protection, maintain a list of processed signature hashes:

```php
// After successful verification.
$signatureHash = md5($signature); // Or store the signature itself.

// Check if we've already processed this.
if ($processedSignatures[$signatureHash] ?? false) {
    http_response_code(200); // Idempotent: return success but don't reprocess.
    exit;
}

// First time seeing this signature — process it.
$processedSignatures[$signatureHash] = true;
processWebhook($payload);
```

Store processed signatures with a TTL (e.g., 24 hours) in Redis or a database:

```php
$redis = new Redis();
$signatureKey = "webhook:sig:" . $signature;

if ($redis->exists($signatureKey)) {
    // Already processed.
    http_response_code(200);
    exit;
}

// Process the webhook.
processWebhook($payload);

// Mark as processed for the next 24 hours.
$redis->setex($signatureKey, 86400, true);
```

### Webhook timeout handling

Webhooks should be processed quickly. If your endpoint takes longer than ~30 seconds, Comfino may timeout and retry. For long-running tasks:

```php
// 1. Verify and queue the webhook.
if (!$verifier->verifySignature($rawBody, $signature)) {
    http_response_code(403);
    exit;
}

// 2. Acknowledge the receipt immediately.
http_response_code(202); // Accepted

// 3. Queue for background processing (async).
Queue::push(new ProcessComfinoWebhook($payload));
```

## Troubleshooting

### "Signature verification failed"

Possible causes:

1. **Wrong API key** — Ensure you're using the correct sandbox/production key.
2. **Body was modified** — Check that no middleware (compression, encoding) altered the request body.
3. **Signature header case** — The header name is `CR-Signature` (exact case on Comfino's side, but HTTP headers are case-insensitive in most servers).
4. **Body read twice** — If you read `php://input` before verification, the stream is consumed. Read it once and store it.

**Debug:**
```php
// Print what we received.
echo "Body length: " . strlen($rawBody) . "\n";
echo "Signature header: " . $signature . "\n";

// Compute what we expect.
$verifier = new WebhookSignatureVerifier('your-api-key');
$expected = hash_hmac('sha3-256', $rawBody, 'your-api-key', false);
echo "Expected: " . $expected . "\n";
echo "Received: " . $signature . "\n";
echo "Match: " . ($expected === $signature ? 'YES' : 'NO') . "\n";
```

### "Already processed this webhook"

If you're seeing the same webhook multiple times:

1. **Check your deduplication logic** — Ensure the key is unique and TTL is adequate.
2. **Comfino retry logic** — Comfino may retry on timeout or 5xx errors. Your endpoint should be idempotent.
3. **Network issues** — If a request times out but actually succeeds server-side, Comfino won't retry.

## Security considerations

- **Never log the API key** — Don't include it in error messages or logs.
- **Never expose the signature** — Don't return it in responses or logs (except for debugging in private logs).
- **Use HTTPS** — Always receive webhooks over HTTPS; unencrypted HTTP can leak the body and signature.
- **Validate the signature first** — Verify before parsing or processing the payload.
- **Implement replay protection** — Store processed signatures to prevent accidental re-processing.

## References

- [hash_hmac() documentation](https://www.php.net/manual/en/function.hash-hmac.php)
- [hash_equals() documentation](https://www.php.net/manual/en/function.hash-equals.php) — Timing-safe comparison
- [WebhookSignatureVerifier source](../src/Api/WebhookSignatureVerifier.php)
