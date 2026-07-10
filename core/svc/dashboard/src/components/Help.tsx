import { useState } from 'react';

interface HelpDoc {
  title: string;
  what: string;
  steps: string[];
  tips?: string[];
}

export const HELP: Record<string, HelpDoc> = {
  overview: {
    title: 'Overview',
    what: 'Your at-a-glance dashboard: available/pending/locked balances per asset, and the live sync + payment-engine status of each blockchain.',
    steps: [
      'Available = you can withdraw it now. Pending = confirmed but held by your settlement mode. Locked = reserved by an in-flight withdrawal.',
      'The node table shows each chain’s sync progress. The payment engine turns ACTIVE for a chain only once its node is fully synced.',
      'Balances refresh automatically every 15 seconds.',
    ],
    tips: ['If a chain shows “waiting for sync”, payments on that chain aren’t detected yet - that’s normal during initial node sync.'],
  },
  invoices: {
    title: 'Invoices',
    what: 'One-off crypto payment requests. Create an invoice for a specific amount, share its checkout page, and the funds are credited to your balance once confirmed.',
    steps: [
      'Click “New invoice”, pick an asset and amount (optionally an order id / description).',
      'Set how long the payer has with “Expires in” (minutes, hours or days - up to 30 days). Leave it empty for the 1-hour default.',
      'Share the generated checkout link with your customer - it shows a QR + address and updates live.',
      'When enough confirmations arrive, the invoice becomes PAID and your balance goes up.',
      'Click any invoice to see its detected payments and confirmation progress.',
    ],
    tips: ['Every invoice gets a brand-new address (never reused). XRP invoices use a unique destination tag instead.'],
  },
  withdrawals: {
    title: 'Withdrawals',
    what: 'Move your available balance out to an external wallet address you control.',
    steps: [
      'Enter the asset, amount and destination address (XRP also needs a destination tag).',
      'Funds are locked immediately and the withdrawal is signed + broadcast by the isolated signer.',
      'Large amounts (over the configured threshold) wait for admin approval before broadcasting.',
      'Track status: PENDING → APPROVED → BROADCAST → CONFIRMED.',
    ],
    tips: ['You can only withdraw the Available balance. Use Settings → Settle to release Pending funds first.'],
  },
  store: {
    title: 'Store - balance top-ups',
    what: 'Let your customers load a crypto balance and spend it on your tools. Top-ups credit each customer’s wallet; purchases move that credit to your gateway balance.',
    steps: [
      'Set a store name and click “Enable store”.',
      'Create an API key (API Keys page) with the store:manage permission - you’ll use it on your server.',
      'On your server, call POST /merchant/store/sessions with the customer’s id to get a hosted top-up URL, and send them there.',
      'When a customer buys a tool, call POST /merchant/store/purchase to debit their balance and credit yours.',
      'Use “Generate link” below to preview the exact page your customers will see.',
    ],
    tips: ['Copy the ready-made Node/PHP snippets below. The full SDK files live in docs/integration/.'],
  },
  easySetup: {
    title: 'Easy Setup',
    what: 'The fastest way to go live. Enter your website, and we create your API key, register your webhook, and generate every file you need - filled in with your credentials and labelled with exactly where to put it.',
    steps: [
      'Type your website address and pick what you want: customer balance top-ups, or a one-off checkout.',
      'Click “Set up my website” - we create the key + webhook for you.',
      'Download/copy each file into the same folder on your site (your web root). orizen-config.php holds your keys - keep it private.',
      'Paste the button HTML where customers should pay, then send a small test payment.',
    ],
    tips: ['If your site isn’t public yet, the webhook can’t auto-register - add it later on the Webhooks page and paste the whsec_ secret into orizen-config.php.'],
  },
  connect: {
    title: 'Connect a website',
    what: 'The fastest way to add crypto payments to your site. Tell us your site and how you charge, and we generate the exact code to paste - a no-code button, or a full API integration for carts.',
    steps: [
      'Enter your website name/URL.',
      'Pick “Payment button” for the simplest option - paste one line of HTML, no keys, nothing secret exposed.',
      'Or pick “Full API” to auto-create a dedicated API key and get server code for dynamic cart totals.',
      'Click Generate, then copy the code into your site. Done.',
    ],
    tips: ['The payment button opens a hosted, stylish checkout on this gateway - safe to paste on any site or share as a link/QR.'],
  },
  liveSync: {
    title: 'Live sync - per chain',
    what: 'Two separate things per chain: Node status (is the blockchain node synced - infrastructure you don’t control here) and Detection (whether Orizen Pay scans that chain for payments to your invoices - the switch you DO control).',
    steps: [
      'Node status shows synced / syncing % / offline - that’s the node itself.',
      'Detection ON means the gateway is watching that chain for incoming payments; PAUSED means it isn’t (the node stays synced regardless).',
      'Pause/Resume toggles detection for just that chain; “Pause all / Resume all” does every chain.',
      'A chain can be “synced” but you still pause detection (e.g. to stop accepting that coin, or reduce RPC load) - that’s why a synced chain still has a Pause button.',
    ],
    tips: ['Pausing detection never touches the node and resumes exactly where it left off. “Offline” = no reachable node yet.'],
  },
  cryptoAssets: {
    title: 'Crypto assets',
    what: 'Choose which cryptocurrencies your gateway accepts. Disabling a coin hides it from checkout, payment links, invoices and top-ups everywhere.',
    steps: [
      'Toggle any asset ON or OFF - it applies gateway-wide immediately.',
      'Disabled assets can’t be selected for new payments; existing invoices are unaffected.',
    ],
    tips: ['Only run/self-host the node for a coin you’ve enabled - see the README storage guide.'],
  },
  links: {
    title: 'Payment Links',
    what: 'Reusable, shareable checkout URLs - no code needed. Perfect for donations, social-media sales, point-of-sale QR codes, or one product. The payer opens the link, picks the coin, and pays; a fresh address is generated every time.',
    steps: [
      'Click “Create link”, give it a title the payer will see.',
      'Pick pricing: a fixed fiat price (payer chooses the coin), a fixed crypto price, or let the payer enter the amount (great for donations/tips).',
      'Copy the URL and share it anywhere - or print its QR at your counter.',
      'Every completed payment shows up in Invoices and credits your balance; Analytics tracks each link’s conversion.',
      'Disable a link any time - the URL stops working instantly.',
    ],
    tips: ['Links can also be created via API: POST /merchant/links.'],
  },
  analytics: {
    title: 'Analytics',
    what: 'Your revenue at a glance: paid invoices per day, confirmed revenue per asset, the payment funnel (created → detected → paid), and which payment links convert best.',
    steps: [
      'Switch the range with the 7 / 30 / 90-day chips.',
      'Conversion = paid ÷ created invoices in the period.',
      'Revenue counts confirmed on-chain funds only (PAID, OVERPAID and partial UNDERPAID amounts).',
    ],
  },
  apiKeys: {
    title: 'API Keys',
    what: 'Credentials your server uses to call the gateway programmatically (create invoices, run the store, request withdrawals).',
    steps: [
      'Click “Create key”, give it a label and select only the permissions it needs.',
      'Copy the key AND secret immediately - the secret is shown only once.',
      'On your server, sign every request: X-API-KEY, X-TIMESTAMP (ms), and X-SIGNATURE = HMAC-SHA256(secret, `timestamp.METHOD.path.sha256(body)`).',
      'Revoke a key any time - integrations using it stop instantly.',
    ],
    tips: ['Never put the key/secret in browser code. Use the provided orizen.js / orizen.php SDKs which sign for you.'],
  },
  apiDocs: {
    title: 'API Docs',
    what: 'Every API command in one place - copy-paste ready, with the exact request signing, all endpoints, and the webhook payloads. This is what your developer needs to wire the gateway into your site.',
    steps: [
      'Start with "Store quickstart": the full top-up → verify → add-balance → spend loop in one file.',
      'Create an API key (API Keys page) and keep the secret on your server - never in the browser.',
      'Sign each request: X-API-KEY, X-TIMESTAMP (ms), X-SIGNATURE = HMAC-SHA256(secret, timestamp.METHOD.path.sha256(body)). The path includes /api/v1.',
      'Add a webhook endpoint (Webhooks page) and verify its signature on the raw body before trusting an event.',
    ],
    tips: ['The Node & PHP SDKs in docs/integration/ sign requests and verify webhooks for you - you barely write any crypto code.'],
  },
  webhooks: {
    title: 'Webhooks',
    what: 'Real-time notifications pushed to your server when invoices and withdrawals change state (e.g. invoice.paid).',
    steps: [
      'Add an https endpoint URL. Copy its signing secret (whsec_...) - shown once.',
      'On each delivery, verify the header X-Orizen-Signature = v1=HMAC-SHA256(secret, `timestamp.rawBody`) using X-Orizen-Timestamp.',
      'Return HTTP 200 quickly. Non-2xx responses are retried with exponential backoff.',
      'Use the Deliveries view to inspect attempts and debug failures.',
    ],
    tips: ['The gateway blocks webhook URLs that resolve to private/internal addresses (SSRF protection).'],
  },
  settings: {
    title: 'Settings',
    what: 'Control when your money becomes withdrawable, how much underpayment you tolerate, and your account security.',
    steps: [
      'Payout timing: Instant = confirmed funds are withdrawable right away; Hold/Manual = they wait as Pending until you release them; Scheduled = released automatically on a timer.',
      'Use “Release pending funds now” to move Pending → Available on demand.',
      'Default invoice expiry: set how long a payer has to complete checkout when your site/API doesn’t specify one (5 min to 30 days; empty = the built-in 1 hour). Any single invoice can still override it.',
      'Accept slightly underpaid payments: e.g. 1% means a $100 order still counts as fully paid if the customer sends $99+ (crypto can arrive a little short due to sender fees/rounding). Set 0% to require the exact amount.',
      'Change your password (signs out other sessions) and turn on two-factor authentication.',
    ],
  },
  admin: {
    title: 'Admin',
    what: 'Operator controls: approve/reject large withdrawals, manage merchants and sign-ups, choose which coins are on, and monitor wallets, node health and the audit log.',
    steps: [
      'Approve or reject withdrawals awaiting review - approving signs + broadcasts them.',
      'New account registration: open or close self-service sign-ups. Closing it stops strangers registering; existing accounts still work.',
      'Crypto assets: turn each coin on/off gateway-wide. Live sync: pause/resume payment detection per chain.',
      'Suspend or reactivate merchants, and watch node status + the immutable audit trail.',
    ],
  },
};

export function HelpButton({ topic }: { topic: keyof typeof HELP }): JSX.Element {
  const [open, setOpen] = useState(false);
  const doc = HELP[topic];
  return (
    <>
      <button className="help-btn" onClick={() => setOpen(true)} title="How to use">
        ? How to use
      </button>
      {open && (
        <div className="help-overlay" onClick={() => setOpen(false)}>
          <div className="help-drawer" onClick={(e) => e.stopPropagation()}>
            <button className="ghost help-close" onClick={() => setOpen(false)}>✕</button>
            <h3>{doc.title}</h3>
            <div className="tag">How to use</div>
            <p className="help-what">{doc.what}</p>
            <ol className="help-steps">
              {doc.steps.map((s, i) => <li key={i}>{s}</li>)}
            </ol>
            {doc.tips?.map((t, i) => <div className="help-tip" key={i}>💡 {t}</div>)}
          </div>
        </div>
      )}
    </>
  );
}
