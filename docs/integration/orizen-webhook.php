<?php
/**
 * orizen-webhook.php — receives payment events from your Orizen gateway.
 *
 * 1) Put this file, orizen.php and orizen-config.php in the same folder on your server.
 * 2) On the gateway's Webhooks page, add this file's public URL (e.g. https://yoursite.com/orizen-webhook.php).
 * 3) Copy the whsec_... secret it shows into orizen-config.php as webhookSecret.
 *
 * ALWAYS verify the signature on the RAW body before trusting an event.
 */

require __DIR__ . '/orizen.php';
$cfg = require __DIR__ . '/orizen-config.php';

$raw = file_get_contents('php://input');
$ok  = Orizen::verifyWebhook(
    $raw,
    $_SERVER['HTTP_X_ORIZEN_SIGNATURE']  ?? '',
    $_SERVER['HTTP_X_ORIZEN_TIMESTAMP']  ?? '',
    $cfg['webhookSecret']
);

if (!$ok) {
    http_response_code(400);
    exit('bad signature');
}

$event = json_decode($raw, true);

switch ($event['type'] ?? '') {
    case 'invoice.paid':
        // The invoice is fully paid & confirmed. Mark the order paid, grant access, etc.
        // $event['data'] holds the invoice (id, orderId, asset, amount, ...).
        // error_log('PAID: ' . ($event['data']['orderId'] ?? ''));
        break;

    case 'invoice.underpaid':
    case 'invoice.expired':
        // Handle a short / expired payment (notify the customer, reopen the cart, ...).
        break;

    case 'withdrawal.confirmed':
        // A payout you requested has confirmed on-chain.
        break;
}

// ACK fast. Any non-2xx response is retried by the gateway with exponential backoff.
http_response_code(200);
echo 'ok';
