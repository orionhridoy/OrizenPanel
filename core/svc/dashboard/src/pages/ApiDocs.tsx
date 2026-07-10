import { useState } from 'react';
import { HelpButton } from '../components/Help';

/** Copyable code block. */
function Code({ children, id, copied, onCopy }: { children: string; id: string; copied: string; onCopy: (v: string, id: string) => void }): JSX.Element {
  return (
    <div style={{ position: 'relative' }}>
      <button className="secondary" style={{ position: 'absolute', top: 8, right: 8, padding: '4px 10px', fontSize: 12 }}
        onClick={() => onCopy(children, id)}>{copied === id ? 'Copied ✓' : 'Copy'}</button>
      <div className="codeblock">{children}</div>
    </div>
  );
}

const METHOD_COLORS: Record<string, string> = { GET: '#22d3ee', POST: '#6d5efc', PATCH: '#f59e0b', DELETE: '#ef4444' };
function Method({ m }: { m: string }): JSX.Element {
  return <span style={{ fontFamily: 'ui-monospace, monospace', fontWeight: 700, fontSize: 12, color: METHOD_COLORS[m] ?? '#fff', border: `1px solid ${METHOD_COLORS[m] ?? '#888'}55`, background: `${METHOD_COLORS[m] ?? '#888'}18`, borderRadius: 6, padding: '2px 8px', marginRight: 8 }}>{m}</span>;
}

type Loc = 'body' | 'query' | 'path';
interface Param { name: string; loc: Loc; type: string; req: boolean; desc: string }
interface Ep { m: string; path: string; perm?: string; auth?: string; desc: string; params?: Param[]; res?: string }

const ASSET_ENUM = 'BTC · LTC · ETH · XRP · USDT_TRC20 · USDC_ERC20';

