<?php
/**
 * pay.php — create an invoice and send the buyer to the hosted checkout.
 * Put this next to orizen.php + orizen-config.php, then link customers to it.
 */

require __DIR__ . '/orizen.php';
$cfg = require __DIR__ . '/orizen-config.php';

$orizen = new Orizen($cfg['baseUrl'], $cfg['apiKey'], $cfg['apiSecret']);

// Create an invoice for a specific amount (fiat price → the buyer picks the coin at checkout).
$invoice = $orizen->request('POST', '/api/v1/merchant/invoices', [
    'fiatAmount'   => '19.99',
    'fiatCurrency' => 'USD',
    'assetCode'    => 'BTC',            // BTC, LTC, ETH, XRP, USDT_TRC20, USDC_ERC20
    'orderId'      => 'ORDER-' . time(),
    'description'  => 'Order from my store',
    // 'redirectUrl' => 'https://yoursite.com/thank-you',   // optional: where to send the buyer after paying
]);

// Send the customer to the hosted, mobile-friendly checkout page.
header('Location: ' . $invoice['checkoutUrl']);
exit;
