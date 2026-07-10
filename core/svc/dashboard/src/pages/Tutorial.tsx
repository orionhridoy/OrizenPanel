import { useState } from 'react';
import { Link } from 'react-router-dom';
import { HelpButton } from '../components/Help';

function Code({ children, id, copied, onCopy }: { children: string; id: string; copied: string; onCopy: (v: string, id: string) => void }): JSX.Element {
  return (
    <div style={{ position: 'relative' }}>
      <button className="secondary" style={{ position: 'absolute', top: 8, right: 8, padding: '4px 10px', fontSize: 12 }}
        onClick={() => onCopy(children, id)}>{copied === id ? 'Copied' : 'Copy'}</button>
      <div className="codeblock">{children}</div>
    </div>
  );
}

function StepHead({ n, title }: { n: number; title: string }): JSX.Element {
  return (
    <div style={{ display: 'flex', alignItems: 'center', gap: 14, margin: '10px 0 4px' }}>
      <div style={{ width: 40, height: 40, borderRadius: 12, display: 'grid', placeItems: 'center', fontWeight: 800, color: '#fff', background: 'linear-gradient(120deg,#6d5efc,#22d3ee)' }}>{n}</div>
      <h2 style={{ margin: 0 }}>{title}</h2>
    </div>
  );
}

