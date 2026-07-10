import { FormEvent, useEffect, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { api, ApiError, setSession } from '../api/client';
import Logo from '../components/Logo';

export default function Register(): JSX.Element {
  const navigate = useNavigate();
  const [name, setName] = useState('');
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);
  const [registrationOpen, setRegistrationOpen] = useState(true);

  useEffect(() => {
    void api<{ enabled: boolean }>('/auth/registration', { auth: false })
      .then((r) => setRegistrationOpen(r.enabled))
      .catch(() => setRegistrationOpen(true));
  }, []);

  const submit = async (event: FormEvent): Promise<void> => {
    event.preventDefault();
    if (password.length < 12) {
      setError('password must be at least 12 characters');
      return;
    }
    setBusy(true);
    setError('');
    try {
      const session = await api<Parameters<typeof setSession>[0]>('/auth/register', {
        method: 'POST',
        auth: false,
        body: { name, email, password },
      });
      setSession(session);
      navigate('/');
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'registration failed');
    } finally {
      setBusy(false);
    }
  };

  if (!registrationOpen) {
    return (
      <div className="auth-page">
        <div className="auth-card">
          <div className="auth-brand"><Logo size={34} /><h1>Orizen Pay</h1></div>
          <p className="auth-sub">Sign-ups are closed</p>
          <div className="error" style={{ marginTop: 8 }}>
            New account registration is currently disabled by the administrator.
          </div>
          <p className="muted" style={{ textAlign: 'center', marginTop: 16 }}>
            Already have an account? <Link to="/login">Sign in</Link>
          </p>
        </div>
      </div>
    );
  }

  return (
    <div className="auth-page">
      <form className="auth-card" onSubmit={(e) => void submit(e)}>
        <div className="auth-brand"><Logo size={34} /><h1>Orizen Pay</h1></div>
        <p className="auth-sub">Create your merchant account</p>
        <div className="field">
          <label>Business name</label>
          <input value={name} onChange={(e) => setName(e.target.value)} required maxLength={120} autoFocus />
        </div>
        <div className="field">
          <label>Email</label>
          <input type="email" value={email} onChange={(e) => setEmail(e.target.value)} required />
        </div>
        <div className="field">
          <label>Password <span className="faint">(min 12 characters)</span></label>
          <input type="password" value={password} onChange={(e) => setPassword(e.target.value)} required minLength={12} />
        </div>
        {error && <div className="error">{error}</div>}
        <button type="submit" className="btn-lg" disabled={busy} style={{ marginTop: 8 }}>
          {busy ? <><span className="spinner" /> Creating...</> : 'Create account'}
        </button>
        <p className="muted" style={{ textAlign: 'center', marginTop: 16 }}>
          Have an account? <Link to="/login">Sign in</Link>
        </p>
      </form>
    </div>
  );
}
