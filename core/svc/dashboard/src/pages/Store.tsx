import { FormEvent, useEffect, useState } from 'react';
import { api } from '../api/client';
import { StoreConfig } from '../api/types';
import { IconStore } from '../components/icons';
import { HelpButton } from '../components/Help';
import Pager from '../components/Pager';

interface StoreUserRow {
  id: string;
  external_ref: string;
  display_name: string | null;
  email: string | null;
  created_at: string;
  balances: Array<{ asset: string; balance: string }>;
}

export default function Store(): JSX.Element {
  const [config, setConfig] = useState<StoreConfig | null>(null);
  const [storeName, setStoreName] = useState('');
  const [users, setUsers] = useState<StoreUserRow[]>([]);
  const [userOffset, setUserOffset] = useState(0);
  const PAGE = 25;
  const [testRef, setTestRef] = useState('customer-001');
  const [testUrl, setTestUrl] = useState('');
  const [copied, setCopied] = useState('');
  const [msg, setMsg] = useState<{ kind: 'error' | 'success'; text: string } | null>(null);

  const load = async (): Promise<void> => {
    const cfg = await api<StoreConfig>('/dashboard/store/config');
    setConfig(cfg);
    setStoreName(cfg.storeName ?? '');
    if (cfg.storeEnabled) {
      setUsers(await api<StoreUserRow[]>(`/dashboard/store/users?limit=${PAGE}&offset=${userOffset}`));
    }
  };

  useEffect(() => {
    void load().catch((e) => setMsg({ kind: 'error', text: (e as Error).message }));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [userOffset]);

  const save = async (enabled: boolean): Promise<void> => {
    try {
      const cfg = await api<StoreConfig>('/dashboard/store/config', {
        method: 'PATCH',
        body: { storeEnabled: enabled, storeName: storeName || undefined },
      });
      setConfig((c) => (c ? { ...c, ...cfg } : c));
      setMsg({ kind: 'success', text: 'Store settings saved.' });
      if (enabled) await load();
    } catch (e) {
      setMsg({ kind: 'error', text: (e as Error).message });
    }
  };

  const makeTestLink = async (event: FormEvent): Promise<void> => {
    event.preventDefault();
    setMsg(null);
    try {
      const res = await api<{ url: string }>('/dashboard/store/test-session', {
        method: 'POST',
        body: { externalRef: testRef, displayName: 'Test customer' },
      });
      setTestUrl(res.url);
      await load();
    } catch (e) {
      setMsg({ kind: 'error', text: (e as Error).message });
    }
  };

  const copy = async (value: string, label: string): Promise<void> => {
    await navigator.clipboard.writeText(value);
    setCopied(label);
    setTimeout(() => setCopied(''), 1500);
  };

  if (!config) return <p className="muted">Loading...</p>;

  const base = config.storeId;
  const origin = window.location.origin;

  const sdkSnippet = `// server.js - using the Orizen Pay SDK (docs/integration/orizen.js)
const { Orizen } = require('./orizen');
const nvx = new Orizen({
  baseUrl: '${origin}',
  apiKey:  process.env.ORIZEN_API_KEY,     // orz_live_...  (API Keys page)
  apiSecret: process.env.ORIZEN_API_SECRET // shown once at creation
});

// 1) Give a customer the hosted top-up page
app.post('/topup-link', async (req, res) => {
  const s = await nvx.createStoreSession({ externalRef: user.id, displayName: user.name });
  res.json({ url: s.url });                // redirect the browser to s.url
});

// 2) Charge their balance when they buy a tool
app.post('/buy-tool', async (req, res) => {
  const r = await nvx.charge({
    externalRef: user.id, assetCode: 'ETH', amount: '0.01',
    description: 'Pro License', idempotencyKey: order.id
  });
  res.json({ ok: true, remaining: r.remainingBalanceDecimal });
});`;

  const buttonSnippet = `<!-- your store page - calls YOUR server above -->
<button onclick="addFunds()">+ Add funds</button>
<button onclick="buyTool()">Buy Pro Tool</button>
<script>
  async function addFunds() {
    const { url } = await (await fetch('/topup-link', { method:'POST' })).json();
    window.location.href = url;             // stylish hosted top-up page
  }
  async function buyTool() {
    const r = await fetch('/buy-tool', { method:'POST' });
    const d = await r.json();
    alert(r.ok ? 'Purchased! Balance left: ' + d.remaining : 'Top up first');
  }
</script>`;

  const CodeBlock = ({ id, code }: { id: string; code: string }): JSX.Element => (
    <div style={{ position: 'relative' }}>
      <button
        className="secondary"
        style={{ position: 'absolute', top: 8, right: 8, padding: '4px 10px', fontSize: 12 }}
        onClick={() => void copy(code, id)}
      >
        {copied === id ? 'Copied ✓' : 'Copy'}
      </button>
      <div className="codeblock">{code}</div>
    </div>
  );

  return (
    <>
      <h1><IconStore /> Store - accept balance top-ups<HelpButton topic="store" /></h1>
      {msg && <div className={msg.kind}>{msg.text}</div>}

      <div className="panel">
        <h2>Store status</h2>
        <p className="muted" style={{ marginTop: -6 }}>
          Let your customers load a balance with crypto and spend it on your tools.
          Top-ups credit each customer's wallet; purchases move credit to your gateway balance.
        </p>
        <div className="row" style={{ marginTop: 6 }}>
          <div className="field" style={{ flex: 1, minWidth: 220 }}>
            <label>Store display name (shown on the top-up page)</label>
            <input value={storeName} onChange={(e) => setStoreName(e.target.value)} placeholder="My Tools Shop" maxLength={120} />
          </div>
          <div style={{ display: 'flex', gap: 10 }}>
            <button onClick={() => void save(true)}>
              {config.storeEnabled ? 'Save' : 'Enable store'}
            </button>
            {config.storeEnabled && (
              <button className="secondary" onClick={() => void save(false)}>Disable</button>
            )}
          </div>
        </div>
        <div style={{ marginTop: 12 }}>
          Status:{' '}
          <span className={`badge ${config.storeEnabled ? 'ACTIVE' : 'PENDING'}`}>
            {config.storeEnabled ? 'ENABLED' : 'DISABLED'}
          </span>{' '}
          <span className="faint mono">store id: {base}</span>
        </div>
      </div>

      {config.storeEnabled && (
        <>
          <div className="panel">
            <h2>Try it - generate a top-up link</h2>
            <form onSubmit={(e) => void makeTestLink(e)}>
              <div className="row">
                <div className="field" style={{ width: 260 }}>
                  <label>Test customer reference</label>
                  <input value={testRef} onChange={(e) => setTestRef(e.target.value)} maxLength={200} />
                </div>
                <button type="submit">Generate link</button>
              </div>
            </form>
            {testUrl && (
              <div className="secret-box" style={{ marginTop: 14 }}>
                <div className="muted" style={{ marginBottom: 6 }}>Open this as your customer would:</div>
                <div className="pill-copy" style={{ width: '100%' }}>{testUrl}</div>
                <div style={{ marginTop: 10, display: 'flex', gap: 10 }}>
                  <a className="btn" href={testUrl} target="_blank" rel="noopener noreferrer">Open top-up page ↗</a>
                  <button className="secondary" onClick={() => void copy(testUrl, 'u')}>
                    {copied === 'u' ? 'Copied ✓' : 'Copy link'}
                  </button>
                </div>
              </div>
            )}
          </div>

          <div className="panel">
            <h2>Add to your store - full script <span className="tag">ready to copy</span></h2>
            <p className="muted" style={{ marginTop: -6 }}>
              Two steps: your server (below) + two buttons on your page. Your API key/secret stay on
              your server. Full SDKs (Node &amp; PHP) are in <span className="mono">docs/integration/</span>.
            </p>
            <ol className="help-steps" style={{ margin: '10px 0 18px' }}>
              <li>Create an API key with the <span className="mono">store:manage</span> permission (API Keys page).</li>
              <li>Drop <span className="mono">orizen.js</span> on your server and add the two routes below.</li>
              <li>Add the two buttons to your store page.</li>
              <li>Done - customers top up on the hosted page and pay from their balance.</li>
            </ol>

            <label>1 · Your server (Node.js)</label>
            <CodeBlock id="sdk" code={sdkSnippet} />

            <label style={{ marginTop: 16 }}>2 · Your store page (buttons)</label>
            <CodeBlock id="btn" code={buttonSnippet} />
          </div>

          <div className="panel">
            <h2>Customers &amp; balances</h2>
            <table>
              <thead>
                <tr><th>Customer</th><th>Email</th><th>Balances</th><th>Joined</th></tr>
              </thead>
              <tbody>
                {users.map((u) => (
                  <tr key={u.id}>
                    <td><strong>{u.display_name ?? u.external_ref}</strong><br /><span className="faint mono">{u.external_ref}</span></td>
                    <td className="muted">{u.email ?? '-'}</td>
                    <td>
                      {u.balances.filter((b) => b.balance && b.balance !== '0').length === 0
                        ? <span className="faint">no balance</span>
                        : u.balances.filter((b) => b.balance !== '0').map((b) => (
                            <span key={b.asset} className="tag" style={{ marginRight: 6 }}>{b.asset}: {b.balance}</span>
                          ))}
                    </td>
                    <td className="muted">{new Date(u.created_at).toLocaleDateString()}</td>
                  </tr>
                ))}
                {users.length === 0 && <tr><td colSpan={4} className="muted">No customers yet.</td></tr>}
              </tbody>
            </table>
            <Pager offset={userOffset} limit={PAGE} count={users.length} onPage={setUserOffset} />
          </div>
        </>
      )}
    </>
  );
}
