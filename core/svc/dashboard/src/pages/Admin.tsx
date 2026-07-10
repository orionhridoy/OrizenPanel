import { useEffect, useState } from 'react';
import { api } from '../api/client';
import { AssetRow, MerchantProfile, NodeStatus, WithdrawalRow, fromBase } from '../api/types';
import { HelpButton } from '../components/Help';
import Pager from '../components/Pager';

interface WalletRow {
  id: string;
  assetCode: string;
  type: string;
  name: string;
  address: string | null;
  xpubFingerprint: string | null;
  issuedAddresses: number;
  isActive: boolean;
}

interface AuditRow {
  id: string;
  actor_type: string;
  action: string;
  resource_type: string | null;
  resource_id: string | null;
  ip: string | null;
  created_at: string;
}

interface TreasuryBalance {
  asset_code: string;
  type: string;
  balance: string;
}

export default function Admin(): JSX.Element {
  const [pending, setPending] = useState<WithdrawalRow[]>([]);
  const [merchants, setMerchants] = useState<MerchantProfile[]>([]);
  const [wallets, setWallets] = useState<WalletRow[]>([]);
  const [treasury, setTreasury] = useState<TreasuryBalance[]>([]);
  const [audit, setAudit] = useState<AuditRow[]>([]);
  const [assets, setAssets] = useState<AssetRow[]>([]);
  const [nodes, setNodes] = useState<Array<NodeStatus & { syncEnabled: boolean }>>([]);
  const [registrationOpen, setRegistrationOpen] = useState(true);
  const [merchantOffset, setMerchantOffset] = useState(0);
  const [auditOffset, setAuditOffset] = useState(0);
  const [error, setError] = useState('');
  const PAGE = 25;

  const load = async (): Promise<void> => {
    try {
      const [p, m, w, t, a, as, sy, reg] = await Promise.all([
        api<WithdrawalRow[]>('/admin/withdrawals/pending'),
        api<MerchantProfile[]>(`/admin/merchants?limit=${PAGE}&offset=${merchantOffset}`),
        api<WalletRow[]>('/admin/wallets'),
        api<TreasuryBalance[]>('/admin/wallets/treasury-balances'),
        api<AuditRow[]>(`/admin/audit-logs?limit=${PAGE}&offset=${auditOffset}`),
        api<AssetRow[]>('/admin/assets'),
        api<{ chains: Array<NodeStatus & { syncEnabled: boolean }> }>('/admin/sync'),
        api<{ enabled: boolean }>('/admin/settings/registration'),
      ]);
      setPending(p);
      setMerchants(m);
      setWallets(w);
      setTreasury(t);
      setAudit(a);
      setAssets(as);
      setNodes(sy.chains);
      setRegistrationOpen(reg.enabled);
    } catch (err) {
      setError((err as Error).message);
    }
  };

  const toggleRegistration = async (enabled: boolean): Promise<void> => {
    await api('/admin/settings/registration', { method: 'PATCH', body: { enabled } });
    await load();
  };

  const toggleAsset = async (code: string, enabled: boolean): Promise<void> => {
    await api(`/admin/assets/${code}`, { method: 'PATCH', body: { enabled } });
    await load();
  };

  const toggleChainSync = async (chain: string, enabled: boolean): Promise<void> => {
    await api(`/admin/sync/${chain}`, { method: 'PATCH', body: { enabled } });
    await load();
  };

  const setAllSync = async (enabled: boolean): Promise<void> => {
    await api('/admin/sync', { method: 'PATCH', body: { enabled } });
    await load();
  };

  useEffect(() => {
    void load();
    const timer = setInterval(() => void load(), 20_000);
    return () => clearInterval(timer);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [merchantOffset, auditOffset]);

  const decide = async (id: string, action: 'approve' | 'reject'): Promise<void> => {
    if (!window.confirm(`${action} this withdrawal?`)) return;
    await api(`/admin/withdrawals/${id}/${action}`, { method: 'POST' });
    await load();
  };

  const setStatus = async (merchant: MerchantProfile): Promise<void> => {
    const next = merchant.status === 'ACTIVE' ? 'SUSPENDED' : 'ACTIVE';
    if (!window.confirm(`Set ${merchant.email} to ${next}?`)) return;
    await api(`/admin/merchants/${merchant.id}/status`, { method: 'PATCH', body: { status: next } });
    await load();
  };

  return (
    <>
      <h1>Administration<HelpButton topic="admin" /></h1>
      {error && <div className="error">{error}</div>}

      <div className="grid cols-2">
        <div className="panel">
          <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
            <h2 style={{ margin: 0 }}>Live sync - per chain<HelpButton topic="liveSync" /></h2>
            <div style={{ display: 'flex', gap: 8 }}>
              <button className="secondary" onClick={() => void setAllSync(true)}>Resume all</button>
              <button className="secondary" onClick={() => void setAllSync(false)}>Pause all</button>
            </div>
          </div>
          <p className="faint" style={{ fontSize: 12, marginTop: 6 }}>
            <b>Node status</b> = is the blockchain node synced (infrastructure).
            <b> Detection</b> = whether Orizen Pay scans that chain for payments to your invoices -
            pausing it does not touch the node. "Offline" means no reachable node yet.
          </p>
          <div className="divider" />
          <table>
            <thead><tr><th>Chain</th><th>Node status</th><th>Height</th><th>Detection</th><th></th></tr></thead>
            <tbody>
              {nodes.map((n) => (
                <tr key={n.chain}>
                  <td><strong>{n.chain}</strong></td>
                  <td style={{ width: 150 }}>
                    {n.synced ? (
                      <span className="success">✓ synced</span>
                    ) : n.last_error ? (
                      <span className="badge REJECTED" title={n.last_error}>⚠ offline</span>
                    ) : (
                      <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                        <span>{(Number(n.progress) * 100).toFixed(1)}%</span>
                        <div className="progressbar" style={{ flex: 1, maxWidth: 70 }}><div style={{ width: `${Number(n.progress) * 100}%` }} /></div>
                      </div>
                    )}
                  </td>
                  <td>{Number(n.height) > 0 ? Number(n.height).toLocaleString() : '-'}</td>
                  <td><span className={`badge ${n.syncEnabled ? 'ACTIVE' : 'PENDING'}`}>{n.syncEnabled ? 'ON' : 'PAUSED'}</span></td>
                  <td>
                    <button className={n.syncEnabled ? 'danger' : 'secondary'} onClick={() => void toggleChainSync(n.chain, !n.syncEnabled)}>
                      {n.syncEnabled ? 'Pause' : 'Resume'}
                    </button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>

        <div className="panel">
          <h2>Crypto assets<HelpButton topic="cryptoAssets" /></h2>
          <p className="muted" style={{ marginTop: -6 }}>Turn a coin on/off for the whole gateway.</p>
          <table>
            <thead><tr><th>Asset</th><th>Status</th><th></th></tr></thead>
            <tbody>
              {assets.map((a) => (
                <tr key={a.code}>
                  <td><strong>{a.code}</strong> <span className="faint">{a.display_name}</span></td>
                  <td><span className={`badge ${a.enabled ? 'ACTIVE' : 'REJECTED'}`}>{a.enabled ? 'ON' : 'OFF'}</span></td>
                  <td><button className={a.enabled ? 'danger' : 'secondary'} onClick={() => void toggleAsset(a.code, !a.enabled)}>{a.enabled ? 'Disable' : 'Enable'}</button></td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </div>

      <div className="panel">
        <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', flexWrap: 'wrap', gap: 10 }}>
          <div>
            <h2 style={{ margin: 0 }}>New account registration</h2>
            <p className="muted" style={{ margin: '4px 0 0' }}>
              Controls whether anyone can create a merchant account at the sign-up page. Turn it off once
              your own accounts exist to stop strangers signing up. Existing accounts and logins are unaffected.
            </p>
          </div>
          <div style={{ display: 'flex', alignItems: 'center', gap: 12 }}>
            <span className={`badge ${registrationOpen ? 'ACTIVE' : 'REJECTED'}`}>{registrationOpen ? 'OPEN' : 'CLOSED'}</span>
            <button className={registrationOpen ? 'danger' : 'secondary'} onClick={() => void toggleRegistration(!registrationOpen)}>
              {registrationOpen ? 'Close sign-ups' : 'Open sign-ups'}
            </button>
          </div>
        </div>
      </div>

      <div className="panel">
        <h2>Withdrawals awaiting approval</h2>
        <table>
          <thead>
            <tr><th>Requested</th><th>Merchant</th><th>Asset</th><th>Amount</th><th>Destination</th><th>Risk flags</th><th></th></tr>
          </thead>
          <tbody>
            {pending.map((w) => (
              <tr key={w.id}>
                <td>{new Date(w.created_at).toLocaleString()}</td>
                <td>{w.merchant_email}</td>
                <td>{w.asset_code}</td>
                <td>{fromBase(w.amount, w.asset_code)}</td>
                <td className="mono">{w.destination_address.slice(0, 20)}...</td>
                <td className="muted">{(w.risk_flags ?? []).join(', ')}</td>
                <td style={{ whiteSpace: 'nowrap' }}>
                  <button onClick={() => void decide(w.id, 'approve')}>Approve</button>{' '}
                  <button className="danger" onClick={() => void decide(w.id, 'reject')}>Reject</button>
                </td>
              </tr>
            ))}
            {pending.length === 0 && <tr><td colSpan={7} className="muted">Nothing pending.</td></tr>}
          </tbody>
        </table>
      </div>

      <div className="grid cols-2">
        <div className="panel">
          <h2>Merchants</h2>
          <table>
            <thead><tr><th>Email</th><th>Role</th><th>Status</th><th></th></tr></thead>
            <tbody>
              {merchants.map((merchant) => (
                <tr key={merchant.id}>
                  <td>{merchant.email}</td>
                  <td>{merchant.role}</td>
                  <td><span className={`badge ${merchant.status}`}>{merchant.status}</span></td>
                  <td>
                    {merchant.role === 'MERCHANT' && (
                      <button className="secondary" onClick={() => void setStatus(merchant)}>
                        {merchant.status === 'ACTIVE' ? 'Suspend' : 'Reactivate'}
                      </button>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
          <Pager offset={merchantOffset} limit={PAGE} count={merchants.length} onPage={setMerchantOffset} />
        </div>

        <div className="panel">
          <h2>System accounts (custody)</h2>
          <table>
            <thead><tr><th>Asset</th><th>Account</th><th>Balance (signed)</th></tr></thead>
            <tbody>
              {treasury.map((row) => (
                <tr key={`${row.asset_code}-${row.type}`}>
                  <td>{row.asset_code}</td>
                  <td className="muted">{row.type}</td>
                  <td>{fromBase(row.balance.replace('-', ''), row.asset_code)}{row.balance.startsWith('-') ? ' (dr)' : ''}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </div>

      <div className="panel">
        <h2>Wallets</h2>
        <table>
          <thead>
            <tr><th>Asset</th><th>Type</th><th>Address / xpub</th><th>Issued addresses</th><th>Active</th></tr>
          </thead>
          <tbody>
            {wallets.map((wallet) => (
              <tr key={wallet.id}>
                <td>{wallet.assetCode}</td>
                <td>{wallet.type}</td>
                <td className="mono">{wallet.address ?? wallet.xpubFingerprint ?? '-'}</td>
                <td>{wallet.issuedAddresses}</td>
                <td>{wallet.isActive ? '✓' : '-'}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      <div className="panel">
        <h2>Recent audit log</h2>
        <table>
          <thead>
            <tr><th>Time</th><th>Actor</th><th>Action</th><th>Resource</th><th>IP</th></tr>
          </thead>
          <tbody>
            {audit.map((entry) => (
              <tr key={entry.id}>
                <td>{new Date(entry.created_at).toLocaleString()}</td>
                <td>{entry.actor_type}</td>
                <td>{entry.action}</td>
                <td className="muted">{entry.resource_type ? `${entry.resource_type}:${(entry.resource_id ?? '').slice(0, 8)}` : '-'}</td>
                <td className="muted">{entry.ip ?? '-'}</td>
              </tr>
            ))}
          </tbody>
        </table>
        <Pager offset={auditOffset} limit={PAGE} count={audit.length} onPage={setAuditOffset} />
      </div>
    </>
  );
}
