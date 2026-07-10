import { FormEvent, useState } from 'react';
import { Link } from 'react-router-dom';
import { api } from '../api/client';
import { HelpButton } from '../components/Help';

/** The PHP server SDK, shipped verbatim so "Download orizen.php" gives the real file. */
const SDK_PHP = `<?php
/**
 * Orizen Pay - PHP server SDK (PHP 7.4+, uses curl).
 * Put this on YOUR server. Signs every request with your API key + secret.
 */
class OrizenException extends Exception {}

class Orizen {
    private $baseUrl; private $apiKey; private $apiSecret;
    public function __construct($baseUrl, $apiKey, $apiSecret) {
        if (!$baseUrl || !$apiKey || !$apiSecret) throw new InvalidArgumentException('baseUrl, apiKey and apiSecret are required');
        $this->baseUrl = rtrim($baseUrl, '/'); $this->apiKey = $apiKey; $this->apiSecret = $apiSecret;
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
                "X-API-KEY: {$this->apiKey}", "X-TIMESTAMP: {$timestamp}",
                "X-SIGNATURE: {$signature}", 'Content-Type: application/json',
            ],
        ]);
        if ($body !== '') curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        $resp = curl_exec($ch);
        if ($resp === false) throw new OrizenException('network error: ' . curl_error($ch));
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        $data = $resp ? json_decode($resp, true) : null;
        if ($status < 200 || $status >= 300) {
            $msg = isset($data['error']['message']) ? $data['error']['message'] : "request failed ($status)";
            throw new OrizenException(is_array($msg) ? implode(', ', $msg) : $msg, $status);
        }
        return $data;
    }
    public function createStoreSession($externalRef, $displayName = null, $email = null) {
        return $this->request('POST', '/api/v1/merchant/store/sessions', ['externalRef'=>$externalRef,'displayName'=>$displayName,'email'=>$email]);
    }
    public function charge($externalRef, $assetCode, $amount, $description = null, $idempotencyKey = null) {
        return $this->request('POST', '/api/v1/merchant/store/purchase', ['externalRef'=>$externalRef,'assetCode'=>$assetCode,'amount'=>$amount,'description'=>$description,'idempotencyKey'=>$idempotencyKey]);
    }
    public function getStoreBalances($externalRef) {
        return $this->request('GET', '/api/v1/merchant/store/users/' . rawurlencode($externalRef) . '/balances');
    }
    public function createInvoice($assetCode, $amount, $orderId = null, $description = null) {
        return $this->request('POST', '/api/v1/merchant/invoices', ['assetCode'=>$assetCode,'amount'=>$amount,'orderId'=>$orderId,'description'=>$description]);
    }
    public static function verifyWebhook($rawBody, $signatureHeader, $timestampHeader, $endpointSecret) {
        if (!$signatureHeader || !$timestampHeader) return false;
        $provided = preg_replace('/^v1=/', '', $signatureHeader);
        $expected = hash_hmac('sha256', "$timestampHeader.$rawBody", $endpointSecret);
        return hash_equals($expected, $provided);
    }
}
`;

type Goal = 'store' | 'checkout';

interface Result {
  apiKey: string;
  apiSecret: string;
  whsec: string | null;
  webhookUrl: string;
  webhookError?: string;
  storeEnabled: boolean;
  goal: Goal;
}

function triggerDownload(name: string, content: string): void {
  const blob = new Blob([content], { type: 'text/plain;charset=utf-8' });
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = name;
  document.body.appendChild(a);
  a.click();
  a.remove();
  URL.revokeObjectURL(url);
}

