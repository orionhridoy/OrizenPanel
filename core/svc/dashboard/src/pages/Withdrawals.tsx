import { FormEvent, useEffect, useState } from 'react';
import { api } from '../api/client';
import { WithdrawalRow, explorerTxUrl, fromBase } from '../api/types';
import { HelpButton } from '../components/Help';
import Pager from '../components/Pager';

const ASSETS = ['BTC', 'LTC', 'ETH', 'XRP', 'USDT_TRC20', 'USDC_ERC20'];
const PAGE = 25;

export default function Withdrawals(): JSX.Element {
  const [items, setItems] = useState<WithdrawalRow[]>([]);
  const [offset, setOffset] = useState(0);
  const [asset, setAsset] = useState('BTC');
  const [amount, setAmount] = useState('');
  const [address, setAddress] = useState('');
  const [tag, setTag] = useState('');
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const [batchText, setBatchText] = useState('');
  const [batchResult, setBatchResult] = useState('');
  // "Require 2FA to withdraw" - fetched from the merchant's 2FA settings.
  const [w2fa, setW2fa] = useState<{ required: boolean; totp: boolean; telegram: boolean }>({
    required: false,
    totp: false,
    telegram: false,
  });
  const [twofaCode, setTwofaCode] = useState('');

  const load = async (at = offset): Promise<void> => {
    setItems(await api<WithdrawalRow[]>(`/dashboard/withdrawals?limit=${PAGE}&offset=${at}`));
  };

  useEffect(() => {
    void load().catch((err) => setError((err as Error).message));
    const timer = setInterval(() => void load().catch(() => undefined), 15_000);
    return () => clearInterval(timer);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [offset]);

  useEffect(() => {
    void api<{ totp: boolean; telegram: boolean; withdrawal2fa: boolean }>('/auth/2fa/status')
      .then((s) => setW2fa({ required: !!s.withdrawal2fa, totp: !!s.totp, telegram: !!s.telegram }))
      .catch(() => undefined);
  }, []);

  // "Max": ask the server for the largest sendable amount (available balance minus the
  // auto-detected network fee) and drop it into the amount field.
  const fillMax = async (): Promise<void> => {
    setError('');
    try {
      const r = await api<{ max: string; available: string }>(
        `/dashboard/withdrawals/max?asset=${encodeURIComponent(asset)}`,
      );
      if (BigInt(r.max) <= 0n) {
        setError('No withdrawable balance for this asset (after network fee).');
        return;
      }
      setAmount(fromBase(r.max, asset));
    } catch (err) {
      setError((err as Error).message);
    }
  };

  // Ask Telegram to send a withdrawal code (when Telegram is the merchant's factor).
  const sendTelegramCode = async (): Promise<void> => {
    setError('');
    setNotice('');
    try {
      await api('/dashboard/withdrawals/2fa/send', { method: 'POST' });
      setNotice('Code sent to your Telegram - enter it below.');
    } catch (err) {
      setError((err as Error).message);
    }
  };

  const submit = async (event: FormEvent): Promise<void> => {
    event.preventDefault();
    setError('');
    setNotice('');
    if (w2fa.required && !/^\d{6}$/.test(twofaCode)) {
      setError('Enter the 6-digit 2FA code to withdraw.');
      return;
    }
    try {
      const row = await api<{ status: string; requires_admin_approval: boolean }>(
        '/dashboard/withdrawals',
        {
          method: 'POST',
          body: {
            assetCode: asset,
            amount,
            destinationAddress: address,
            ...(tag ? { destinationTag: Number(tag) } : {}),
            ...(w2fa.required ? { twofaCode } : {}),
            idempotencyKey: crypto.randomUUID(),
          },
        },
      );
      setNotice(
        row.requires_admin_approval
          ? 'Withdrawal requested - funds locked, awaiting admin approval.'
          : 'Withdrawal requested - funds locked, processing.',
      );
      setAmount('');
      setAddress('');
      setTag('');
      setTwofaCode('');
      await load();
    } catch (err) {
      setError((err as Error).message);
    }
  };

  return (
    <>
      <h1>Withdrawals<HelpButton topic="withdrawals" /></h1>
      <div className="panel">
        <h2>Request withdrawal</h2>
        <p className="muted" style={{ marginTop: -6 }}>
          Send any amount — even small balances — to an external wallet. The gateway automatically
          gathers your funds and detects the network fee; you receive exactly what you enter, and the
          fee is taken from the treasury. Use <b>Max</b> to send everything (available minus the fee).
          Tiny native-coin amounts (BTC/ETH) can be uneconomical once the on-chain fee is applied;
          stablecoins (USDT/USDC) are cheap to move. To send automatically on a threshold, set up
          <b> Auto-payout</b> in Settings.
        </p>
        <form onSubmit={(e) => void submit(e)}>
          <div className="row">
            <div style={{ width: 150 }}>
              <label>Asset</label>
              <select value={asset} onChange={(e) => setAsset(e.target.value)}>
                {ASSETS.map((a) => <option key={a}>{a}</option>)}
              </select>
            </div>
            <div style={{ width: 170 }}>
              <label>Amount &middot; <button type="button" className="linklike" onClick={() => void fillMax()}>Max</button></label>
              <input value={amount} onChange={(e) => setAmount(e.target.value)} placeholder="0.01" required />
            </div>
            <div style={{ flex: 1 }}>
              <label>Destination address</label>
              <input value={address} onChange={(e) => setAddress(e.target.value)} required maxLength={128} />
            </div>
            {asset === 'XRP' && (
              <div style={{ width: 140 }}>
                <label>Dest. tag</label>
                <input value={tag} onChange={(e) => setTag(e.target.value)} inputMode="numeric" />
              </div>
            )}
            {w2fa.required && (
              <div style={{ width: 150 }}>
                <label>2FA code</label>
                <input
                  value={twofaCode}
                  onChange={(e) => setTwofaCode(e.target.value)}
                  pattern="\d{6}"
                  maxLength={6}
                  inputMode="numeric"
                  placeholder="123456"
                  required
                />
              </div>
            )}
            <button type="submit">Withdraw</button>
          </div>
          {w2fa.required && (
            <div className="faint" style={{ fontSize: 12, marginTop: 6 }}>
              🔒 Withdrawals require a 2FA code.{' '}
              {w2fa.totp && 'Use your authenticator app'}
              {w2fa.totp && w2fa.telegram && ', or '}
              {w2fa.telegram && (
                <button type="button" className="linklike" onClick={() => void sendTelegramCode()}>
                  send a code to Telegram
                </button>
              )}
              {w2fa.totp && !w2fa.telegram && '.'}
            </div>
          )}
        </form>
        {error && <div className="error">{error}</div>}
        {notice && <div className="success">{notice}</div>}
      </div>

      <div className="panel">
        <h2>Mass payout <span className="tag">batch</span></h2>
        <p className="muted" style={{ marginTop: -6 }}>
          One line per payout: <span className="mono">ASSET, amount, address[, destinationTag]</span> -
          e.g. <span className="mono">ETH, 0.05, 0xabc...</span>. Each item passes the normal
          risk checks and approval thresholds.
        </p>
        <textarea
          rows={4}
          value={batchText}
          onChange={(e) => setBatchText(e.target.value)}
          placeholder={'ETH, 0.05, 0x1234...\nUSDT_TRC20, 25, TabcD...\nXRP, 100, rXYZ..., 12345'}
          style={{ fontFamily: 'ui-monospace, monospace', fontSize: 12.5 }}
        />
        <div style={{ marginTop: 10 }}>
          <button
            onClick={() => {
              void (async () => {
                setBatchResult('');
                const items = batchText
                  .split('\n')
                  .map((line) => line.trim())
                  .filter(Boolean)
                  .map((line) => {
                    const [assetCode, amount, destinationAddress, tag] = line.split(',').map((s) => s.trim());
                    return {
                      assetCode,
                      amount,
                      destinationAddress,
                      ...(tag ? { destinationTag: Number(tag) } : {}),
                    };
                  });
                if (items.length === 0) return;
                if (w2fa.required && !/^\d{6}$/.test(twofaCode)) {
                  setBatchResult('Enter the 6-digit 2FA code (top form) to send a batch.');
                  return;
                }
                try {
                  const r = await api<{ accepted: number; rejected: number; items: Array<{ index: number; status: string; error?: string }> }>(
                    '/dashboard/payouts',
                    { method: 'POST', body: { items, ...(w2fa.required ? { twofaCode } : {}), idempotencyKey: crypto.randomUUID() } },
                  );
                  const failures = r.items.filter((i) => i.status === 'REJECTED')
                    .map((i) => `line ${i.index + 1}: ${i.error}`).join(' · ');
                  setBatchResult(`Accepted ${r.accepted}, rejected ${r.rejected}.${failures ? ' ' + failures : ''}`);
                  setBatchText('');
                  await load();
                } catch (e) {
                  setBatchResult((e as Error).message);
                }
              })();
            }}
            disabled={!batchText.trim()}
          >
            Send batch
          </button>
        </div>
        {batchResult && <p className="muted" style={{ marginTop: 8 }}>{batchResult}</p>}
      </div>

      <div className="panel">
        <table>
          <thead>
            <tr><th>Created</th><th>Asset</th><th>Amount</th><th>Destination</th><th>Status</th><th>TxID</th></tr>
          </thead>
          <tbody>
            {items.map((w) => (
              <tr key={w.id}>
                <td>{new Date(w.created_at).toLocaleString()}</td>
                <td>{w.asset_code}</td>
                <td>{fromBase(w.amount, w.asset_code)}</td>
                <td className="mono">{w.destination_address.slice(0, 20)}...</td>
                <td><span className={`badge ${w.status}`}>{w.status}</span></td>
                <td className="mono">
                  {w.txid ? (
                    explorerTxUrl(w.asset_code, w.txid) ? (
                      <a
                        href={explorerTxUrl(w.asset_code, w.txid) as string}
                        target="_blank"
                        rel="noopener noreferrer"
                        title={w.txid}
                      >
                        {w.txid.slice(0, 14)}... ↗
                      </a>
                    ) : (
                      `${w.txid.slice(0, 14)}...`
                    )
                  ) : (
                    '-'
                  )}
                </td>
              </tr>
            ))}
            {items.length === 0 && <tr><td colSpan={6} className="muted">No withdrawals.</td></tr>}
          </tbody>
        </table>
        <Pager offset={offset} limit={PAGE} count={items.length} onPage={setOffset} />
      </div>
    </>
  );
}
