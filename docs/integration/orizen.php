<?php
/**
 * Orizen Pay - PHP server SDK (PHP 7.4+, uses curl).
 *
 * Put this on YOUR store's server. Signs every request with your API key + secret.
 *
 *   require 'orizen.php';
 *   $nvx = new Orizen(
 *     'https://your-gateway-domain',   // baseUrl
 *     getenv('ORIZEN_API_KEY'),         // orz_live_...
 *     getenv('ORIZEN_API_SECRET')       // shown once at key creation
 *   );
 */

class OrizenException extends Exception {}

class Orizen {
    private $baseUrl;
    private $apiKey;
    private $apiSecret;

    public function __construct($baseUrl, $apiKey, $apiSecret) {
        if (!$baseUrl || !$apiKey || !$apiSecret) {
            throw new InvalidArgumentException('baseUrl, apiKey and apiSecret are required');
        }
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->apiKey = $apiKey;
        $this->apiSecret = $apiSecret;
    }

    public function request($method, $path, $bodyObj = null) {
        $body = $bodyObj === null ? '' : json_encode($bodyObj, JSON_UNESCAPED_SLASHES);
        $timestamp = (string) round(microtime(true) * 1000);
        $bodyHash = hash('sha256', $body);
        $canonical = "$timestamp." . strtoupper($method) . ".$path.$bodyHash";
        $signature = hash_hmac('sha256', $canonical, $this->apiSecret);

        $ch = curl_init($this->baseUrl . $path);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                "X-API-KEY: {$this->apiKey}",
                "X-TIMESTAMP: {$timestamp}",
                "X-SIGNATURE: {$signature}",
                'Content-Type: application/json',
            ],
        ]);
        if ($body !== '') curl_setopt($ch, CURLOPT_POSTFIELDS, $body);

        $resp = curl_exec($ch);
        if ($resp === false) throw new OrizenException('network error: ' . curl_error($ch));
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $data = $resp ? json_decode($resp, true) : null;
        if ($status < 200 || $status >= 300) {
            $msg = isset($data['error']['message']) ? $data['error']['message'] : "request failed ($status)";
            throw new OrizenException(is_array($msg) ? implode(', ', $msg) : $msg, $status);
        }
        return $data;
    }

    /** Create a hosted top-up session; redirect the customer to $result['url']. */
    public function createStoreSession($externalRef, $displayName = null, $email = null) {
        return $this->request('POST', '/api/v1/merchant/store/sessions', [
            'externalRef' => $externalRef, 'displayName' => $displayName, 'email' => $email,
        ]);
    }

    /** Charge a customer's balance when they buy a tool. */
    public function charge($externalRef, $assetCode, $amount, $description = null, $idempotencyKey = null) {
        return $this->request('POST', '/api/v1/merchant/store/purchase', [
            'externalRef' => $externalRef, 'assetCode' => $assetCode, 'amount' => $amount,
            'description' => $description, 'idempotencyKey' => $idempotencyKey,
        ]);
    }

    public function getStoreBalances($externalRef) {
        return $this->request('GET', '/api/v1/merchant/store/users/' . rawurlencode($externalRef) . '/balances');
    }

    public function createInvoice($assetCode, $amount, $orderId = null, $description = null) {
        return $this->request('POST', '/api/v1/merchant/invoices', [
            'assetCode' => $assetCode, 'amount' => $amount, 'orderId' => $orderId, 'description' => $description,
        ]);
    }

    /** Verify a webhook. Pass the RAW POST body and the two X-Orizen-* headers. */
    public static function verifyWebhook($rawBody, $signatureHeader, $timestampHeader, $endpointSecret) {
        if (!$signatureHeader || !$timestampHeader) return false;
        $provided = preg_replace('/^v1=/', '', $signatureHeader);
        $expected = hash_hmac('sha256', "$timestampHeader.$rawBody", $endpointSecret);
        return hash_equals($expected, $provided);
    }
}
