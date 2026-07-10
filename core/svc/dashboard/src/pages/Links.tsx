import { FormEvent, useEffect, useState } from 'react';
import { api } from '../api/client';
import { HelpButton } from '../components/Help';
import Pager from '../components/Pager';

interface LinkRow {
  id: string;
  slug: string;
  title: string;
  url: string;
  fiat_currency: string | null;
  fiat_amount: string | null;
  asset_code: string | null;
  crypto_amount: string | null;
  allow_custom_amount: boolean;
  is_active: boolean;
  times_used: number;
  created_at: string;
}

const ASSETS = ['BTC', 'LTC', 'ETH', 'XRP', 'USDT_TRC20', 'USDC_ERC20'];
const PAGE = 25;

export default function Links(): JSX.Element {
  const [links, setLinks] = useState<LinkRow[]>([]);
  const [total, setTotal] = useState(0);
  const [offset, setOffset] = useState(0);
  const [title, setTitle] = useState('');
  const [mode, setMode] = useState<'fiat' | 'crypto' | 'open'>('fiat');
  const [fiatCurrency, setFiatCurrency] = useState('USD');
  const [amount, setAmount] = useState('');
  const [asset, setAsset] = useState('BTC');
  const [copied, setCopied] = useState('');
  const [error, setError] = useState('');

  const load = async (at = offset): Promise<void> => {
    const r = await api<{ items: LinkRow[]; total: number }>(`/dashboard/links?limit=${PAGE}&offset=${at}`);
    setLinks(r.items);
    setTotal(r.total);
  };

  useEffect(() => {
    void load().catch((e) => setError((e as Error).message));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [offset]);

  const create = async (event: FormEvent): Promise<void> => {
    event.preventDefault();
    setError('');
    try {
      await api('/dashboard/links', {
        method: 'POST',
        body: {
          title,
          ...(mode === 'fiat' ? { fiatAmount: amount, fiatCurrency } : {}),
          ...(mode === 'crypto' ? { assetCode: asset, cryptoAmount: amount } : {}),
          ...(mode === 'open' ? { allowCustomAmount: true, fiatCurrency } : {}),
        },
      });
      setTitle('');
      setAmount('');
      await load();
    } catch (e) {
      setError((e as Error).message);
    }
  };

  const toggle = async (link: LinkRow): Promise<void> => {
    await api(`/dashboard/links/${link.id}`, { method: 'PATCH', body: { isActive: !link.is_active } });
    await load();
  };

  const copy = async (value: string, id: string): Promise<void> => {
    await navigator.clipboard.writeText(value);
    setCopied(id);
    setTimeout(() => setCopied(''), 1500);
  };

  const priceOf = (l: LinkRow): string =>
    l.fiat_amount ? `${l.fiat_amount} ${l.fiat_currency}`
    : l.crypto_amount ? `${l.crypto_amount} ${l.asset_code}`
    : `payer chooses${l.fiat_currency ? ` (${l.fiat_currency})` : ''}`;

  return (
    <>
      <h1>Payment Links<HelpButton topic="links" /></h1>
      <div className="panel">
        <h2>Create a link <span className="tag">no code needed</span></h2>
        <p className="muted" style={{ marginTop: -6 }}>
          Share the URL anywhere - email, chat, social, QR on the counter. The payer picks the coin
          and pays; a fresh address is generated for every payment.
        </p>
        <form onSubmit={(e) => void create(e)}>
          <div className="row">
            <div className="field" style={{ flex: 1, minWidth: 200 }}>
              <label>Title (shown to the payer)</label>
              <input value={title} onChange={(e) => setTitle(e.target.value)} placeholder="Pro License / Donation / Table 5" required maxLength={140} />
            </div>
            <div className="field" style={{ width: 190 }}>
              <label>Pricing</label>
              <select value={mode} onChange={(e) => setMode(e.target.value as typeof mode)}>
                <option value="fiat">Fixed fiat price</option>
                <option value="crypto">Fixed crypto price</option>
                <option value="open">Payer enters amount</option>
              </select>
            </div>
            {mode !== 'open' && (
              <div className="field" style={{ width: 140 }}>
                <label>Amount</label>
                <input value={amount} onChange={(e) => setAmount(e.target.value)} placeholder={mode === 'fiat' ? '20.00' : '0.01'} required />
              </div>
            )}
            {(mode === 'fiat' || mode === 'open') && (
              <div className="field" style={{ width: 110 }}>
                <label>Currency</label>
                <select value={fiatCurrency} onChange={(e) => setFiatCurrency(e.target.value)}>
                  <option>USD</option><option>EUR</option><option>GBP</option>
                </select>
              </div>
            )}
            {mode === 'crypto' && (
              <div className="field" style={{ width: 150 }}>
                <label>Asset</label>
                <select value={asset} onChange={(e) => setAsset(e.target.value)}>
                  {ASSETS.map((a) => <option key={a}>{a}</option>)}
                </select>
              </div>
            )}
            <button type="submit">Create link</button>
          </div>
        </form>
        {error && <div className="error">{error}</div>}
      </div>

      <div className="panel">
        <table>
          <thead>
            <tr><th>Title</th><th>Price</th><th>Link</th><th>Used</th><th>Status</th><th></th></tr>
          </thead>
          <tbody>
            {links.map((l) => (
              <tr key={l.id}>
                <td><strong>{l.title}</strong></td>
                <td>{priceOf(l)}</td>
                <td>
                  <span className="mono">{l.url.replace(/^https?:\/\//, '')}</span>{' '}
                  <button className="ghost" onClick={() => void copy(l.url, l.id)}>{copied === l.id ? '✓' : 'copy'}</button>{' '}
                  <a href={l.url} target="_blank" rel="noopener noreferrer">open ↗</a>
                </td>
                <td>{l.times_used}</td>
                <td><span className={`badge ${l.is_active ? 'ACTIVE' : 'REJECTED'}`}>{l.is_active ? 'ACTIVE' : 'DISABLED'}</span></td>
                <td><button className="secondary" onClick={() => void toggle(l)}>{l.is_active ? 'Disable' : 'Enable'}</button></td>
              </tr>
            ))}
            {links.length === 0 && <tr><td colSpan={6} className="muted">No payment links yet - create one above.</td></tr>}
          </tbody>
        </table>
        <Pager offset={offset} limit={PAGE} count={links.length} total={total} onPage={setOffset} />
      </div>
    </>
  );
}
