<?php
/**
 * orizen-config.php — keep this file PRIVATE (never commit it, never expose it in the browser).
 * Fill in the values from your gateway: API Keys page (key + secret) and Webhooks page (signing secret).
 */
return [
    // Your gateway URL, e.g. https://pay.yoursite.com
    'baseUrl'       => 'https://YOUR-GATEWAY-DOMAIN',

    // API Keys page → create a key. Copy BOTH — the secret is shown only once.
    'apiKey'        => 'orz_live_xxxxxxxxxxxxxxxxxxxxxxxx',
    'apiSecret'     => 'PASTE_YOUR_API_SECRET',

    // Webhooks page → add an endpoint → copy its whsec_... signing secret.
    'webhookSecret' => 'whsec_xxxxxxxxxxxxxxxxxxxxxxxx',
];
