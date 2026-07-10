import { FormEvent, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { api } from '../api/client';
import { InvoiceView } from '../api/types';
import { HelpButton } from '../components/Help';

const ASSETS = ['BTC', 'LTC', 'ETH', 'XRP', 'USDT_TRC20', 'USDC_ERC20'];
const STATUSES = ['', 'NEW', 'SEEN', 'CONFIRMING', 'PAID', 'UNDERPAID', 'OVERPAID', 'EXPIRED', 'INVALID'];

export default function Invoices(): JSX.Element {
  const [items, setItems] = useState<InvoiceView[]>([]);
  const [total, setTotal] = useState(0);
  const [status, setStatus] = useState('');
  const [offset, setOffset] = useState(0);
  const [showCreate, setShowCreate] = useState(false);
  const [asset, setAsset] = useState('BTC');
  const [priceCurrency, setPriceCurrency] = useState('USD');
  const [amount, setAmount] = useState('');
  const [orderId, setOrderId] = useState('');
  const [description, setDescription] = useState('');
  const [expiryValue, setExpiryValue] = useState('');
  const [expiryUnit, setExpiryUnit] = useState<'minutes' | 'hours' | 'days'>('hours');
  const [error, setError] = useState('');
  const limit = 25;

  const UNIT_MINUTES: Record<typeof expiryUnit, number> = { minutes: 1, hours: 60, days: 1440 };

  const load = async (): Promise<void> => {
    const query = new URLSearchParams({ limit: String(limit), offset: String(offset) });
    if (status) query.set('status', status);
    const result = await api<{ items: InvoiceView[]; total: number }>(
      `/dashboard/invoices?${query.toString()}`,
    );
    setItems(result.items);
    setTotal(result.total);
  };

  useEffect(() => {
    void load().catch((err) => setError((err as Error).message));
  }, [status, offset]);

  const create = async (event: FormEvent): Promise<void> => {
    event.preventDefault();
    setError('');
    try {
      const invoice = await api<InvoiceView>('/dashboard/invoices', {
        method: 'POST',
        body: {
          assetCode: asset,
          ...(priceCurrency === 'CRYPTO' ? { amount } : { fiatAmount: amount, fiatCurrency: priceCurrency }),
          ...(orderId ? { orderId } : {}),
          ...(description ? { description } : {}),
          ...(expiryValue
            ? { expiresInMinutes: Math.round(Number(expiryValue) * UNIT_MINUTES[expiryUnit]) }
            : {}),
        },
      });
      setShowCreate(false);
      setAmount('');
      setOrderId('');
      setDescription('');
      setExpiryValue('');
      await load();
      window.open(invoice.checkoutUrl, '_blank', 'noopener');
    } catch (err) {
      setError((err as Error).message);
    }
  };

  return (
    <>
      <h1>Invoices<HelpButton topic="invoices" /></h1>
      <div className="panel">
        <div className="row" style={{ justifyContent: 'space-between', alignItems: 'center' }}>
          <div style={{ width: 220 }}>
            <select value={status} onChange={(e) => { setStatus(e.target.value); setOffset(0); }}>
              {STATUSES.map((s) => (
                <option key={s} value={s}>{s === '' ? 'All statuses' : s}</option>
              ))}
            </select>
          </div>
          <button onClick={() => setShowCreate((v) => !v)}>
            {showCreate ? 'Cancel' : 'New invoice'}
          </button>
        </div>

        {showCreate && (
          <form onSubmit={(e) => void create(e)} style={{ marginTop: 12 }}>
            <div className="row">
              <div style={{ width: 150 }}>
                <label>Pay in (asset)</label>
                <select value={asset} onChange={(e) => setAsset(e.target.value)}>
                  {ASSETS.map((a) => <option key={a}>{a}</option>)}
                </select>
              </div>
              <div style={{ width: 120 }}>
                <label>Price in</label>
                <select value={priceCurrency} onChange={(e) => setPriceCurrency(e.target.value)}>
                  <option value="USD">USD $</option>
                  <option value="EUR">EUR €</option>
                  <option value="GBP">GBP £</option>
                  <option value="CRYPTO">{asset}</option>
                </select>
              </div>
              <div style={{ width: 160 }}>
                <label>Amount ({priceCurrency === 'CRYPTO' ? asset : priceCurrency})</label>
                <input value={amount} onChange={(e) => setAmount(e.target.value)} placeholder={priceCurrency === 'CRYPTO' ? '0.001' : '20.00'} required />
              </div>
              <div style={{ width: 180 }}>
                <label>Order ID (optional)</label>
                <input value={orderId} onChange={(e) => setOrderId(e.target.value)} maxLength={120} />
              </div>
              <div style={{ flex: 1, minWidth: 160 }}>
                <label>Description (optional)</label>
                <input value={description} onChange={(e) => setDescription(e.target.value)} maxLength={500} />
              </div>
              <div style={{ width: 100 }}>
                <label>Expires in</label>
                <input value={expiryValue} onChange={(e) => setExpiryValue(e.target.value)}
                  inputMode="numeric" placeholder="default" />
              </div>
              <div style={{ width: 110 }}>
                <label>Unit</label>
                <select value={expiryUnit} onChange={(e) => setExpiryUnit(e.target.value as typeof expiryUnit)}>
                  <option value="minutes">minutes</option>
                  <option value="hours">hours</option>
                  <option value="days">days</option>
                </select>
              </div>
              <button type="submit">Create</button>
            </div>
            <p className="faint" style={{ fontSize: 12, margin: '8px 0 0' }}>
              Expiry: leave empty for the default (1 hour). Allowed range 5 minutes to 30 days.
            </p>
          </form>
        )}
        {error && <div className="error">{error}</div>}
      </div>

      <div className="panel">
        <table>
          <thead>
            <tr><th>Created</th><th>Order</th><th>Asset</th><th>Amount</th><th>Status</th><th>Checkout</th></tr>
          </thead>
          <tbody>
            {items.map((invoice) => (
              <tr key={invoice.id}>
                <td>{new Date(invoice.createdAt).toLocaleString()}</td>
                <td><Link to={`/invoices/${invoice.id}`}>{invoice.orderId ?? invoice.id.slice(0, 8)}</Link></td>
                <td>{invoice.asset}</td>
                <td>{invoice.amountDueDecimal}</td>
                <td><span className={`badge ${invoice.status}`}>{invoice.status}</span></td>
                <td><a href={invoice.checkoutUrl} target="_blank" rel="noopener noreferrer">open ↗</a></td>
              </tr>
            ))}
            {items.length === 0 && (
              <tr><td colSpan={6} className="muted">No invoices.</td></tr>
            )}
          </tbody>
        </table>
        <div className="row" style={{ marginTop: 10 }}>
          <button className="secondary" disabled={offset === 0} onClick={() => setOffset(Math.max(0, offset - limit))}>‹ Prev</button>
          <span className="muted" style={{ alignSelf: 'center' }}>
            {offset + 1}-{Math.min(offset + limit, total)} of {total}
          </span>
          <button className="secondary" disabled={offset + limit >= total} onClick={() => setOffset(offset + limit)}>Next ›</button>
        </div>
      </div>
    </>
  );
}