const GROUPS: Array<{ id: string; title: string; blurb: string; eps: Ep[] }> = [
  {
    id: 'invoices', title: 'Invoices', blurb: 'One-off checkouts that credit YOUR gateway balance.',
    eps: [
      {
        m: 'POST', path: '/api/v1/merchant/invoices', perm: 'invoices:write',
        desc: 'Create an invoice. Send EITHER amount (crypto) OR fiatAmount + fiatCurrency (auto-converted, rate locked).',
        params: [
          { name: 'assetCode', loc: 'body', type: `enum (${ASSET_ENUM})`, req: true, desc: 'Coin to be paid in.' },
          { name: 'amount', loc: 'body', type: 'string (decimal)', req: false, desc: 'Crypto amount in whole coins, e.g. "0.015". Provide this OR fiatAmount.' },
          { name: 'fiatAmount', loc: 'body', type: 'string (≤2 dp)', req: false, desc: 'Fiat amount, e.g. "49.99". Converted to crypto at the live rate.' },
          { name: 'fiatCurrency', loc: 'body', type: 'enum (USD · EUR · GBP)', req: false, desc: 'Required when fiatAmount is used.' },
          { name: 'orderId', loc: 'body', type: 'string (≤120)', req: false, desc: 'Your order reference. Unique per merchant (duplicates rejected).' },
          { name: 'description', loc: 'body', type: 'string (≤500)', req: false, desc: 'Shown on the checkout page.' },
          { name: 'redirectUrl', loc: 'body', type: 'string https (≤500)', req: false, desc: 'Where to send the payer after they pay.' },
          { name: 'metadata', loc: 'body', type: 'object (JSON)', req: false, desc: 'Free-form data stored on the invoice and echoed back.' },
          { name: 'expiresInMinutes', loc: 'body', type: 'int 5-43200', req: false, desc: 'Custom expiry window: 5 minutes to 30 days. Defaults to 1 hour.' },
        ],
        res: `{
  "id": "0192f3...", "orderId": "order-1042", "status": "NEW",
  "asset": "ETH", "chain": "ethereum",
  "amountDue": "18500000000000000",        // base units (wei/sat/drops)
  "amountDueDecimal": "0.0185",
  "fiatCurrency": "USD", "fiatAmount": "49.99", "exchangeRate": "2702.7",
  "amountPaidPending": "0", "amountPaidConfirmed": "0",
  "address": "0xabc...", "destinationTag": null,
  "paymentUri": "ethereum:0xabc...?value=18500000000000000",
  "requiredConfirmations": 12,
  "description": "Pro plan", "metadata": {}, "redirectUrl": null,
  "checkoutUrl": "https://your-gateway/checkout/0192f3...",
  "expiresAt": "2026-07-04T12:30:00.000Z", "paidAt": null,
  "createdAt": "2026-07-04T11:30:00.000Z"
}`,
      },
      {
        m: 'GET', path: '/api/v1/merchant/invoices', perm: 'invoices:read', desc: 'List invoices, newest first.',
        params: [
          { name: 'status', loc: 'query', type: 'enum (NEW · SEEN · CONFIRMING · PAID · UNDERPAID · OVERPAID · EXPIRED · INVALID)', req: false, desc: 'Filter by status.' },
          { name: 'limit', loc: 'query', type: 'int 1-100', req: false, desc: 'Page size. Default 25.' },
          { name: 'offset', loc: 'query', type: 'int ≥0', req: false, desc: 'Rows to skip. Default 0.' },
        ],
        res: `{ "items": [ /* InvoiceView, see above */ ], "total": 42 }`,
      },
      {
        m: 'GET', path: '/api/v1/merchant/invoices/:id', perm: 'invoices:read', desc: 'Fetch one invoice with its detected payments.',
        params: [{ name: 'id', loc: 'path', type: 'uuid', req: true, desc: 'Invoice id.' }],
      },
    ],
  },
  {
    id: 'store', title: 'Store - customer balances', blurb: 'Customers load a balance and spend it. The gateway holds each balance; you get a webhook on top-up.',
    eps: [
      {
        m: 'POST', path: '/api/v1/merchant/store/sessions', perm: 'store:manage',
        desc: 'Mint a hosted top-up session for one customer. Redirect them to the returned url.',
        params: [
          { name: 'externalRef', loc: 'body', type: 'string 1-200', req: true, desc: 'YOUR user id. Creates the store user on first use.' },
          { name: 'displayName', loc: 'body', type: 'string (≤120)', req: false, desc: 'Shown on the hosted page.' },
          { name: 'email', loc: 'body', type: 'email (≤254)', req: false, desc: 'Optional customer email.' },
        ],
        res: `{ "token": "eyJ...", "url": "https://your-gateway/store?t=eyJ...", "expiresIn": 86400, "storeUserId": "0192..." }`,
      },
      {
        m: 'POST', path: '/api/v1/merchant/store/purchase', perm: 'store:manage',
        desc: 'Spend a customer’s balance (debit them, credit you). Idempotent per idempotencyKey.',
        params: [
          { name: 'externalRef', loc: 'body', type: 'string 1-200', req: true, desc: 'The customer to charge.' },
          { name: 'assetCode', loc: 'body', type: `enum (${ASSET_ENUM})`, req: true, desc: 'Which balance to debit.' },
          { name: 'amount', loc: 'body', type: 'string (decimal)', req: true, desc: 'Whole-coin amount, e.g. "5.00".' },
          { name: 'description', loc: 'body', type: 'string (≤200)', req: false, desc: 'What they bought.' },
          { name: 'idempotencyKey', loc: 'body', type: 'string (≤120)', req: false, desc: 'Repeat calls with the same key return the first result - never double-charge.' },
        ],
        res: `{ "purchaseId": "0192...", "assetCode": "USDT_TRC20", "amount": "5000000", "remainingBalanceDecimal": "12.50" }`,
      },
      {
        m: 'GET', path: '/api/v1/merchant/store/users/:externalRef/balances', perm: 'store:manage', desc: 'A customer’s per-asset store balances.',
        params: [{ name: 'externalRef', loc: 'path', type: 'string (url-encoded)', req: true, desc: 'YOUR user id.' }],
        res: `[ { "asset": "USDT_TRC20", "balanceBaseUnits": "12500000", "balanceDecimal": "12.50" } ]`,
      },
    ],
  },
  {
    id: 'links', title: 'Payment Links', blurb: 'Reusable, shareable checkout URLs (no code).',
    eps: [
      {
        m: 'POST', path: '/api/v1/merchant/links', perm: 'invoices:write',
        desc: 'Create a reusable link. Choose ONE pricing style: fixed fiat, fixed crypto, or payer-entered.',
        params: [
          { name: 'title', loc: 'body', type: 'string 1-140', req: true, desc: 'Shown to the payer.' },
          { name: 'description', loc: 'body', type: 'string (≤500)', req: false, desc: 'Optional detail.' },
          { name: 'fiatCurrency', loc: 'body', type: 'enum (USD · EUR · GBP)', req: false, desc: 'Currency for fiat pricing / display.' },
          { name: 'fiatAmount', loc: 'body', type: 'string (≤2 dp)', req: false, desc: 'Fixed fiat price; payer picks the coin.' },
          { name: 'assetCode', loc: 'body', type: `enum (${ASSET_ENUM})`, req: false, desc: 'Lock the coin.' },
          { name: 'cryptoAmount', loc: 'body', type: 'string (decimal)', req: false, desc: 'Fixed crypto price (needs assetCode).' },
          { name: 'allowCustomAmount', loc: 'body', type: 'boolean', req: false, desc: 'Let the payer enter the amount (donations/tips).' },
          { name: 'redirectUrl', loc: 'body', type: 'string https', req: false, desc: 'Post-payment redirect.' },
        ],
        res: `{ "id": "0192...", "slug": "pay_ab12", "title": "...", "isActive": true, "url": "https://your-gateway/pay/pay_ab12" }`,
      },
      {
        m: 'GET', path: '/api/v1/merchant/links', perm: 'invoices:read', desc: 'List your links, newest first.',
        params: [
          { name: 'limit', loc: 'query', type: 'int 1-100', req: false, desc: 'Page size. Default 25.' },
          { name: 'offset', loc: 'query', type: 'int ≥0', req: false, desc: 'Rows to skip. Default 0.' },
        ],
        res: `{ "items": [ { "slug": "pay_ab12", "url": "...", "times_used": 3, ... } ], "total": 12 }`,
      },
    ],
  },
  {
    id: 'withdrawals', title: 'Withdrawals', blurb: 'Send your Available balance to an external address. Large amounts wait for admin approval.',
    eps: [
      {
        m: 'POST', path: '/api/v1/merchant/withdrawals', perm: 'withdrawals:write', desc: 'Request a withdrawal.',
        params: [
          { name: 'assetCode', loc: 'body', type: `enum (${ASSET_ENUM})`, req: true, desc: 'Coin to send.' },
          { name: 'amount', loc: 'body', type: 'string (decimal)', req: true, desc: 'Whole-coin amount.' },
          { name: 'destinationAddress', loc: 'body', type: 'string (≤128)', req: true, desc: 'External address you control.' },
          { name: 'destinationTag', loc: 'body', type: 'int 0-4294967295', req: false, desc: 'XRP destination tag (XRP only).' },
          { name: 'idempotencyKey', loc: 'body', type: 'string (≤120)', req: false, desc: 'Prevents duplicate withdrawals on retry.' },
        ],
        res: `{
  "id": "0192...", "asset_code": "BTC", "amount": "1500000", "network_fee": null,
  "destination_address": "bc1q...", "destination_tag": null,
  "status": "PENDING",              // PENDING → APPROVED → BROADCAST → CONFIRMED
  "requires_admin_approval": false, "txid": null, "error": null,
  "created_at": "...", "broadcast_at": null, "confirmed_at": null
}`,
      },
      {
        m: 'GET', path: '/api/v1/merchant/withdrawals/:id', perm: 'withdrawals:write', desc: 'Track a withdrawal.',
        params: [{ name: 'id', loc: 'path', type: 'uuid', req: true, desc: 'Withdrawal id.' }],
      },
    ],
  },
  {
    id: 'payouts', title: 'Mass Payouts', blurb: 'Pay up to 100 destinations in one call (payroll, affiliates, suppliers).',
    eps: [
      {
        m: 'POST', path: '/api/v1/merchant/payouts', perm: 'withdrawals:write', desc: 'Create a payout batch. Each item passes the normal risk/approval checks.',
        params: [
          { name: 'items', loc: 'body', type: 'array 1-100', req: true, desc: 'Payout items (see item fields below).' },
          { name: 'items[].assetCode', loc: 'body', type: `enum (${ASSET_ENUM})`, req: true, desc: 'Coin for this item.' },
          { name: 'items[].amount', loc: 'body', type: 'string (decimal)', req: true, desc: 'Whole-coin amount.' },
          { name: 'items[].destinationAddress', loc: 'body', type: 'string (≤128)', req: true, desc: 'Recipient address.' },
          { name: 'items[].destinationTag', loc: 'body', type: 'int 0-4294967295', req: false, desc: 'XRP tag if applicable.' },
          { name: 'items[].reference', loc: 'body', type: 'string (≤120)', req: false, desc: 'Your reference for this line.' },
          { name: 'label', loc: 'body', type: 'string (≤140)', req: false, desc: 'Batch name.' },
          { name: 'idempotencyKey', loc: 'body', type: 'string (≤120)', req: false, desc: 'Safe retries for the whole batch.' },
        ],
      },
      {
        m: 'GET', path: '/api/v1/merchant/payouts/:id', perm: 'withdrawals:write', desc: 'Fetch a batch + per-item status.',
        params: [{ name: 'id', loc: 'path', type: 'uuid', req: true, desc: 'Batch id.' }],
      },
    ],
  },
  {
    id: 'balances', title: 'Balances', blurb: 'Your gateway balances across all assets.',
    eps: [
      {
        m: 'GET', path: '/api/v1/merchant/balances', perm: 'balances:read', desc: 'One row per asset per account type (no parameters).',
        res: `[
  { "asset_code": "BTC", "type": "MERCHANT_AVAILABLE", "balance": "125000" },
  { "asset_code": "BTC", "type": "MERCHANT_PENDING",   "balance": "0" },
  { "asset_code": "BTC", "type": "MERCHANT_LOCKED",    "balance": "0" }
]`,
      },
    ],
  },
  {
    id: 'public', title: 'Public (no auth / no signing)', blurb: 'Safe to call from a browser. Used by hosted checkout/link pages.',
    eps: [
      { m: 'GET', path: '/api/v1/public/rates', auth: 'none', desc: 'Current fiat conversion rates (no parameters).' },
      { m: 'GET', path: '/api/v1/public/invoices/:id', auth: 'none', desc: 'Public invoice view for a checkout page.', params: [{ name: 'id', loc: 'path', type: 'uuid', req: true, desc: 'Invoice id.' }] },
      { m: 'GET', path: '/api/v1/public/invoices/:id/events', auth: 'none', desc: 'Server-Sent Events stream of live status changes for a checkout.', params: [{ name: 'id', loc: 'path', type: 'uuid', req: true, desc: 'Invoice id.' }] },
      { m: 'GET', path: '/api/v1/public/links/:slug', auth: 'none', desc: 'Public payment-link details.', params: [{ name: 'slug', loc: 'path', type: 'string', req: true, desc: 'Link slug.' }] },
      {
        m: 'POST', path: '/api/v1/public/links/:slug/invoice', auth: 'none', desc: 'Create an invoice from a payment link (payer chooses coin/amount as the link allows).',
        params: [
          { name: 'slug', loc: 'path', type: 'string', req: true, desc: 'Link slug.' },
          { name: 'assetCode', loc: 'body', type: `enum (${ASSET_ENUM})`, req: false, desc: 'Coin, when the link lets the payer choose.' },
          { name: 'amount', loc: 'body', type: 'string (decimal)', req: false, desc: 'Crypto amount (payer-entered links).' },
          { name: 'fiatAmount', loc: 'body', type: 'string (≤2 dp)', req: false, desc: 'Fiat amount (payer-entered links).' },
          { name: 'fiatCurrency', loc: 'body', type: 'enum (USD · EUR · GBP)', req: false, desc: 'Currency for fiatAmount.' },
        ],
      },
      { m: 'GET', path: '/api/v1/public/health', auth: 'none', desc: 'Liveness probe (no parameters).' },
    ],
  },
];

