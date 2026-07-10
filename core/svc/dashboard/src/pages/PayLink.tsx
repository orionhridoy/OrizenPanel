import { FormEvent, useEffect, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { api } from '../api/client';
import { InvoiceView } from '../api/types';
import Logo from '../components/Logo';

interface LinkView {
  slug: string;
  title: string;
  description: string | null;
  merchantName: string;
  fiatCurrency: string | null;
  fiatAmount: string | null;
  assetCode: string | null;
  cryptoAmount: string | null;
  allowCustomAmount: boolean;
  assets: Array<{ code: string; displayName: string }>;
}

export default function PayLink(): JSX.Element {
  const { slug } = useParams<{ slug: string }>();
  const navigate = useNavigate();
  const [link, setLink] = useState<LinkView | null>(null);
  const [error, setError] = useState('');
  const [asset, setAsset] = useState('');
  const [amount, setAmount] = useState('');
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    api<LinkView>(`/public/links/${slug}`, { auth: false })
      .then((l) => {
        setLink(l);
        setAsset(l.assetCode ?? l.assets[0]?.code ?? '');
        if (l.fiatAmount) setAmount(l.fiatAmount);
        if (l.cryptoAmount) setAmount(l.cryptoAmount);
      })
      .catch((e) => setError((e as Error).message));
  }, [slug]);

  const pay = async (event: FormEvent): Promise<void> => {
    event.preventDefault();
    if (!link) return;
    setBusy(true);
    setError('');
    try {
      const body: Record<string, unknown> = {};
      if (!link.assetCode) body.assetCode = asset;
      if (link.allowCustomAmount) {
        if (link.fiatCurrency) {
          body.fiatAmount = amount;
          body.fiatCurrency = link.fiatCurrency;
        } else {
          body.amount = amount;
        }
      }
      const invoice = await api<InvoiceView>(`/public/links/${slug}/invoice`, {
        method: 'POST',
        auth: false,
        body,
      });
      navigate(`/checkout/${invoice.id}`);
    } catch (e) {
      setError((e as Error).message);
    } finally {
      setBusy(false);
    }
  };

  if (error && !link) {
    return (
      <div className="checkout-page">
        <div className="checkout-card">
          <div className="co-top" />
          <div className="co-body">
            <div className="error">{error}</div>
            <p className="faint">This payment link may have been disabled.</p>
          </div>
        </div>
      </div>
    );
  }
  if (!link) {
    return (
      <div className="checkout-page">
        <div className="checkout-card"><div className="co-top" /><div className="co-body"><span className="spinner" /> Loading...</div></div>
      </div>
    );
  }

  const fixedPrice = link.fiatAmount
    ? `${link.fiatAmount} ${link.fiatCurrency}`
    : link.cryptoAmount
      ? `${link.cryptoAmount} ${link.assetCode}`
      : null;

  return (
    <div className="checkout-page">
      <form className="checkout-card" onSubmit={(e) => void pay(e)} style={{ textAlign: 'center' }}>
        <div className="co-top" />
        <div className="co-body">
          <div className="auth-brand"><Logo size={30} /><h1 style={{ fontSize: 20 }}>{link.merchantName}</h1></div>
          <h2 style={{ fontSize: 18, margin: '6px 0', justifyContent: 'center' }}>{link.title}</h2>
          {link.description && <p className="muted">{link.description}</p>}
          {fixedPrice && <div className="co-amount" style={{ fontSize: 28 }}>{fixedPrice}</div>}

          {!link.assetCode && (
            <>
              <label style={{ textAlign: 'center', marginTop: 14 }}>Pay with</label>
              <div className="chips" style={{ justifyContent: 'center', marginBottom: 14 }}>
                {link.assets.map((a) => (
                  <span key={a.code} className={`chip ${asset === a.code ? 'active' : ''}`} onClick={() => setAsset(a.code)}>
                    {a.code}
                  </span>
                ))}
              </div>
            </>
          )}

          {link.allowCustomAmount && (
            <div className="field" style={{ textAlign: 'left', marginTop: 6 }}>
              <label>Amount {link.fiatCurrency ? `(${link.fiatCurrency})` : `(${asset})`}</label>
              <input value={amount} onChange={(e) => setAmount(e.target.value)} inputMode="decimal"
                placeholder={link.fiatCurrency ? '20.00' : '0.01'} required autoFocus />
            </div>
          )}

          {error && <div className="error">{error}</div>}
          <button type="submit" className="btn-lg btn-grad" style={{ marginTop: 8 }}
            disabled={busy || !asset || (link.allowCustomAmount && !amount)}>
            {busy ? <><span className="spinner" /> Preparing...</> : 'Continue to payment →'}
          </button>
        </div>
        <div className="co-foot">SECURED BY ORIZEN PAY</div>
      </form>
    </div>
  );
}
