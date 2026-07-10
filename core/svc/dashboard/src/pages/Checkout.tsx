import { useEffect, useRef, useState } from 'react';
import { useParams } from 'react-router-dom';
import { QRCodeSVG } from 'qrcode.react';
import { API_BASE, api } from '../api/client';
import { InvoiceView, fromBase } from '../api/types';

const TERMINAL = new Set(['PAID', 'OVERPAID', 'UNDERPAID', 'EXPIRED', 'INVALID']);

interface LiveStatus {
  status: string;
  amountPaidPending: string;
  amountPaidConfirmed: string;
  expiresAt: string;
}

const IconClock = (): JSX.Element => (
  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={2} strokeLinecap="round" strokeLinejoin="round">
    <circle cx="12" cy="12" r="9" /><path d="M12 7v5l3 2" />
  </svg>
);
const IconLock = (): JSX.Element => (
  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={2} strokeLinecap="round" strokeLinejoin="round">
    <rect x="4" y="11" width="16" height="10" rx="2" /><path d="M8 11V7a4 4 0 0 1 8 0v4" />
  </svg>
);
const IconBack = (): JSX.Element => (
  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={2} strokeLinecap="round" strokeLinejoin="round">
    <path d="M15 18l-6-6 6-6" />
  </svg>
);

/** 0x91d3f1741ff2b4030f19ef908e9b6b874c5b7d7d -> 0x91d3f174...4c5b7d7d */
function shorten(value: string): string {
  return value.length > 26 ? `${value.slice(0, 12)}...${value.slice(-8)}` : value;
}