const WEBHOOK_EVENTS = [
  'invoice.seen', 'invoice.confirming', 'invoice.paid', 'invoice.underpaid',
  'invoice.overpaid', 'invoice.expired', 'invoice.invalid',
  'withdrawal.broadcast', 'withdrawal.confirmed', 'withdrawal.failed',
];

const PERMISSIONS = [
  ['invoices:read', 'List/read invoices and links'],
  ['invoices:write', 'Create invoices and payment links'],
  ['balances:read', 'Read gateway balances'],
  ['store:manage', 'Store sessions, purchases, customer balances'],
  ['withdrawals:write', 'Request withdrawals and payouts'],
  ['webhooks:manage', 'Manage webhook endpoints'],
];

export default function ApiDocs(): JSX.Element {
  const origin = window.location.origin;
  const [copied, setCopied] = useState('');
  const copy = (value: string, id: string): void => {
    void navigator.clipboard.writeText(value);
    setCopied(id);
    setTimeout(() => setCopied(''), 1500);
  };

  const signingExplainer = `Every merchant request is signed. Send these headers:

  X-API-KEY     orz_live_...        (your key)
  X-TIMESTAMP   1720094400000       (unix milliseconds, ±5 min skew allowed)
  X-SIGNATURE   <hex>               (HMAC-SHA256, see below)

Signature = HMAC_SHA256( apiSecret, canonical ), where
  canonical = TIMESTAMP + "." + METHOD + "." + PATH + "." + SHA256_HEX(body)

  • METHOD is upper-case (GET, POST, ...)
  • PATH includes /api/v1 and NO query string, e.g. /api/v1/merchant/invoices
  • body is the exact JSON string you send (empty string "" for GET / no body)
  • never put the secret in a browser - sign on your server only`;

  const conventions = `Base URL     ${origin}/api/v1
Content-Type application/json  (on every request with a body)
Amounts      request "amount"/"fiatAmount" are DECIMAL strings in whole units ("0.015", "49.99").
             responses also give base-unit integers (amountDue = wei / satoshi / drops).
Pagination   list endpoints take ?limit=1..100 (default 25) and ?offset>=0 (default 0).
Idempotency  pass idempotencyKey on purchase/withdrawals/payouts - retries return the first result.
Errors       non-2xx returns { "error": { "message": "...", "statusCode": 400 } }.
             401 = bad/missing signature or key · 403 = missing permission / suspended
             400 = validation (message lists the invalid fields) · 404 = not found · 409 = conflict`;

  const curlExample = `TS=$(date +%s000)
BODY='{"externalRef":"user_123"}'
BH=$(printf '%s' "$BODY" | openssl dgst -sha256 -hex | sed 's/^.* //')
SIG=$(printf '%s' "$TS.POST./api/v1/merchant/store/sessions.$BH" | openssl dgst -sha256 -hmac "$ORIZEN_API_SECRET" -hex | sed 's/^.* //')
curl -X POST ${origin}/api/v1/merchant/store/sessions \\
  -H "X-API-KEY: $ORIZEN_API_KEY" -H "X-TIMESTAMP: $TS" -H "X-SIGNATURE: $SIG" \\
  -H "content-type: application/json" -d "$BODY"`;

  const webhookPayload = `POST  https://your-server/orizen-webhook.php
Headers:
  X-Orizen-Event:      invoice.paid
  X-Orizen-Timestamp:  1720094400000
  X-Orizen-Signature:  v1=<hmac_sha256(whsec, timestamp + "." + rawBody)>
Body:
{
  "event": "invoice.paid",
  "createdAt": "2026-07-04T12:00:00.000Z",
  "data": {
    "invoiceId": "0192f3...",
    "status": "PAID",                 // NEW·SEEN·CONFIRMING·PAID·UNDERPAID·OVERPAID·EXPIRED·INVALID
    "asset": "USDT_TRC20",
    "amountDue": "5000000",           // base units
    "amountPaidPending": "0",
    "amountPaidConfirmed": "5000000",
    "purpose": "TOPUP",               // TOPUP (store) or CHECKOUT (plain invoice)
    "orderId": null,                  // your invoice orderId (CHECKOUT)
    "storeUserRef": "user_123",       // your customer id (TOPUP)
    "description": "Store credit top-up (user_123)"
  }
}`;

  const locColor: Record<Loc, string> = { body: '#6d5efc', query: '#22d3ee', path: '#f59e0b' };

  return (
    <>
      <h1>API Docs<HelpButton topic="apiDocs" /></h1>
      <p className="muted" style={{ marginTop: -8 }}>
        Base URL <span className="mono">{origin}/api/v1</span>. Every endpoint, every parameter. The zero-dependency
        Node &amp; PHP SDKs in <span className="mono">docs/integration/</span> sign requests for you.
      </p>

      <div className="panel">
        <div className="chips">
          <a href="#auth" className="chip">Auth &amp; signing</a>
          <a href="#conventions" className="chip">Conventions</a>
          <a href="#quickstart" className="chip active">⭐ Store quickstart</a>
          {GROUPS.map((g) => <a key={g.id} href={`#${g.id}`} className="chip">{g.title}</a>)}
          <a href="#webhooks" className="chip">Webhooks</a>
        </div>
      </div>

      {/* Auth */}
      <div className="panel" id="auth">
        <h2>Authentication &amp; request signing</h2>
        <p className="muted" style={{ marginTop: -6 }}>Create a key on the <b>API Keys</b> page, granting only the permissions you need:</p>
        <table>
          <thead><tr><th>Permission</th><th>Grants</th></tr></thead>
          <tbody>{PERMISSIONS.map(([p, d]) => <tr key={p}><td className="mono">{p}</td><td className="muted">{d}</td></tr>)}</tbody>
        </table>
        <div style={{ marginTop: 14 }}><Code id="sign" copied={copied} onCopy={copy}>{signingExplainer}</Code></div>
        <p className="faint" style={{ marginTop: 12 }}>Raw curl (no SDK):</p>
        <Code id="curl" copied={copied} onCopy={copy}>{curlExample}</Code>
      </div>

      {/* Conventions */}
      <div className="panel" id="conventions">
        <h2>Conventions - amounts, pagination, idempotency, errors</h2>
        <Code id="conv" copied={copied} onCopy={copy}>{conventions}</Code>
      </div>

      {/* Quickstart */}
      <div className="panel" id="quickstart" style={{ borderColor: 'rgba(109,94,252,.45)' }}>
        <div className="tag">Recommended · the fastest path</div>
        <h2 style={{ marginTop: 6 }}>Store balance in 3 steps</h2>
        <p className="muted" style={{ marginTop: -6 }}>
          Or skip the code entirely: <b>Easy Setup</b> generates every file (incl. the PHP webhook) with your keys filled in.
        </p>
        <ol style={{ lineHeight: 1.9 }}>
          <li><b>Top-up:</b> <span className="mono">POST /merchant/store/sessions</span> with your <span className="mono">externalRef</span> → redirect the customer to <span className="mono">url</span>.</li>
          <li><b>Verify + credit:</b> they pay; the gateway adds their balance and POSTs <span className="mono">invoice.paid</span> to your webhook (contains <span className="mono">storeUserRef</span>).</li>
          <li><b>Spend:</b> <span className="mono">POST /merchant/store/purchase</span> to debit their balance when they buy.</li>
        </ol>
      </div>

      {/* Endpoint groups */}
      {GROUPS.map((g) => (
        <div className="panel" id={g.id} key={g.id}>
          <h2>{g.title}</h2>
          <p className="muted" style={{ marginTop: -6 }}>{g.blurb}</p>
          {g.eps.map((e, i) => (
            <div key={i} style={{ borderTop: i ? '1px solid #ffffff14' : 'none', paddingTop: i ? 16 : 4, marginTop: i ? 16 : 0 }}>
              <div style={{ display: 'flex', alignItems: 'center', flexWrap: 'wrap', gap: 6 }}>
                <Method m={e.m} />
                <span className="mono" style={{ fontSize: 13 }}>{e.path}</span>
                {e.perm && <span className="badge PENDING" style={{ marginLeft: 6 }}>{e.perm}</span>}
                {e.auth === 'none' && <span className="badge ACTIVE" style={{ marginLeft: 6 }}>no auth</span>}
              </div>
              <p style={{ margin: '8px 0' }}>{e.desc}</p>
              {e.params && e.params.length > 0 && (
                <table style={{ fontSize: 13 }}>
                  <thead><tr><th>Parameter</th><th>In</th><th>Type / constraints</th><th>Req?</th><th>Notes</th></tr></thead>
                  <tbody>
                    {e.params.map((p) => (
                      <tr key={p.name}>
                        <td className="mono" style={{ whiteSpace: 'nowrap' }}>{p.name}</td>
                        <td><span className="badge" style={{ background: `${locColor[p.loc]}22`, color: locColor[p.loc], border: `1px solid ${locColor[p.loc]}55` }}>{p.loc}</span></td>
                        <td className="muted">{p.type}</td>
                        <td>{p.req ? <span style={{ color: '#f59e0b', fontWeight: 700 }}>yes</span> : <span className="faint">no</span>}</td>
                        <td className="muted">{p.desc}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              )}
              {e.res && (<><div className="faint" style={{ fontSize: 12, marginTop: 10 }}>Response</div><Code id={`${g.id}-${i}-res`} copied={copied} onCopy={copy}>{e.res}</Code></>)}
            </div>
          ))}
        </div>
      ))}

      {/* Webhooks */}
      <div className="panel" id="webhooks">
        <h2>Webhooks</h2>
        <p className="muted" style={{ marginTop: -6 }}>
          Add an https endpoint on the <b>Webhooks</b> page (or let <b>Easy Setup</b> register it) and copy its secret
          (<span className="mono">whsec_...</span>, shown once). We POST a signed JSON event on every state change.
        </p>
        <div className="faint" style={{ fontSize: 12 }}>Event types</div>
        <div className="chips" style={{ margin: '6px 0 14px' }}>
          {WEBHOOK_EVENTS.map((ev) => <span key={ev} className="chip mono" style={{ cursor: 'default' }}>{ev}</span>)}
        </div>
        <div className="faint" style={{ fontSize: 12 }}>Delivery + payload (store top-up shown)</div>
        <Code id="wh" copied={copied} onCopy={copy}>{webhookPayload}</Code>
        <div className="help-tip">💡 Verify on the RAW body: <span className="mono">Orizen::verifyWebhook(raw, sig, ts, whsec)</span> (PHP) / <span className="mono">Orizen.verifyWebhook(...)</span> (Node). Return 2xx fast; non-2xx retries with exponential backoff, then dead-letters.</div>
      </div>
    </>
  );
}
