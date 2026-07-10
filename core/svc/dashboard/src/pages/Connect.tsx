import { FormEvent, useState } from 'react';
import { Link } from 'react-router-dom';
import { api } from '../api/client';
import { HelpButton } from '../components/Help';

interface LinkResult { url: string; slug: string }
interface KeyResult { apiKey: string; apiSecret: string }

type Mode = 'button' | 'store' | 'api';

export default function Connect(): JSX.Element {
  const origin = window.location.origin;
  const [siteName, setSiteName] = useState('');
  const [mode, setMode] = useState<Mode>('button');
  const [priceMode, setPriceMode] = useState<'fixed' | 'open'>('open');
  const [amount, setAmount] = useState('');
  const [currency, setCurrency] = useState('USD');
  const [link, setLink] = useState<LinkResult | null>(null);
  const [key, setKey] = useState<KeyResult | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [copied, setCopied] = useState('');

  const copy = async (value: string, id: string): Promise<void> => {
    await navigator.clipboard.writeText(value);
    setCopied(id);
    setTimeout(() => setCopied(''), 1500);
  };

  const generate = async (event: FormEvent): Promise<void> => {
    event.preventDefault();
    setBusy(true);
    setError('');
    setLink(null);
    setKey(null);
    try {
      if (mode === 'button') {
        const l = await api<LinkResult>('/dashboard/links', {
          method: 'POST',
          body: {
            title: siteName || 'Payment',
            ...(priceMode === 'fixed' ? { fiatAmount: amount, fiatCurrency: currency } : { allowCustomAmount: true, fiatCurrency: currency }),
          },
        });
        setLink(l);
      } else {
        const permissions =
          mode === 'store'
            ? ['store:manage', 'invoices:read', 'balances:read']
            : ['invoices:read', 'invoices:write', 'balances:read', 'store:manage'];
        const k = await api<KeyResult>('/dashboard/api-keys', {
          method: 'POST',
          body: { label: siteName || (mode === 'store' ? 'Store integration' : 'Website integration'), permissions },
        });
        setKey(k);
      }
    } catch (e) {
      setError((e as Error).message);
    } finally {
      setBusy(false);
    }
  };

  const buttonSnippet = link
    ? `<!-- Paste anywhere on your website. No API key needed, nothing secret. -->
<a href="${link.url}" target="_blank" rel="noopener"
   style="display:inline-block;padding:13px 26px;border-radius:12px;
   background:linear-gradient(120deg,#6d5efc,#a855f7,#22d3ee);
   color:#fff;font:600 15px system-ui;text-decoration:none;
   box-shadow:0 8px 24px -8px rgba(109,94,252,.7)">
  Pay with Crypto
</a>`
    : '';

  const storeSnippet = key
    ? `// server.js - npm i express ; put orizen.js (docs/integration) next to this
const { Orizen } = require('./orizen');
const nvx = new Orizen({
  baseUrl: '${origin}',
  apiKey: '${key.apiKey}',
  apiSecret: '${key.apiSecret}'   // SERVER only - never send to the browser
});

// 1) Customer clicks "Add funds" → send them to the hosted top-up page
app.post('/wallet/topup', async (req, res) => {
  const s = await nvx.createStoreSession({ externalRef: req.user.id });
  res.json({ redirect: s.url });
});

// 2) They pay crypto. Orizen Pay verifies on-chain, adds their balance, and calls
//    this webhook. Verify it, then refresh your UI. (Set the URL on Webhooks.)
app.post('/webhooks/orizen', express.raw({ type: '*/*' }), (req, res) => {
  const ok = Orizen.verifyWebhook(req.body, req.header('X-Orizen-Signature'),
    req.header('X-Orizen-Timestamp'), process.env.ORIZEN_WEBHOOK_SECRET);
  if (!ok) return res.sendStatus(400);
  const evt = JSON.parse(req.body.toString());
  if (evt.event === 'invoice.paid' && evt.data.purpose === 'TOPUP') {
    // evt.data.storeUserRef = your user id, balance already credited in the gateway
    markFunded(evt.data.storeUserRef, evt.data.asset, evt.data.amountPaidConfirmed);
  }
  res.sendStatus(200);
});

// 3) When they buy a tool, spend their balance
app.post('/buy', async (req, res) => {
  const r = await nvx.charge({ externalRef: req.user.id, assetCode: 'USDT_TRC20',
    amount: '5.00', description: 'Tool X', idempotencyKey: req.body.orderId });
  res.json(r); // { remainingBalanceDecimal, ... }
});`
    : '';

  const nodeSnippet = key
    ? `// server.js  -  npm i express ; put orizen.js (docs/integration) next to this
const { Orizen } = require('./orizen');
const nvx = new Orizen({
  baseUrl: '${origin}',
  apiKey: '${key.apiKey}',
  apiSecret: '${key.apiSecret}'   // keep this on the SERVER only
});

// create a checkout for a cart total
app.post('/pay', async (req, res) => {
  const inv = await nvx.createInvoice({
    assetCode: 'ETH', fiatAmount: String(req.body.usdTotal), fiatCurrency: 'USD',
    orderId: req.body.orderId
  });
  res.json({ checkoutUrl: inv.checkoutUrl });   // redirect the buyer here
});`
    : '';

  const CodeBlock = ({ id, code }: { id: string; code: string }): JSX.Element => (
    <div style={{ position: 'relative' }}>
      <button className="secondary" style={{ position: 'absolute', top: 8, right: 8, padding: '4px 10px', fontSize: 12 }}
        onClick={() => void copy(code, id)}>{copied === id ? 'Copied ✓' : 'Copy'}</button>
      <div className="codeblock">{code}</div>
    </div>
  );

  return (
    <>
      <h1>Connect a website<HelpButton topic="connect" /></h1>

      <div className="panel">
        <h2>Add crypto payments to your site in one step</h2>
        <p className="muted" style={{ marginTop: -6 }}>
          Tell us your site and how you want to charge - we generate the exact code to paste.
        </p>
        <form onSubmit={(e) => void generate(e)}>
          <div className="field" style={{ maxWidth: 420 }}>
            <label>Website name or URL</label>
            <input value={siteName} onChange={(e) => setSiteName(e.target.value)} placeholder="My Shop / myshop.com" maxLength={140} />
          </div>

          <label>Integration type</label>
          <div className="chips" style={{ marginBottom: 14 }}>
            <span className={`chip ${mode === 'button' ? 'active' : ''}`} onClick={() => setMode('button')}>🔘 Payment button (no code)</span>
            <span className={`chip ${mode === 'store' ? 'active' : ''}`} onClick={() => setMode('store')}>🏦 Store balance (top-ups)</span>
            <span className={`chip ${mode === 'api' ? 'active' : ''}`} onClick={() => setMode('api')}>⚙️ Full API (carts / dynamic)</span>
          </div>

          {mode === 'button' && (
            <div className="row">
              <div className="field" style={{ width: 200 }}>
                <label>Amount</label>
                <select value={priceMode} onChange={(e) => setPriceMode(e.target.value as 'fixed' | 'open')}>
                  <option value="open">Buyer enters amount</option>
                  <option value="fixed">Fixed price</option>
                </select>
              </div>
              {priceMode === 'fixed' && (
                <div className="field" style={{ width: 130 }}>
                  <label>Price</label>
                  <input value={amount} onChange={(e) => setAmount(e.target.value)} placeholder="20.00" required />
                </div>
              )}
              <div className="field" style={{ width: 110 }}>
                <label>Currency</label>
                <select value={currency} onChange={(e) => setCurrency(e.target.value)}>
                  <option>USD</option><option>EUR</option><option>GBP</option>
                </select>
              </div>
            </div>
          )}

          {mode === 'store' && (
            <p className="muted" style={{ fontSize: 13 }}>
              Customers load a balance and spend it on your tools. We create a store API key; you get
              back copy-paste server code for the whole loop: <b>create top-up → Orizen Pay verifies the
              payment &amp; adds balance → you receive a signed webhook → spend the balance</b>.
            </p>
          )}

          {mode === 'api' && (
            <p className="muted" style={{ fontSize: 13 }}>
              We'll create a dedicated API key so your server can create invoices for cart totals,
              charge store balances, and more. The secret is shown once - keep it on your server.
            </p>
          )}

          {error && <div className="error">{error}</div>}
          <button type="submit" disabled={busy} style={{ marginTop: 8 }}>
            {busy ? <><span className="spinner" /> Generating...</> : 'Generate my payment code'}
          </button>
        </form>
      </div>

      {link && (
        <div className="panel">
          <h2>✅ Your payment button is ready</h2>
          <p className="muted">Paste this into your site's HTML. It opens a hosted, stylish checkout - nothing secret is exposed.</p>
          <CodeBlock id="btn" code={buttonSnippet} />
          <div style={{ marginTop: 14 }}>
            <span className="muted">Live preview: </span>
            <a href={link.url} target="_blank" rel="noopener noreferrer" className="btn" style={{ marginLeft: 8 }}>Pay with Crypto</a>
          </div>
          <p className="faint" style={{ marginTop: 12 }}>Direct link (share anywhere / QR): <span className="mono">{link.url}</span>{' '}
            <button className="ghost" onClick={() => void copy(link.url, 'url')}>{copied === 'url' ? '✓' : 'copy'}</button></p>
        </div>
      )}

      {key && (
        <div className="panel">
          <h2>✅ Your {mode === 'store' ? 'store' : 'API'} integration is ready</h2>
          <div className="secret-box">
            <strong>Save these now - the secret is shown only once.</strong>
            <p className="mono">API key: {key.apiKey}</p>
            <p className="mono">API secret: {key.apiSecret}</p>
          </div>
          <label>{mode === 'store' ? 'Store server code - top-up, verify webhook, spend (Node.js)' : 'Server code (Node.js)'}</label>
          <CodeBlock id="node" code={mode === 'store' ? storeSnippet : nodeSnippet} />
          <p className="faint" style={{ marginTop: 10 }}>
            Grab the ready-made SDKs (Node &amp; PHP) from <span className="mono">docs/integration/</span>, or see every
            command in <Link to="/api-docs">API Docs →</Link>. Prefer no code? Switch to “Payment button” above.
          </p>
        </div>
      )}
    </>
  );
}
