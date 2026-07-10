import { FormEvent, useEffect, useRef, useState } from 'react';
import { QRCodeSVG } from 'qrcode.react';
import { API_BASE, apiToken } from '../api/client';
import { InvoiceView, StoreSessionView } from '../api/types';
import Logo from '../components/Logo';

const TERMINAL = new Set(['PAID', 'OVERPAID', 'UNDERPAID', 'EXPIRED', 'INVALID']);

export default function StorePage({ token }: { token: string }): JSX.Element {
  const [view, setView] = useState<StoreSessionView | null>(null);
  const [error, setError] = useState('');
  const [asset, setAsset] = useState('');
  const [payCurrency, setPayCurrency] = useState('USD');
  const [amount, setAmount] = useState('');
  const [invoice, setInvoice] = useState<InvoiceView | null>(null);
  const [status, setStatus] = useState('');
  const [copied, setCopied] = useState('');
  const [busy, setBusy] = useState(false);
  const sourceRef = useRef<EventSource | null>(null);

  const loadView = async (): Promise<void> => {
    try {
      const v = await apiToken<StoreSessionView>('/public/store/session', token);
      setView(v);
      if (!asset && v.assets[0]) setAsset(v.assets[0].code);
    } catch (e) {
      setError((e as Error).message);
    }
  };

  useEffect(() => {
    void loadView();
    return () => sourceRef.current?.close();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [token]);

  const startTopup = async (event: FormEvent): Promise<void> => {
    event.preventDefault();
    setError('');
    setBusy(true);
    try {
      const inv = await apiToken<InvoiceView>('/public/store/session/topup', token, {
        method: 'POST',
        body: {
          assetCode: asset,
          ...(payCurrency === 'CRYPTO' ? { amount } : { fiatAmount: amount, fiatCurrency: payCurrency }),
        },
      });
      setInvoice(inv);
      setStatus(inv.status);
      const src = new EventSource(`${API_BASE}/public/invoices/${inv.id}/events`);
      src.onmessage = (e) => {
        const s = JSON.parse(e.data as string) as { status: string };
        setStatus(s.status);
        if (TERMINAL.has(s.status)) {
          src.close();
          void loadView();
        }
      };
      src.onerror = () => src.close();
      sourceRef.current = src;
    } catch (e) {
      setError((e as Error).message);
    } finally {
      setBusy(false);
    }
  };

  const reset = (): void => {
    sourceRef.current?.close();
    setInvoice(null);
    setStatus('');
    setAmount('');
    void loadView();
  };

  const copy = async (value: string, label: string): Promise<void> => {
    await navigator.clipboard.writeText(value);
    setCopied(label);
    setTimeout(() => setCopied(''), 1500);
  };

  if (error && !view) {
    return (
      <div className="checkout-page">
        <div className="checkout-card">
          <div className="co-top" />
          <div className="co-body">
            <div className="error">{error}</div>
            <p className="faint">This top-up link may have expired. Ask the store for a new one.</p>
          </div>
        </div>
      </div>
    );
  }
  if (!view) {
    return (
      <div className="checkout-page">
        <div className="checkout-card"><div className="co-top" /><div className="co-body"><span className="spinner" /> Loading...</div></div>
      </div>
    );
  }

  const paid = TERMINAL.has(status) && (status === 'PAID' || status === 'OVERPAID');

  return (
    <div className="checkout-page">
      <div className="checkout-card">
        <div className="co-top" />
        <div className="co-body">
        <div className="auth-brand"><Logo size={30} /><h1 style={{ fontSize: 20 }}>{view.storeName}</h1></div>
        <p className="auth-sub">Balance for {view.externalRef}</p>

        {/* balances */}
        <div className="balance-grid" style={{ marginBottom: 18 }}>
          {view.assets.map((a) => {
            const bal = view.balances.find((b) => b.asset === a.code);
            return (
              <div className="balance-card" key={a.code}>
                <div className="asset">{a.code}</div>
                <div className="amt">{bal ? bal.balanceDecimal : '0'}</div>
              </div>
            );
          })}
        </div>

        {!invoice ? (
          <form onSubmit={(e) => void startTopup(e)}>
            <div className="divider" />
            <h2 style={{ justifyContent: 'center', display: 'flex' }}>Add funds</h2>
            <label>Pay with</label>
            <div className="chips" style={{ justifyContent: 'center', marginBottom: 14 }}>
              {view.assets.map((a) => (
                <span key={a.code} className={`chip ${asset === a.code ? 'active' : ''}`} onClick={() => setAsset(a.code)}>
                  {a.code}
                </span>
              ))}
            </div>
            <div className="field">
              <label>Amount</label>
              <div style={{ display: 'flex', gap: 8 }}>
                <select value={payCurrency} onChange={(e) => setPayCurrency(e.target.value)} style={{ width: 110 }}>
                  <option value="USD">USD $</option>
                  <option value="EUR">EUR €</option>
                  <option value="GBP">GBP £</option>
                  <option value="CRYPTO">{asset}</option>
                </select>
                <input value={amount} onChange={(e) => setAmount(e.target.value)} placeholder={payCurrency === 'CRYPTO' ? '0.05' : '20.00'} inputMode="decimal" required />
              </div>
            </div>
            {error && <div className="error">{error}</div>}
            <button type="submit" className="btn-lg" disabled={busy || !amount}>
              {busy ? <><span className="spinner" /> Generating...</> : 'Generate payment'}
            </button>
          </form>
        ) : paid ? (
          <>
            <div className="divider" />
            <p className="success" style={{ fontSize: 17 }}>✓ Payment received - balance updated!</p>
            <button className="btn-lg" onClick={reset}>Add more funds</button>
          </>
        ) : status === 'EXPIRED' || status === 'INVALID' || status === 'UNDERPAID' ? (
          <>
            <div className="divider" />
            <p className="error">This top-up {status.toLowerCase()}. Please try again.</p>
            <button className="btn-lg" onClick={reset}>Try again</button>
          </>
        ) : (
          <>
            <div className="divider" />
            <div className="amount">{invoice.amountDueDecimal}<span className="asset">{invoice.asset}</span></div>
            <span className={`badge ${status}`}>{status}</span>
            <div className="qr"><QRCodeSVG value={invoice.paymentUri} size={188} /></div>
            <p className="muted">Send exactly this amount to:</p>
            <div className="pill-copy" style={{ width: '100%', justifyContent: 'center' }}>{invoice.address}</div>
            <div style={{ marginTop: 10, display: 'flex', gap: 8, justifyContent: 'center' }}>
              <button className="secondary" onClick={() => void copy(invoice.address, 'a')}>{copied === 'a' ? 'Copied ✓' : 'Copy address'}</button>
              <button className="secondary" onClick={() => void copy(invoice.amountDueDecimal, 'm')}>{copied === 'm' ? 'Copied ✓' : 'Copy amount'}</button>
            </div>
            {invoice.destinationTag !== null && (
              <p className="error" style={{ marginTop: 12 }}>Destination tag REQUIRED: <span className="mono">{invoice.destinationTag}</span></p>
            )}
            <p className="faint" style={{ marginTop: 14, fontSize: 12 }}>Waiting for payment · needs {invoice.requiredConfirmations} confirmations · updates live</p>
            <button className="ghost" onClick={reset} style={{ marginTop: 6 }}>Cancel</button>
          </>
        )}
        </div>
        <div className="co-foot">SECURED BY ORIZEN PAY</div>
      </div>
    </div>
  );
}
