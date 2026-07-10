import { FormEvent, useCallback, useEffect, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { api, ApiError, setSession } from '../api/client';
import Logo from '../components/Logo';

type Session = Parameters<typeof setSession>[0];
interface Captcha {
  id: string;
  svg: string;
}
interface Challenge {
  twofaRequired: true;
  ticket: string;
  methods: { totp: boolean; telegram: boolean };
}

export default function Login(): JSX.Element {
  const navigate = useNavigate();
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [captcha, setCaptcha] = useState<Captcha | null>(null);
  const [captchaAns, setCaptchaAns] = useState('');
  const [stage, setStage] = useState<'password' | '2fa'>('password');
  const [challenge, setChallenge] = useState<Challenge | null>(null);
  const [method, setMethod] = useState<'totp' | 'telegram'>('totp');
  const [tgSent, setTgSent] = useState(false);
  const [code, setCode] = useState('');
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);
  const [regOpen, setRegOpen] = useState(false); // default hidden - self-registration is off by default

  const loadCaptcha = useCallback(() => {
    setCaptchaAns('');
    api<Captcha>('/auth/captcha', { auth: false })
      .then(setCaptcha)
      .catch(() => setCaptcha(null));
  }, []);

  useEffect(() => {
    api<{ enabled: boolean }>('/auth/registration', { auth: false })
      .then((r) => setRegOpen(!!r.enabled))
      .catch(() => setRegOpen(false));
    loadCaptcha();
  }, [loadCaptcha]);

  const submitPassword = async (event: FormEvent): Promise<void> => {
    event.preventDefault();
    if (!captcha) return;
    setBusy(true);
    setError('');
    try {
      const res = await api<Session | Challenge>('/auth/login', {
        method: 'POST',
        auth: false,
        body: { email, password, captchaId: captcha.id, captcha: captchaAns },
      });
      if (res && (res as Challenge).twofaRequired) {
        const ch = res as Challenge;
        setChallenge(ch);
        setCode('');
        // When both factors are on the user chooses; default to the app. When only Telegram
        // is on the backend has already sent the code, so mark it as sent.
        setMethod(ch.methods.totp ? 'totp' : 'telegram');
        setTgSent(ch.methods.telegram && !ch.methods.totp);
        setStage('2fa');
      } else {
        setSession(res as Session);
        navigate('/');
      }
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'login failed');
      loadCaptcha(); // every failure burns the captcha - fetch a fresh one
    } finally {
      setBusy(false);
    }
  };

  const submit2fa = async (event: FormEvent): Promise<void> => {
    event.preventDefault();
    if (!challenge) return;
    setBusy(true);
    setError('');
    try {
      const session = await api<Session>('/auth/login/2fa', {
        method: 'POST',
        auth: false,
        body: { ticket: challenge.ticket, code },
      });
      setSession(session);
      navigate('/');
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'verification failed');
    } finally {
      setBusy(false);
    }
  };

  const restart = (): void => {
    setStage('password');
    setChallenge(null);
    setError('');
    setPassword('');
    setTgSent(false);
    loadCaptcha();
  };

  // Switch factor. Picking Telegram sends the code the first time it's chosen.
  const chooseMethod = async (next: 'totp' | 'telegram'): Promise<void> => {
    setMethod(next);
    setCode('');
    setError('');
    if (next === 'telegram' && !tgSent && challenge) {
      setBusy(true);
      try {
        await api('/auth/login/2fa/send', { method: 'POST', auth: false, body: { ticket: challenge.ticket } });
        setTgSent(true);
      } catch (err) {
        setError(err instanceof ApiError ? err.message : 'could not send Telegram code');
      } finally {
        setBusy(false);
      }
    }
  };

  const m = challenge?.methods;
  const bothMethods = !!(m?.totp && m?.telegram);
  const twofaHint = bothMethods
    ? method === 'telegram'
      ? tgSent
        ? 'We sent a 6-digit code to your Telegram. Enter it below.'
        : 'Sending a code to your Telegram...'
      : 'Enter the 6-digit code from your authenticator app.'
    : m?.telegram
      ? 'We sent a 6-digit code to your Telegram. Enter it below.'
      : 'Enter the 6-digit code from your authenticator app.';

  return (
    <div className="auth-page">
      {stage === '2fa' ? (
        <form className="auth-card" onSubmit={(e) => void submit2fa(e)}>
          <div className="auth-brand"><Logo size={34} /><h1>Two-step verification</h1></div>
          {bothMethods && (
            <div className="l2f-tabs" role="tablist" style={{ display: 'flex', gap: 8, margin: '4px 0 12px' }}>
              <button
                type="button"
                className={`l2f-tab btn-sm${method === 'totp' ? ' active' : ''}`}
                aria-selected={method === 'totp'}
                onClick={() => void chooseMethod('totp')}
                disabled={busy}
                style={{ flex: 1, opacity: method === 'totp' ? 1 : 0.6 }}
              >
                Authenticator app
              </button>
              <button
                type="button"
                className={`l2f-tab btn-sm${method === 'telegram' ? ' active' : ''}`}
                aria-selected={method === 'telegram'}
                onClick={() => void chooseMethod('telegram')}
                disabled={busy}
                style={{ flex: 1, opacity: method === 'telegram' ? 1 : 0.6 }}
              >
                Telegram
              </button>
            </div>
          )}
          <p className="auth-sub">{twofaHint}</p>
          <div className="field">
            <label>Verification code</label>
            <input
              value={code}
              onChange={(e) => setCode(e.target.value)}
              inputMode="numeric"
              pattern="\d{6}"
              maxLength={6}
              autoFocus
              required
            />
          </div>
          {error && <div className="error">{error}</div>}
          <button type="submit" className="btn-lg" disabled={busy} style={{ marginTop: 8 }}>
            {busy ? <><span className="spinner" /> Verifying...</> : 'Verify'}
          </button>
          <p className="muted" style={{ textAlign: 'center', marginTop: 16 }}>
            <button type="button" className="linklike" onClick={restart}>Cancel and start over</button>
          </p>
        </form>
      ) : (
        <form className="auth-card" onSubmit={(e) => void submitPassword(e)}>
          <div className="auth-brand"><Logo size={34} /><h1>Orizen Pay</h1></div>
          <p className="auth-sub">Multi-chain payment gateway</p>
          <div className="field">
            <label>Email</label>
            <input type="email" value={email} onChange={(e) => setEmail(e.target.value)} required autoFocus />
          </div>
          <div className="field">
            <label>Password</label>
            <input type="password" value={password} onChange={(e) => setPassword(e.target.value)} required />
          </div>
          <div className="field">
            <label>Captcha</label>
            <div className="captcha-row">
              <div
                className="captcha-img"
                onClick={loadCaptcha}
                title="Click for a new image"
                dangerouslySetInnerHTML={{ __html: captcha?.svg ?? '' }}
              />
              <input
                value={captchaAns}
                onChange={(e) => setCaptchaAns(e.target.value)}
                placeholder="Type the characters"
                autoComplete="off"
                autoCapitalize="characters"
                spellCheck={false}
                required
              />
            </div>
            <div className="captcha-hint">
              Not case-sensitive &middot;{' '}
              <button type="button" className="linklike" onClick={loadCaptcha}>get a new image</button>
            </div>
          </div>
          {error && <div className="error">{error}</div>}
          <button type="submit" className="btn-lg" disabled={busy || !captcha} style={{ marginTop: 8 }}>
            {busy ? <><span className="spinner" /> Signing in...</> : 'Sign in'}
          </button>
          {regOpen && (
            <p className="muted" style={{ textAlign: 'center', marginTop: 16 }}>
              No account? <Link to="/register">Create one</Link>
            </p>
          )}
        </form>
      )}
      <p className="faint" style={{ textAlign: 'center', marginTop: 18, fontSize: 12 }}>
        Developed by{' '}
        <a href="https://www.facebook.com/orion.hridoy" target="_blank" rel="noopener noreferrer">Orion Hridoy</a>
      </p>
    </div>
  );
}