export default function EasySetup(): JSX.Element {
  const baseUrl = window.location.origin;
  const [site, setSite] = useState('');
  const [goal, setGoal] = useState<Goal>('store');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [copied, setCopied] = useState('');
  const [result, setResult] = useState<Result | null>(null);

  const copy = (value: string, id: string): void => {
    void navigator.clipboard.writeText(value);
    setCopied(id);
    setTimeout(() => setCopied(''), 1500);
  };

  const normalizeSite = (s: string): string => {
    let u = s.trim().replace(/\/+$/, '');
    if (u && !/^https?:\/\//i.test(u)) u = `https://${u}`;
    return u;
  };

  const generate = async (event: FormEvent): Promise<void> => {
    event.preventDefault();
    setBusy(true);
    setError('');
    setResult(null);
    try {
      const siteUrl = normalizeSite(site);
      const webhookUrl = `${siteUrl}/orizen-webhook.php`;
      const permissions =
        goal === 'store'
          ? ['store:manage', 'invoices:read', 'balances:read']
          : ['invoices:write', 'invoices:read', 'balances:read'];

      // 1) create the API key
      const key = await api<{ apiKey: string; apiSecret: string }>('/dashboard/api-keys', {
        method: 'POST',
        body: { label: site || 'My website', permissions },
      });

      // 2) enable the store when needed
      let storeEnabled = false;
      if (goal === 'store') {
        await api('/dashboard/store/config', { method: 'PATCH', body: { storeEnabled: true } })
          .then(() => { storeEnabled = true; })
          .catch(() => { storeEnabled = false; });
      }

      // 3) register the webhook (best-effort - fails for local/private URLs)
      let whsec: string | null = null;
      let webhookError: string | undefined;
      try {
        const wh = await api<{ secret: string }>('/dashboard/webhooks', {
          method: 'POST',
          body: {
            url: webhookUrl,
            events: ['invoice.paid', 'invoice.underpaid', 'invoice.overpaid'],
          },
        });
        whsec = wh.secret;
      } catch (err) {
        webhookError = (err as Error).message;
      }

      setResult({ apiKey: key.apiKey, apiSecret: key.apiSecret, whsec, webhookUrl, webhookError, storeEnabled, goal });
    } catch (err) {
      setError((err as Error).message);
    } finally {
      setBusy(false);
    }
  };

  // -- generated file contents (creds filled in) ------------------------------
  const files = ((): Array<{ name: string; where: string; code: string; downloadOnly?: boolean }> => {
    if (!result) return [];
    const whsecValue = result.whsec ?? 'whsec_PASTE_FROM_WEBHOOKS_PAGE';
    const configPhp = `<?php
// Orizen Pay credentials for YOUR server. Keep this file private (never in the browser).
return [
    'baseUrl'       => '${baseUrl}',
    'apiKey'        => '${result.apiKey}',
    'apiSecret'     => '${result.apiSecret}',
    'webhookSecret' => '${whsecValue}',
];
`;

    const webhookStore = `<?php
// Receives payment notifications. Orizen Pay POSTs here when a payment is confirmed.
require __DIR__ . '/orizen.php';
$cfg = require __DIR__ . '/orizen-config.php';

$raw = file_get_contents('php://input');
$ok  = Orizen::verifyWebhook(
    $raw,
    $_SERVER['HTTP_X_ORIZEN_SIGNATURE'] ?? '',
    $_SERVER['HTTP_X_ORIZEN_TIMESTAMP'] ?? '',
    $cfg['webhookSecret']
);
if (!$ok) { http_response_code(400); exit('bad signature'); }

$evt  = json_decode($raw, true);
$data = $evt['data'] ?? [];

if (($evt['event'] ?? '') === 'invoice.paid' && ($data['purpose'] ?? '') === 'TOPUP') {
    $userId = $data['storeUserRef'];        // YOUR user id
    $asset  = $data['asset'];
    $amount = $data['amountPaidConfirmed']; // base units
    // The balance is ALREADY added inside the gateway. Reflect it in your app:
    // your_mark_funded($userId, $asset, $amount);
}
http_response_code(200); // reply 2xx fast; non-2xx is retried
`;

    const webhookCheckout = `<?php
// Receives payment notifications. Orizen Pay POSTs here when a payment is confirmed.
require __DIR__ . '/orizen.php';
$cfg = require __DIR__ . '/orizen-config.php';

$raw = file_get_contents('php://input');
$ok  = Orizen::verifyWebhook(
    $raw,
    $_SERVER['HTTP_X_ORIZEN_SIGNATURE'] ?? '',
    $_SERVER['HTTP_X_ORIZEN_TIMESTAMP'] ?? '',
    $cfg['webhookSecret']
);
if (!$ok) { http_response_code(400); exit('bad signature'); }

$evt  = json_decode($raw, true);
$data = $evt['data'] ?? [];

if (($evt['event'] ?? '') === 'invoice.paid') {
    $orderId = $data['orderId'];   // YOUR order id
    // Mark the order paid and fulfil it:
    // your_mark_order_paid($orderId);
}
http_response_code(200); // reply 2xx fast; non-2xx is retried
`;

    const addFunds = `<?php
// "Add funds" - send the customer to the hosted top-up page.
session_start();
require __DIR__ . '/orizen.php';
$cfg = require __DIR__ . '/orizen-config.php';
$nvx = new Orizen($cfg['baseUrl'], $cfg['apiKey'], $cfg['apiSecret']);

// use YOUR logged-in user's id (a guest id is used as a fallback here)
$userId = $_SESSION['user_id'] ?? ('guest_' . bin2hex(random_bytes(4)));

$session = $nvx->createStoreSession($userId);
header('Location: ' . $session['url']);
`;

    const payPhp = `<?php
// Create a checkout for one order and send the buyer to the hosted page.
require __DIR__ . '/orizen.php';
$cfg = require __DIR__ . '/orizen-config.php';
$nvx = new Orizen($cfg['baseUrl'], $cfg['apiKey'], $cfg['apiSecret']);

$order = $_GET['order'] ?? ('order_' . time());
$inv = $nvx->createInvoice('ETH', '0.01', $order, 'Order ' . $order); // price in crypto
header('Location: ' . $inv['checkoutUrl']);
`;

    const common = [
      { name: 'orizen.php', where: 'Your website root (e.g. public_html/). This is the SDK - download as-is.', code: SDK_PHP, downloadOnly: true },
      { name: 'orizen-config.php', where: 'Same folder as orizen.php. Holds your keys - keep it private.', code: configPhp },
    ];
    if (result.goal === 'store') {
      return [
        ...common,
        { name: 'orizen-webhook.php', where: 'Same folder. Must be reachable at ' + result.webhookUrl, code: webhookStore },
        { name: 'add-funds.php', where: 'Same folder. Link your "Add funds" button to /add-funds.php', code: addFunds },
      ];
    }
    return [
      ...common,
      { name: 'orizen-webhook.php', where: 'Same folder. Must be reachable at ' + result.webhookUrl, code: webhookCheckout },
      { name: 'pay.php', where: 'Same folder. Link your "Pay" button to /pay.php?order=YOUR_ORDER_ID', code: payPhp },
    ];
  })();

  const buttonHtml =
    result?.goal === 'store'
      ? `<!-- Paste where you want the button on your site -->
<a href="/add-funds.php" style="display:inline-block;padding:12px 22px;border-radius:10px;
   background:linear-gradient(120deg,#6d5efc,#22d3ee);color:#fff;font:600 15px system-ui;
   text-decoration:none">Add funds with crypto</a>`
      : `<!-- Paste where you want the button on your product/cart page -->
<a href="/pay.php?order=INV-123" style="display:inline-block;padding:12px 22px;border-radius:10px;
   background:linear-gradient(120deg,#6d5efc,#22d3ee);color:#fff;font:600 15px system-ui;
   text-decoration:none">Pay with crypto</a>`;

  const CodeBox = ({ id, code }: { id: string; code: string }): JSX.Element => (
    <div style={{ position: 'relative' }}>
      <button className="secondary" style={{ position: 'absolute', top: 8, right: 8, padding: '4px 10px', fontSize: 12 }}
        onClick={() => copy(code, id)}>{copied === id ? 'Copied ✓' : 'Copy'}</button>
      <div className="codeblock">{code}</div>
    </div>
  );

  return (
    <>
      <h1>Easy Setup<HelpButton topic="easySetup" /></h1>
      <p className="muted" style={{ marginTop: -8 }}>
        Tell us your website. We create your key + webhook and hand you every file - filled in,
        with exactly where to put it. No crypto or signing code to write.
      </p>

      <div className="panel">
        <h2>Your website</h2>
        <form onSubmit={(e) => void generate(e)}>
          <div className="field" style={{ maxWidth: 460 }}>
            <label>Website address</label>
            <input value={site} onChange={(e) => setSite(e.target.value)} placeholder="myshop.com" maxLength={200} required />
          </div>
          <label>What do you want?</label>
          <div className="chips" style={{ marginBottom: 14 }}>
            <span className={`chip ${goal === 'store' ? 'active' : ''}`} onClick={() => setGoal('store')}>🏦 Customers load a balance (top-ups)</span>
            <span className={`chip ${goal === 'checkout' ? 'active' : ''}`} onClick={() => setGoal('checkout')}>🧾 Charge one order (checkout)</span>
          </div>
          {error && <div className="error">{error}</div>}
          <button type="submit" disabled={busy}>
            {busy ? <><span className="spinner" /> Setting everything up...</> : 'Set up my website →'}
          </button>
        </form>
      </div>

      {result && (
        <>
          <div className="panel" style={{ borderColor: 'rgba(52,211,153,.4)' }}>
            <h2>✅ Done - here's your setup</h2>
            <ul style={{ margin: '6px 0 0', paddingLeft: 20, lineHeight: 1.9 }}>
              <li>API key created (already scoped for what you need).</li>
              {result.goal === 'store' && <li>{result.storeEnabled ? 'Store enabled ✓' : 'Enable the store under Store (couldn’t auto-enable).'}</li>}
              <li>
                {result.whsec
                  ? <>Webhook registered ✓ at <span className="mono">{result.webhookUrl}</span></>
                  : <>Webhook <b>not</b> auto-registered (site not publicly reachable yet) - add it manually, see step ②.</>}
              </li>
            </ul>
          </div>

          <div className="panel">
            <h2>① Put these files on your website</h2>
            <p className="muted" style={{ marginTop: -6 }}>Upload all of them into the <b>same folder</b> (your site root). Copy or download each:</p>
            {files.map((f, i) => (
              <div key={f.name} style={{ marginTop: i ? 20 : 12 }}>
                <div style={{ display: 'flex', alignItems: 'center', gap: 10, flexWrap: 'wrap' }}>
                  <span className="mono" style={{ fontWeight: 700 }}>{f.name}</span>
                  <button className="ghost" style={{ fontSize: 12 }} onClick={() => triggerDownload(f.name, f.code)}>⬇ Download</button>
                </div>
                <div className="faint" style={{ fontSize: 12, margin: '4px 0 6px' }}>📁 {f.where}</div>
                {f.downloadOnly
                  ? <div className="help-tip" style={{ marginTop: 4 }}>The SDK - just download it, no edits needed.</div>
                  : <CodeBox id={`file-${i}`} code={f.code} />}
              </div>
            ))}
            <div className="secret-box" style={{ marginTop: 18 }}>
              <strong>Heads up:</strong> <span className="mono">orizen-config.php</span> holds your API secret{result.whsec ? ' and webhook secret' : ''}.
              Keep it on your server only - never commit it publicly or expose it in the browser.
            </div>
          </div>

          <div className="panel">
            <h2>② Your webhook</h2>
            {result.whsec ? (
              <p>
                Already registered ✓ - Orizen Pay will POST payment events to{' '}
                <span className="mono">{result.webhookUrl}</span>. It's wired into your{' '}
                <span className="mono">orizen-webhook.php</span> automatically. Manage it on the{' '}
                <Link to="/webhooks">Webhooks</Link> page.
              </p>
            ) : (
              <>
                <p>We couldn't auto-register it{result.webhookError ? ` (${result.webhookError})` : ''} - likely because the site isn't live/public yet. Once it is:</p>
                <ol style={{ lineHeight: 1.9 }}>
                  <li>Go to <Link to="/webhooks">Webhooks</Link> → add this URL: <span className="mono">{result.webhookUrl}</span></li>
                  <li>Copy the <span className="mono">whsec_...</span> secret it shows (once).</li>
                  <li>Paste it into <span className="mono">orizen-config.php</span> as <span className="mono">webhookSecret</span>.</li>
                </ol>
              </>
            )}
          </div>

          <div className="panel">
            <h2>③ Add the button to your site</h2>
            <p className="muted" style={{ marginTop: -6 }}>Paste this HTML wherever you want customers to {result.goal === 'store' ? 'add funds' : 'pay'}:</p>
            <CodeBox id="btn" code={buttonHtml} />
          </div>

          <div className="panel">
            <h2>④ Test it</h2>
            <ul className="chk" style={{ listStyle: 'none', paddingLeft: 0, lineHeight: 2 }}>
              <li>✓ Upload the files, then open <span className="mono">{result.webhookUrl.replace('/orizen-webhook.php', result.goal === 'store' ? '/add-funds.php' : '/pay.php')}</span> - it should redirect to a hosted checkout.</li>
              <li>✓ Or create a test invoice right here and watch it live: <Link to="/invoices">Invoices → New invoice</Link>.</li>
              <li>✓ Send a tiny amount; your <span className="mono">orizen-webhook.php</span> fires the moment it's paid.</li>
            </ul>
            <p className="faint">Need the raw endpoints or Node instead of PHP? See <Link to="/api-docs">API Docs</Link>.</p>
          </div>

          <div className="panel">
            <h2>⑤ Verify webhooks & troubleshoot</h2>
            <p className="muted" style={{ marginTop: -6 }}>
              Always verify a webhook on the <b>raw body</b> before trusting it - your <span className="mono">orizen-webhook.php</span> already does this:
            </p>
            <CodeBox id="verify" code={`<?php
require __DIR__ . '/orizen.php';
$cfg = require __DIR__ . '/orizen-config.php';
$raw = file_get_contents('php://input');
$ok  = Orizen::verifyWebhook(
    $raw,
    $_SERVER['HTTP_X_ORIZEN_SIGNATURE'] ?? '',
    $_SERVER['HTTP_X_ORIZEN_TIMESTAMP'] ?? '',
    $cfg['webhookSecret']
);
if (!$ok) { http_response_code(400); exit('bad signature'); }
$event = json_decode($raw, true);
if ($event['type'] === 'invoice.paid') { /* mark the order paid */ }
http_response_code(200);   // ACK fast; non-2xx is retried with backoff`} />
            <div className="tshoot">
              <b>Troubleshooting</b>
              <ul style={{ lineHeight: 1.85, marginTop: 6 }}>
                <li><b>Webhook not firing?</b> Your endpoint must be public over HTTPS. Re-add it on the <Link to="/webhooks">Webhooks</Link> page and check <b>Deliveries</b> for the response code.</li>
                <li><b>“bad signature”?</b> Verify against the <b>raw</b> body (don’t re-encode JSON), and confirm <span className="mono">webhookSecret</span> in <span className="mono">orizen-config.php</span> matches the <span className="mono">whsec_…</span> shown once on the Webhooks page.</li>
                <li><b>401 from the API?</b> Sign every request: <span className="mono">X-API-KEY</span>, <span className="mono">X-TIMESTAMP</span> (ms) and <span className="mono">X-SIGNATURE</span>. The SDK does this for you - keep the secret on the server, never the browser.</li>
                <li><b>Checkout shows the wrong amount?</b> Use <b>Full API</b> (dynamic totals) instead of a fixed payment link.</li>
                <li><b>Blocked webhook URL?</b> URLs resolving to private/internal IPs are rejected (SSRF protection) - use a public host.</li>
              </ul>
            </div>
          </div>
        </>
      )}
    </>
  );
}
