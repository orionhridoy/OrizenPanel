# Orizen Pay — integration samples

Everything you need to accept crypto on your own site. Drop these files into the **same folder** on your server (your web root).

| File | What it is |
|---|---|
| [`orizen.php`](orizen.php) | PHP server SDK — signs every API request for you. |
| [`orizen.js`](orizen.js) | Node server SDK — same, for Express/Node apps. |
| [`orizen-config.php`](orizen-config.php) | Your keys/secret — **keep private**, never commit. |
| [`orizen-webhook.php`](orizen-webhook.php) | Receives + verifies payment events (`invoice.paid`, …). |
| [`pay.php`](pay.php) | Creates an invoice and redirects the buyer to hosted checkout. |

## Quick start

1. In your gateway, open **API Keys → create a key** and copy the **key + secret** (the secret is shown once).
2. Open **Webhooks → add** the public URL of `orizen-webhook.php`, and copy the `whsec_…` signing secret.
3. Fill both into `orizen-config.php`.
4. Link customers to `pay.php` (or build your own using the SDK).

## Request signing

Every request carries three headers (the SDK adds them for you):

```
X-API-KEY:    orz_live_...
X-TIMESTAMP:  <unix-ms>
X-SIGNATURE:  HMAC-SHA256(secret, "timestamp.METHOD.path.sha256(body)")
```

## Webhook verification

Verify on the **raw** body before trusting an event:

```php
Orizen::verifyWebhook($rawBody, $_SERVER['HTTP_X_ORIZEN_SIGNATURE'], $_SERVER['HTTP_X_ORIZEN_TIMESTAMP'], $webhookSecret);
```

Events arrive with `X-Orizen-Event`, `X-Orizen-Timestamp`, `X-Orizen-Signature`. Return HTTP 200 quickly; non‑2xx responses are retried with exponential backoff.