export default function Checkout(): JSX.Element {
  const { id } = useParams<{ id: string }>();
  const [invoice, setInvoice] = useState<(InvoiceView & { merchantName: string }) | null>(null);
  const [live, setLive] = useState<LiveStatus | null>(null);
  const [remaining, setRemaining] = useState('');
  const [lowTime, setLowTime] = useState(false);
  const [copied, setCopied] = useState('');
  const [error, setError] = useState('');
  const sourceRef = useRef<EventSource | null>(null);

  useEffect(() => {
    api<InvoiceView & { merchantName: string }>(`/public/invoices/${id}`, { auth: false })
      .then((inv) => {
        setInvoice(inv);
        setLive({
          status: inv.status,
          amountPaidPending: inv.amountPaidPending,
          amountPaidConfirmed: inv.amountPaidConfirmed,
          expiresAt: inv.expiresAt,
        });
        if (!TERMINAL.has(inv.status)) {
          const source = new EventSource(`${API_BASE}/public/invoices/${id}/events`);
          source.onmessage = (event) => {
            const status = JSON.parse(event.data as string) as LiveStatus;
            setLive(status);
            if (TERMINAL.has(status.status)) source.close();
          };
          source.onerror = () => source.close();
          sourceRef.current = source;
        }
      })
      .catch((err) => setError((err as Error).message));
    return () => sourceRef.current?.close();
  }, [id]);

  useEffect(() => {
    if (!live) return;
    const tick = (): void => {
      const ms = new Date(live.expiresAt).getTime() - Date.now();
      if (ms <= 0) {
        setRemaining('0:00');
        return;
      }
      setLowTime(ms < 5 * 60000);
      const minutes = Math.floor(ms / 60000);
      const seconds = Math.floor((ms % 60000) / 1000);
      setRemaining(`${minutes}:${String(seconds).padStart(2, '0')}`);
    };
    tick();
    const timer = setInterval(tick, 1000);
    return () => clearInterval(timer);
  }, [live]);

  const copy = async (value: string, label: string): Promise<void> => {
    await navigator.clipboard.writeText(value);
    setCopied(label);
    setTimeout(() => setCopied(''), 1500);
  };

  const goBack = (): void => {
    if (invoice?.redirectUrl) {
      window.location.href = invoice.redirectUrl;
      return;
    }
    if (window.history.length > 1) {
      window.history.back();
      return;
    }
    window.close();
  };

  const shell = (inner: JSX.Element): JSX.Element => (
    <div className="checkout-page">
      <div className="checkout-card">
        <div className="co-top" />
        <div className="co-body">{inner}</div>
        <div className="co-foot"><IconLock /> SECURED BY ORIZEN PAY</div>
      </div>
    </div>
  );

  if (error) return shell(<div className="error">{error}</div>);
  if (!invoice || !live) return shell(<p className="muted"><span className="spinner" /> Loading invoice...</p>);

  const status = live.status;
  const paid = status === 'PAID' || status === 'OVERPAID';
  const failed = status === 'EXPIRED' || status === 'UNDERPAID' || status === 'INVALID';
  const confirming = status === 'SEEN' || status === 'CONFIRMING';
  const step = paid ? 3 : confirming ? 2 : 1;

  const due = Number(invoice.amountDueDecimal) || 0;
  const got =
    Number(fromBase(live.amountPaidPending, invoice.asset)) +
    Number(fromBase(live.amountPaidConfirmed, invoice.asset));
  const pct = due > 0 ? Math.min(100, Math.round((got / due) * 100)) : 0;

  return shell(
    <>
      <div className="co-head">
        <span className="co-head-left">
          <button type="button" className="co-back" onClick={goBack} aria-label="Go back"><IconBack /> Back</button>
          <span className="co-merchant"><span className="dot" />{invoice.merchantName}</span>
        </span>
        {!paid && !failed && (
          <span className={`co-timer ${lowTime ? 'low' : ''}`}><IconClock /> {remaining}</span>
        )}
      </div>

      {paid ? (
        <div className="co-success">
          <div className="co-check">✓</div>
          <div className="msg">Payment received</div>
          <div className="sub">
            {invoice.amountDueDecimal} {invoice.asset}{status === 'OVERPAID' ? ' (overpaid)' : ''} confirmed. You can close this page.
          </div>
          {invoice.redirectUrl && (
            <p style={{ marginTop: 16 }}>
              <a className="btn btn-grad" href={invoice.redirectUrl}>Return to merchant →</a>
            </p>
          )}
        </div>
      ) : (
        <>
          <div className="co-amount">{invoice.amountDueDecimal}<span className="asset">{invoice.asset}</span></div>
          {invoice.fiatAmount && (
            <div className="co-fiat">≈ {invoice.fiatAmount} {invoice.fiatCurrency} <span className="faint">· rate locked</span></div>
          )}
          {invoice.description && <div className="co-desc">{invoice.description}</div>}

          {failed ? (
            <>
              <div className="co-status" style={{ marginTop: 14 }}><span className={`badge ${status}`}>{status}</span></div>
              <p className="co-warn">
                {status === 'EXPIRED'
                  ? 'This invoice expired before payment was completed. Ask the merchant for a new one.'
                  : status === 'UNDERPAID'
                    ? 'The confirmed amount was below the invoice total. Please contact the merchant.'
                    : 'This invoice was invalidated. Please contact the merchant.'}
              </p>
            </>
          ) : (
            <>
              <div className="co-steps">
                <div className={`co-step ${step > 1 ? 'done' : 'now'}`}>
                  <div className="pt">{step > 1 ? '✓' : '1'}</div><div className="lb">Awaiting</div>
                </div>
                <div className={`co-step ${step > 2 ? 'done' : step === 2 ? 'now' : ''}`}>
                  <div className="pt">{step > 2 ? '✓' : '2'}</div><div className="lb">Confirming</div>
                </div>
                <div className="co-step">
                  <div className="pt">3</div><div className="lb">Paid</div>
                </div>
              </div>

              <div className="co-qr"><QRCodeSVG value={invoice.paymentUri} size={172} level="M" /></div>
              <div>
                <a className="btn btn-grad co-wallet" href={invoice.paymentUri}>Open in wallet app</a>
              </div>
              <div className="co-hint">OR SEND MANUALLY - TAP TO COPY</div>

              <div className={`co-copyrow ${copied === 'address' ? 'done' : ''}`} onClick={() => void copy(invoice.address, 'address')}>
                <div style={{ minWidth: 0 }}>
                  <div className="lbl">{invoice.asset} address</div>
                  <div className="val">{shorten(invoice.address)}</div>
                </div>
                <span className="act">{copied === 'address' ? 'Copied ✓' : 'Copy'}</span>
              </div>

              <div className={`co-copyrow ${copied === 'amount' ? 'done' : ''}`} onClick={() => void copy(invoice.amountDueDecimal, 'amount')}>
                <div style={{ minWidth: 0 }}>
                  <div className="lbl">Exact amount</div>
                  <div className="val">{invoice.amountDueDecimal} {invoice.asset}</div>
                </div>
                <span className="act">{copied === 'amount' ? 'Copied ✓' : 'Copy'}</span>
              </div>

              {invoice.destinationTag !== null && (
                <div className={`co-copyrow ${copied === 'tag' ? 'done' : ''}`} onClick={() => void copy(String(invoice.destinationTag), 'tag')} style={{ borderColor: 'var(--red)' }}>
                  <div style={{ minWidth: 0 }}>
                    <div className="lbl" style={{ color: 'var(--red)' }}>Destination tag - required</div>
                    <div className="val">{invoice.destinationTag}</div>
                  </div>
                  <span className="act">{copied === 'tag' ? 'Copied ✓' : 'Copy'}</span>
                </div>
              )}

              {confirming ? (
                <>
                  <div className="co-progress"><div style={{ width: `${pct}%` }} /></div>
                  <div className="co-meta">
                    Detected {got} / {invoice.amountDueDecimal} {invoice.asset} · {invoice.requiredConfirmations} confirmations needed
                  </div>
                </>
              ) : (
                <div className="co-meta">Waiting for your payment · updates automatically</div>
              )}
            </>
          )}
        </>
      )}
    </>,
  );
}