export default function Tutorial(): JSX.Element {
  const origin = window.location.origin;
  const [copied, setCopied] = useState('');
  const copy = (value: string, id: string): void => {
    void navigator.clipboard.writeText(value);
    setCopied(id);
    setTimeout(() => setCopied(''), 1500);
  };

  const install = `# 1) get the code
git clone <your-orizen-repo> orizen && cd orizen

# 2) one command: installs Docker if needed, generates secrets, starts everything
sh install.sh

# or fully unattended (CI / provisioning):
ORIZEN_ASSUME_YES=1 APP_DOMAIN=pay.example.com ADMIN_EMAIL=ops@example.com sh install.sh`;

  const envZero = `# BTC & LTC via block explorers (no node, no wallet)
BITCOIN_ESPLORA_URL=https://mempool.space/api
LITECOIN_ESPLORA_URL=https://litecoinspace.org/api
# ETH/USDC, XRP, USDT via public RPC/WS
ETH_RPC_URL=https://ethereum-rpc.publicnode.com
XRPL_WS_URL=wss://xrplcluster.com
TRON_HTTP_URL=https://api.trongrid.io
TRON_API_KEY=your-free-trongrid-key`;

  const sdkInit = `require 'orizen.php';
$nvx = new Orizen(
  '${origin}',                 // your gateway URL
  getenv('ORIZEN_API_KEY'),    // orz_live_...
  getenv('ORIZEN_API_SECRET')  // shown once
);`;

  const addFunds = `// add-funds.php - customer clicked "Add funds"
$session = $nvx->createStoreSession($_SESSION['user_id']);
header('Location: ' . $session['url']);   // hosted top-up page
exit;`;

  const webhook = `// orizen-webhook.php - set this URL on the Webhooks page
$raw = file_get_contents('php://input');
$ok  = Orizen::verifyWebhook(
  $raw,
  $_SERVER['HTTP_X_ORIZEN_SIGNATURE'] ?? '',
  $_SERVER['HTTP_X_ORIZEN_TIMESTAMP'] ?? '',
  getenv('ORIZEN_WEBHOOK_SECRET')          // whsec_...
);
if (!$ok) { http_response_code(400); exit; }

$evt = json_decode($raw, true);
if ($evt['event'] === 'invoice.paid' && $evt['data']['purpose'] === 'TOPUP') {
    $userId = $evt['data']['storeUserRef'];        // your user id
    $amount = $evt['data']['amountPaidConfirmed'];
    // balance is already credited in the gateway - reflect it in your app
}
http_response_code(200);   // reply 2xx fast; non-2xx is retried`;

  const charge = `$r = $nvx->charge($_SESSION['user_id'], 'USDT_TRC20', '5.00', 'Tool X', $orderId);
// $r['remainingBalanceDecimal']`;

  const invoice = `$inv = $nvx->createInvoice('ETH', '0.0185', $orderId, 'Order #1042');
header('Location: ' . $inv['checkoutUrl']);   // send buyer to the hosted checkout`;

  return (
    <>
      <h1>Tutorial<HelpButton topic="apiDocs" /></h1>
      <p className="muted" style={{ marginTop: -8 }}>
        Install the gateway, wire it into your PHP shop in a few lines, and take your first payment. Prefer the
        no-code path? <Link to="/setup">Easy Setup</Link> generates every file for you.
      </p>

      {/* PART 1 */}
      <div className="panel">
        <StepHead n={1} title="Install the gateway" />
        <p className="muted">One command on any Linux/macOS host. It auto-detects everything and generates all secrets.</p>
        <h3>What you need</h3>
        <ul>
          <li>A server (a $5-10/mo VPS is plenty): Ubuntu, Debian, Fedora, Alma, Arch, Alpine, macOS or WSL.</li>
          <li>Docker (or Podman): the installer installs it for you if missing.</li>
          <li>A domain pointed at the server (optional: an IP works for testing).</li>
        </ul>
        <Code id="install" copied={copied} onCopy={copy}>{install}</Code>
        <p>When it finishes it prints your dashboard URL and a generated admin login. Change the password on first sign-in.</p>
        <div className="help-tip">
          Zero blockchain storage: point each coin at a public RPC/explorer in <span className="mono">.env</span> and the
          gateway detects payments with no local node. A small VPS runs all six coins.
        </div>
        <Code id="envzero" copied={copied} onCopy={copy}>{envZero}</Code>
      </div>

      {/* PART 2 */}
      <div className="panel">
        <StepHead n={2} title="Configure and go live" />
        <p className="muted">Sign in once and turn on the coins you want to accept.</p>
        <ol style={{ lineHeight: 1.9 }}>
          <li><b>Sign in</b> with the printed admin login and set a new password.</li>
          <li><b>Admin &gt; Crypto assets:</b> toggle BTC, ETH, USDT and the rest on/off gateway-wide.</li>
          <li><b>Admin &gt; Live sync:</b> every enabled chain should show synced.</li>
          <li><b>Settings:</b> choose your payout timing (Instant / Hold / Manual / Scheduled).</li>
        </ol>
        <div className="help-tip">Every page has a "? How to use" button that explains what it does and how.</div>
      </div>

      {/* PART 3 */}
      <div className="panel">
        <StepHead n={3} title="Connect your PHP shop" />
        <p className="muted">Two integration styles: a customer balance (top-ups), or a one-off checkout. Both are a few lines of PHP.</p>

        <div className="secret-box" style={{ borderColor: 'rgba(109,94,252,.45)' }}>
          <div className="tag">Fastest way</div>
          <p style={{ margin: '6px 0 0' }}>
            Open <Link to="/setup">Easy Setup</Link>, type your website, and click one button. It creates your API key,
            registers your webhook, and generates every file below with your keys filled in and where to put each one.
            The steps here explain what those files do.
          </p>
        </div>

        <h3>Step A - Create an API key</h3>
        <p>In <b>API Keys</b>, create a key with the <span className="mono">store:manage</span> permission (top-ups) or
          <span className="mono"> invoices:write</span> (checkout). Copy the key and secret; the secret is shown once. It
          lives on your server only, never in browser code.</p>

        <h3>Step B - Add the SDK to your server</h3>
        <p>Copy <span className="mono">docs/integration/orizen.php</span> next to your shop code. It signs every request for you.</p>
        <Code id="sdk" copied={copied} onCopy={copy}>{sdkInit}</Code>

        <h3>Step C - "Add funds" button</h3>
        <p>When a logged-in customer clicks Add funds, mint a hosted top-up session and send them to it.</p>
        <Code id="addfunds" copied={copied} onCopy={copy}>{addFunds}</Code>

        <h3>Step D - Receive the webhook and add balance</h3>
        <p>The customer pays; Orizen Pay verifies it on-chain, credits their balance, and posts a signed webhook containing
          <span className="mono"> storeUserRef</span> (your user id). Verify the signature, then reflect it.</p>
        <Code id="webhook" copied={copied} onCopy={copy}>{webhook}</Code>

        <h3>Step E - Spend the balance</h3>
        <p>When the customer buys a tool, charge their balance. Idempotent per key.</p>
        <Code id="charge" copied={copied} onCopy={copy}>{charge}</Code>

        <h3>Alternative: one-off invoice for a cart</h3>
        <Code id="invoice" copied={copied} onCopy={copy}>{invoice}</Code>
      </div>

      {/* PART 4 */}
      <div className="panel">
        <StepHead n={4} title="Receive a payment" />
        <p className="muted">Every invoice gets a brand-new address (never reused). The hosted checkout shows a QR, the
          exact amount, a live countdown, and a status that updates in real time.</p>
        <table>
          <thead><tr><th>Status</th><th>Meaning</th></tr></thead>
          <tbody>
            <tr><td><span className="badge PENDING">NEW</span></td><td>Waiting for payment.</td></tr>
            <tr><td><span className="badge PENDING">SEEN</span></td><td>Transaction spotted in the mempool (unconfirmed).</td></tr>
            <tr><td><span className="badge PENDING">CONFIRMING</span></td><td>In a block, waiting for the required confirmations.</td></tr>
            <tr><td><span className="badge ACTIVE">PAID</span></td><td>Fully confirmed. Balance credited, invoice.paid webhook sent.</td></tr>
            <tr><td><span className="badge REJECTED">UNDERPAID / OVERPAID</span></td><td>Handled automatically, each with its own webhook.</td></tr>
          </tbody>
        </table>
        <div className="help-tip">Test it: create an invoice from <Link to="/invoices">Invoices</Link>, open the checkout,
          and send a tiny amount. Watch it go NEW to SEEN to PAID, then see the balance appear.</div>
      </div>

      {/* PART 5 */}
      <div className="panel">
        <StepHead n={5} title="Everything else a merchant needs" />
        <ul style={{ lineHeight: 1.9 }}>
          <li><b>Withdrawals:</b> send your Available balance to any external wallet. Large amounts wait for admin approval.</li>
          <li><b>Mass payouts:</b> pay up to 100 destinations in one call (payroll, affiliates).</li>
          <li><b>Refunds:</b> return a paid invoice to the payer in one click or API call.</li>
          <li><b>Analytics:</b> paid-per-day, revenue by asset, the funnel, top-converting links.</li>
          <li><b>Payment links:</b> no-code reusable checkout URLs for donations, socials, POS QR codes.</li>
          <li><b>Security:</b> keys never touch the API tier; double-entry ledger, HMAC signing, SSRF-guarded webhooks, 2FA.</li>
        </ul>
        <p>Full reference with every endpoint and parameter is in <Link to="/api-docs">API Docs</Link>.</p>
      </div>
    </>
  );
}
