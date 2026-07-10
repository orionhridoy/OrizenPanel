import { FormEvent, useEffect, useState } from 'react';
import { QRCodeSVG } from 'qrcode.react';
import { api } from '../api/client';
import { MerchantProfile, fromBase } from '../api/types';
import { HelpButton } from '../components/Help';

type ExpiryUnit = 'minutes' | 'hours' | 'days';
const UNIT_MINUTES: Record<ExpiryUnit, number> = { minutes: 1, hours: 60, days: 1440 };

export default function Settings(): JSX.Element {
  const [profile, setProfile] = useState<MerchantProfile | null>(null);
  const [mode, setMode] = useState('HOLD');
  const [cron, setCron] = useState('');
  const [tolerance, setTolerance] = useState(100);
  const [expVal, setExpVal] = useState('');
  const [expUnit, setExpUnit] = useState<ExpiryUnit>('hours');
  const [currentPassword, setCurrentPassword] = useState('');
  const [newPassword, setNewPassword] = useState('');
  const [totpSetup, setTotpSetup] = useState<{ secret: string; otpauthUrl: string } | null>(null);
  const [totpCode, setTotpCode] = useState('');
  const [twofa, setTwofa] = useState<{ totp: boolean; telegram: boolean; telegramChatId: string | null; withdrawal2fa: boolean } | null>(null);
  const [tgToken, setTgToken] = useState('');
  const [tgChat, setTgChat] = useState('');
  const [tgSent, setTgSent] = useState(false);
  const [tgCode, setTgCode] = useState('');
  const [apEnabled, setApEnabled] = useState(false);
  const [apTargets, setApTargets] = useState<Array<{ asset: string; address: string; minAmount: string }>>([]);
  const [message, setMessage] = useState<{ kind: 'error' | 'success'; text: string } | null>(null);

  const load = async (): Promise<void> => {
    const p = await api<MerchantProfile>('/dashboard/merchant/me');
    setProfile(p);
    try {
      setTwofa(await api<{ totp: boolean; telegram: boolean; telegramChatId: string | null; withdrawal2fa: boolean }>('/auth/2fa/status'));
    } catch {
      setTwofa({ totp: p.totp_enabled, telegram: false, telegramChatId: null, withdrawal2fa: false });
    }
    try {
      const ap = await api<{ enabled: boolean; targets: Array<{ asset: string; address: string; minAmount: string }> }>(
        '/dashboard/withdrawals/auto-payout',
      );
      setApEnabled(ap.enabled);
      setApTargets(ap.targets);
    } catch {
      setApEnabled(false);
      setApTargets([]);
    }
    setMode(p.settlement_mode);
    setCron(p.settlement_schedule_cron ?? '');
    setTolerance(p.underpayment_tolerance_bps);
    const secs = p.default_invoice_ttl_seconds;
    if (secs && secs > 0) {
      const mins = Math.round(secs / 60);
      if (mins % 1440 === 0) { setExpVal(String(mins / 1440)); setExpUnit('days'); }
      else if (mins % 60 === 0) { setExpVal(String(mins / 60)); setExpUnit('hours'); }
      else { setExpVal(String(mins)); setExpUnit('minutes'); }
    } else {
      setExpVal(''); setExpUnit('hours');
    }
  };

  useEffect(() => {
    void load().catch((err) => setMessage({ kind: 'error', text: (err as Error).message }));
  }, []);

  const note = (kind: 'error' | 'success', text: string): void => setMessage({ kind, text });

  const saveSettlement = async (event: FormEvent): Promise<void> => {
    event.preventDefault();
    try {
      await api('/dashboard/merchant/me', {
        method: 'PATCH',
        body: {
          settlementMode: mode,
          ...(mode === 'SCHEDULED' ? { settlementScheduleCron: cron } : {}),
          underpaymentToleranceBps: tolerance,
        },
      });
      note('success', 'Settings saved.');
      await load();
    } catch (err) {
      note('error', (err as Error).message);
    }
  };

  const saveInvoiceDefaults = async (event: FormEvent): Promise<void> => {
    event.preventDefault();
    try {
      const minutes = expVal.trim() === '' ? 0 : Math.round(Number(expVal) * UNIT_MINUTES[expUnit]);
      if (minutes !== 0 && (minutes < 5 || minutes > 43200)) {
        note('error', 'Default expiry must be between 5 minutes and 30 days (or empty for the 1-hour default).');
        return;
      }
      await api('/dashboard/merchant/me', {
        method: 'PATCH',
        body: { defaultInvoiceExpiryMinutes: minutes },
      });
      note('success', minutes === 0 ? 'Default expiry cleared (back to 1 hour).' : 'Default invoice expiry saved.');
      await load();
    } catch (err) {
      note('error', (err as Error).message);
    }
  };

  const settleNow = async (): Promise<void> => {
    try {
      const released = await api<Array<{ assetCode: string; amount: string }>>(
        '/dashboard/merchant/settle',
        { method: 'POST' },
      );
      note(
        'success',
        released.length === 0
          ? 'Nothing pending to settle.'
          : `Released: ${released.map((r) => `${fromBase(r.amount, r.assetCode)} ${r.assetCode}`).join(', ')}`,
      );
    } catch (err) {
      note('error', (err as Error).message);
    }
  };

  const changePassword = async (event: FormEvent): Promise<void> => {
    event.preventDefault();
    try {
      await api('/auth/change-password', {
        method: 'POST',
        body: { currentPassword, newPassword },
      });
      setCurrentPassword('');
      setNewPassword('');
      note('success', 'Password changed. Other sessions were signed out.');
    } catch (err) {
      note('error', (err as Error).message);
    }
  };

  const startTotp = async (): Promise<void> => {
    try {
      setTotpSetup(await api<{ secret: string; otpauthUrl: string }>('/auth/totp/setup', { method: 'POST' }));
    } catch (err) {
      note('error', (err as Error).message);
    }
  };

  const enableTotp = async (event: FormEvent): Promise<void> => {
    event.preventDefault();
    try {
      await api('/auth/totp/enable', { method: 'POST', body: { code: totpCode } });
      setTotpSetup(null);
      setTotpCode('');
      note('success', 'Authenticator 2FA enabled.');
      await load();
    } catch (err) {
      note('error', (err as Error).message);
    }
  };

  // Turning off a factor is verified by that same factor (a current code).
  const disableTotp = async (): Promise<void> => {
    const code = window.prompt('Enter a current 6-digit code from your authenticator app to turn 2FA off:');
    if (!code) return;
    try {
      await api('/auth/totp/disable', { method: 'POST', body: { code: code.trim() } });
      note('success', 'Authenticator 2FA turned off.');
      await load();
    } catch (err) {
      note('error', (err as Error).message);
    }
  };

  const tgSetup = async (event: FormEvent): Promise<void> => {
    event.preventDefault();
    try {
      await api('/auth/telegram/setup', { method: 'POST', body: { botToken: tgToken.trim(), chatId: tgChat.trim() } });
      setTgSent(true);
      note('success', 'Test code sent to your Telegram - enter it below to enable.');
    } catch (err) {
      note('error', (err as Error).message);
    }
  };

  const tgEnable = async (event: FormEvent): Promise<void> => {
    event.preventDefault();
    try {
      await api('/auth/telegram/enable', { method: 'POST', body: { code: tgCode } });
      setTgToken('');
      setTgChat('');
      setTgCode('');
      setTgSent(false);
      note('success', 'Telegram 2FA enabled.');
      await load();
    } catch (err) {
      note('error', (err as Error).message);
    }
  };

  const tgDisable = async (): Promise<void> => {
    try {
      await api('/auth/telegram/disable-send', { method: 'POST' });
    } catch (err) {
      note('error', (err as Error).message);
      return;
    }
    const code = window.prompt('We sent a code to your Telegram. Enter it to turn Telegram 2FA off:');
    if (!code) return;
    try {
      await api('/auth/telegram/disable', { method: 'POST', body: { code: code.trim() } });
      note('success', 'Telegram 2FA turned off.');
      await load();
    } catch (err) {
      note('error', (err as Error).message);
    }
  };

  // "Require a 2FA code to withdraw" - only meaningful once a factor is set up.
  const toggleWithdraw2fa = async (enabled: boolean): Promise<void> => {
    try {
      await api('/auth/withdrawal-2fa', { method: 'PATCH', body: { enabled } });
      note('success', enabled ? 'Withdrawals now require a 2FA code.' : 'Withdrawal 2FA turned off.');
      await load();
    } catch (err) {
      note('error', (err as Error).message);
      await load();
    }
  };

  // -- Auto-payout: send my balance to my own wallet automatically ----------------
  const AP_ASSETS = ['BTC', 'LTC', 'ETH', 'XRP', 'USDT_TRC20', 'USDC_ERC20'];
  const addApTarget = (): void =>
    setApTargets((t) => [...t, { asset: AP_ASSETS.find((a) => !t.some((x) => x.asset === a)) ?? 'BTC', address: '', minAmount: '' }]);
  const updateApTarget = (i: number, field: 'asset' | 'address' | 'minAmount', value: string): void =>
    setApTargets((t) => t.map((row, idx) => (idx === i ? { ...row, [field]: value } : row)));
  const removeApTarget = (i: number): void => setApTargets((t) => t.filter((_, idx) => idx !== i));

  const saveAutoPayout = async (enabled: boolean): Promise<void> => {
    const targets = apTargets
      .map((t) => ({ asset: t.asset, address: t.address.trim(), minAmount: (t.minAmount.trim() || '0') }))
      .filter((t) => t.address !== '');
    if (enabled && targets.length === 0) {
      note('error', 'Add at least one wallet address before turning auto-payout on.');
      return;
    }
    // Repointing payouts is money-moving: if withdrawal 2FA is on, confirm with a code.
    let twofaCode: string | undefined;
    if (twofa?.withdrawal2fa) {
      if (twofa.telegram && !twofa.totp) {
        try { await api('/dashboard/withdrawals/2fa/send', { method: 'POST' }); } catch { /* show prompt anyway */ }
      }
      const c = window.prompt('Enter your 6-digit 2FA code to change auto-payout:');
      if (!c) return;
      twofaCode = c.trim();
    }
    try {
      await api('/dashboard/withdrawals/auto-payout', {
        method: 'PATCH',
        body: { enabled, targets, ...(twofaCode ? { twofaCode } : {}) },
      });
      note('success', enabled ? 'Auto-payout is on. Balances will be sent to your wallet automatically.' : 'Auto-payout saved.');
      await load();
    } catch (err) {
      note('error', (err as Error).message);
    }
  };

  const modeHelp: Record<string, string> = {
    AUTO_SETTLE: 'Confirmed payments are withdrawable immediately. Most convenient - good for most shops.',
    HOLD: 'Confirmed payments sit as Pending until you release them (use “Release pending funds now”). Safest.',
    MANUAL: 'Funds stay Pending until you release them with the button here or the settle API.',
    SCHEDULED: 'Funds are released to Available automatically on the schedule you set below.',
  };

  if (!profile) return <p className="muted">Loading...</p>;

  return (
    <>
      <h1>Settings<HelpButton topic="settings" /></h1>
      {message && <div className={message.kind}>{message.text}</div>}

      <div className="panel">
        <h2>When do payments become withdrawable?</h2>
        <p className="muted" style={{ marginTop: -6 }}>
          After a payment confirms it lands in your balance. This controls whether it becomes{' '}
          <b>Available</b> (you can withdraw it now) or <b>Pending</b> (held until you release it).
        </p>
        <form onSubmit={(e) => void saveSettlement(e)}>
          <div className="row">
            <div style={{ width: 280 }}>
              <label>Payout timing</label>
              <select value={mode} onChange={(e) => setMode(e.target.value)}>
                <option value="AUTO_SETTLE">Instant - available right away</option>
                <option value="HOLD">Hold - I release funds myself</option>
                <option value="MANUAL">Manual - release with a button / API</option>
                <option value="SCHEDULED">Scheduled - release on a timer</option>
              </select>
            </div>
            {mode === 'SCHEDULED' && (
              <div style={{ width: 240 }}>
                <label>Release schedule</label>
                <input value={cron} onChange={(e) => setCron(e.target.value)} placeholder="0 6 * * *" required />
                <div className="faint" style={{ fontSize: 12, marginTop: 4 }}>Cron format. “0 6 * * *” = every day at 06:00.</div>
              </div>
            )}
          </div>
          <div className="help-tip" style={{ marginTop: 8 }}>💡 {modeHelp[mode]}</div>

          <div style={{ maxWidth: 380, marginTop: 6 }}>
            <label>Accept slightly underpaid payments</label>
            <div className="row" style={{ alignItems: 'center', gap: 8 }}>
              <input
                type="number"
                min={0}
                max={20}
                step={0.1}
                style={{ width: 90 }}
                value={tolerance / 100}
                onChange={(e) => setTolerance(Math.max(0, Math.min(2000, Math.round(Number(e.target.value) * 100))))}
              />
              <span className="muted">% under the amount still counts as paid</span>
            </div>
            <div className="faint" style={{ fontSize: 12, marginTop: 6 }}>
              Crypto payments can arrive a little short (sender fees, rounding, exchange-rate drift).{' '}
              {tolerance > 0 ? (
                <>A $100 order still counts as <b>fully paid</b> if the customer sends at least{' '}
                  <b>${((100 * (10000 - tolerance)) / 10000).toFixed(2)}</b> - i.e. up to {tolerance / 100}% short is OK.</>
              ) : (
                <>At 0%, the customer must send the exact amount (or more) or it’s marked <b>underpaid</b>.</>
              )}
            </div>
          </div>

          <div className="row" style={{ marginTop: 16 }}>
            <button type="submit">Save</button>
            <button type="button" className="secondary" onClick={() => void settleNow()}>
              Release pending funds now
            </button>
          </div>
        </form>
      </div>

      <div className="panel">
        <h2>Default invoice expiry</h2>
        <p className="muted" style={{ marginTop: -6 }}>
          How long a payer has to complete checkout when your website or API doesn’t set its own time.
          Applies to new invoices, payment links and store top-ups. A single invoice can still override this.
        </p>
        <form onSubmit={(e) => void saveInvoiceDefaults(e)}>
          <div className="row" style={{ alignItems: 'flex-end', gap: 8 }}>
            <div style={{ width: 140 }}>
              <label>Expires in</label>
              <input type="number" min={0} step={1} value={expVal} onChange={(e) => setExpVal(e.target.value)} placeholder="1" />
            </div>
            <div style={{ width: 150 }}>
              <label>Unit</label>
              <select value={expUnit} onChange={(e) => setExpUnit(e.target.value as ExpiryUnit)}>
                <option value="minutes">Minutes</option>
                <option value="hours">Hours</option>
                <option value="days">Days</option>
              </select>
            </div>
            <button type="submit">Save</button>
          </div>
          <div className="faint" style={{ fontSize: 12, marginTop: 6 }}>
            Leave empty for the built-in default (1 hour). Allowed range: 5 minutes to 30 days.
          </div>
        </form>
      </div>

      <div className="panel">
        <h2>Password</h2>
        <p className="muted" style={{ marginTop: -6 }}>Changing your password signs you out everywhere else.</p>
        <form onSubmit={(e) => void changePassword(e)}>
          <div className="row">
            <div style={{ width: 260 }}>
              <label>Current password</label>
              <input type="password" value={currentPassword} onChange={(e) => setCurrentPassword(e.target.value)} required />
            </div>
            <div style={{ width: 260 }}>
              <label>New password (min 12 chars)</label>
              <input type="password" value={newPassword} onChange={(e) => setNewPassword(e.target.value)} required minLength={12} />
            </div>
            <button type="submit">Change password</button>
          </div>
        </form>
      </div>

      <div className="panel">
        <h2>Two-factor authentication</h2>
        <p className="muted" style={{ marginTop: -6 }}>
          Add a second step at login. Turn on either method (or both) - you’ll then need a 6-digit code as
          well as your password. Strongly recommended before exposing the gateway publicly.
        </p>

        <h3 style={{ marginBottom: 6 }}>Authenticator app</h3>
        {twofa?.totp ? (
          <div className="row" style={{ alignItems: 'center', gap: 12 }}>
            <span className="success" style={{ margin: 0 }}>Enabled - a code from your app is required at login.</span>
            <button type="button" className="danger" onClick={() => void disableTotp()}>Turn off</button>
          </div>
        ) : totpSetup ? (
          <form onSubmit={(e) => void enableTotp(e)}>
            <ol className="totp-steps">
              <li><b>Scan this QR code</b> in your authenticator app (Google Authenticator, Authy, 1Password, Microsoft Authenticator…).</li>
              <li>Enter the <b>6‑digit code</b> it shows, then Verify.</li>
            </ol>
            <div className="totp-setup">
              <div className="totp-qr"><QRCodeSVG value={totpSetup.otpauthUrl} size={168} level="M" /></div>
              <div className="totp-manual">
                <label>Can’t scan? Enter this key manually:</label>
                <div className="pill-copy" onClick={() => void navigator.clipboard.writeText(totpSetup.secret)} title="Click to copy">
                  <span className="mono">{totpSetup.secret}</span>
                </div>
                <div className="row" style={{ marginTop: 12 }}>
                  <div style={{ width: 160 }}>
                    <label>6-digit code</label>
                    <input value={totpCode} onChange={(e) => setTotpCode(e.target.value)} pattern="\d{6}" maxLength={6} inputMode="numeric" autoFocus required />
                  </div>
                  <button type="submit">Verify &amp; enable</button>
                </div>
              </div>
            </div>
          </form>
        ) : (
          <button onClick={() => void startTotp()}>Set up authenticator</button>
        )}

        <hr style={{ border: 0, borderTop: '1px solid var(--border)', margin: '20px 0' }} />

        <h3 style={{ marginBottom: 6 }}>Telegram</h3>
        {twofa?.telegram ? (
          <div className="row" style={{ alignItems: 'center', gap: 12 }}>
            <span className="success" style={{ margin: 0 }}>
              Enabled - a login code is sent to chat <span className="mono">{twofa.telegramChatId}</span>.
            </span>
            <button type="button" className="danger" onClick={() => void tgDisable()}>Turn off</button>
          </div>
        ) : (
          <>
            <p className="muted" style={{ marginTop: -2 }}>
              Get your login code on Telegram. Create a bot with <span className="mono">@BotFather</span>, press{' '}
              <b>Start</b> on it, then find your numeric chat ID (e.g. via <span className="mono">@userinfobot</span>).
            </p>
            <form onSubmit={(e) => void tgSetup(e)}>
              <div className="row" style={{ gap: 8, flexWrap: 'wrap' }}>
                <div style={{ width: 300 }}>
                  <label>Bot token</label>
                  <input value={tgToken} onChange={(e) => setTgToken(e.target.value)} placeholder="123456789:ABC-DEF..." autoComplete="off" required />
                </div>
                <div style={{ width: 180 }}>
                  <label>Your chat ID</label>
                  <input value={tgChat} onChange={(e) => setTgChat(e.target.value)} placeholder="123456789" autoComplete="off" required />
                </div>
                <button type="submit" style={{ alignSelf: 'flex-end' }}>Send test code</button>
              </div>
            </form>
            {tgSent && (
              <form onSubmit={(e) => void tgEnable(e)} style={{ marginTop: 12 }}>
                <div className="row" style={{ gap: 8 }}>
                  <div style={{ width: 160 }}>
                    <label>Code from Telegram</label>
                    <input value={tgCode} onChange={(e) => setTgCode(e.target.value)} pattern="\d{6}" maxLength={6} inputMode="numeric" autoFocus required />
                  </div>
                  <button type="submit" style={{ alignSelf: 'flex-end' }}>Verify &amp; enable</button>
                </div>
              </form>
            )}
          </>
        )}

        <hr style={{ border: 0, borderTop: '1px solid var(--border)', margin: '20px 0' }} />

        <h3 style={{ marginBottom: 6 }}>Require 2FA to withdraw</h3>
        <p className="muted" style={{ marginTop: -2 }}>
          When on, every withdrawal asks for a fresh 6-digit code (from your authenticator app or Telegram)
          before it’s submitted - an extra guard on moving money out.
        </p>
        {twofa?.totp || twofa?.telegram ? (
          <label className="row" style={{ alignItems: 'center', gap: 10, cursor: 'pointer' }}>
            <input
              type="checkbox"
              checked={!!twofa?.withdrawal2fa}
              onChange={(e) => void toggleWithdraw2fa(e.target.checked)}
              style={{ width: 18, height: 18 }}
            />
            <span>{twofa?.withdrawal2fa ? 'On - withdrawals need a 2FA code.' : 'Off - withdrawals do not ask for a code.'}</span>
          </label>
        ) : (
          <p className="faint" style={{ fontSize: 13 }}>Turn on an authenticator app or Telegram above first.</p>
        )}
      </div>

      <div className="panel">
        <h2>Auto-payout to your wallet</h2>
        <p className="muted" style={{ marginTop: -6 }}>
          Send your balance to your own external wallet <b>automatically</b>. For each coin, set the wallet
          address and a minimum — once your available balance reaches it, the gateway gathers the funds
          (even small deposits below the normal batch size), auto-detects the network fee, and sends the
          rest to you. No manual withdrawal needed. Set the minimum to a tiny value to auto-send almost
          everything; on Bitcoin/Ethereum keep it sensible so the network fee doesn't eat the payout
          (stablecoins like USDT/USDC are cheap to send, so small auto-payouts work well there).
        </p>
        <label className="row" style={{ alignItems: 'center', gap: 10, cursor: 'pointer', marginBottom: 10 }}>
          <input type="checkbox" checked={apEnabled} onChange={(e) => setApEnabled(e.target.checked)} style={{ width: 18, height: 18 }} />
          <span><b>Enable auto-payout</b></span>
        </label>

        {apTargets.map((t, i) => (
          <div className="row" key={i} style={{ gap: 8, alignItems: 'flex-end', marginBottom: 8, flexWrap: 'wrap' }}>
            <div style={{ width: 140 }}>
              <label>Coin</label>
              <select value={t.asset} onChange={(e) => updateApTarget(i, 'asset', e.target.value)}>
                {AP_ASSETS.map((a) => <option key={a} value={a}>{a}</option>)}
              </select>
            </div>
            <div style={{ flex: 1, minWidth: 240 }}>
              <label>Your wallet address</label>
              <input value={t.address} onChange={(e) => updateApTarget(i, 'address', e.target.value)} placeholder="destination address" autoComplete="off" />
            </div>
            <div style={{ width: 150 }}>
              <label>Send when ≥</label>
              <input value={t.minAmount} onChange={(e) => updateApTarget(i, 'minAmount', e.target.value)} placeholder="0.001" inputMode="decimal" />
            </div>
            <button type="button" className="danger" onClick={() => removeApTarget(i)}>Remove</button>
          </div>
        ))}

        <div className="row" style={{ gap: 8, marginTop: 6 }}>
          <button type="button" className="secondary" onClick={addApTarget}>+ Add wallet</button>
          <button type="button" onClick={() => void saveAutoPayout(apEnabled)}>Save auto-payout</button>
        </div>
        <div className="help-tip" style={{ marginTop: 10 }}>
          💡 Token payouts (USDT/USDC) need a little of the chain's native coin (TRX / ETH) in the gateway
          treasury to pay gas. Auto-payout only ever sends to the address you saved here.
        </div>
      </div>
    </>
  );
}
