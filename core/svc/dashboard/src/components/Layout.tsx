import { ReactNode, useState } from 'react';
import { Link, NavLink, useNavigate } from 'react-router-dom';
import { api, getSession, setSession } from '../api/client';
import Logo from './Logo';
import {
  IconOverview,
  IconInvoice,
  IconWithdraw,
  IconKey,
  IconWebhook,
  IconStore,
  IconSettings,
  IconAdmin,
  IconLink,
  IconChart,
  IconConnect,
  IconCode,
  IconSetup,
  IconBook,
} from './icons';

const IconSignout = (): JSX.Element => (
  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={1.8} strokeLinecap="round" strokeLinejoin="round">
    <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9" />
  </svg>
);

export default function Layout({
  session,
  children,
}: {
  session: NonNullable<ReturnType<typeof getSession>>;
  children: ReactNode;
}): JSX.Element {
  const navigate = useNavigate();
  const [menu, setMenu] = useState(false);
  const isAdmin = session.merchant.role === 'ADMIN';
  const email = session.merchant.email;
  const initial = (session.merchant.name || email || '?').trim().charAt(0) || '?';

  const logout = async (): Promise<void> => {
    const current = getSession();
    if (current) {
      await api('/auth/logout', { method: 'POST', body: { refreshToken: current.refreshToken } }).catch(
        () => undefined,
      );
    }
    setSession(null);
    navigate('/login');
  };

  return (
    <div className="layout">
      <aside className="sidebar">
        <div className="brand">
          <Logo size={30} />
          <span className="name">Orizen Pay</span>
        </div>
        <nav>
          <NavLink to="/" end><IconOverview /><span>Overview</span></NavLink>
          <NavLink to="/setup"><IconSetup /><span>Easy Setup</span></NavLink>
          <NavLink to="/connect"><IconConnect /><span>Connect Site</span></NavLink>
          <NavLink to="/invoices"><IconInvoice /><span>Invoices</span></NavLink>
          <NavLink to="/links"><IconLink /><span>Payment Links</span></NavLink>
          <NavLink to="/analytics"><IconChart /><span>Analytics</span></NavLink>
          <NavLink to="/withdrawals"><IconWithdraw /><span>Withdrawals</span></NavLink>
          <NavLink to="/store"><IconStore /><span>Store</span></NavLink>
          <NavLink to="/api-keys"><IconKey /><span>API Keys</span></NavLink>
          <NavLink to="/tutorial"><IconBook /><span>Tutorial</span></NavLink>
          <NavLink to="/api-docs"><IconCode /><span>API Docs</span></NavLink>
          <NavLink to="/webhooks"><IconWebhook /><span>Webhooks</span></NavLink>
          {isAdmin && (
            <NavLink to="/admin"><IconAdmin /><span>Admin</span></NavLink>
          )}
        </nav>
        <div className="spacer" />
        <div className="dev-credit">
          Developed by{' '}
          <a href="https://www.facebook.com/orion.hridoy" target="_blank" rel="noopener noreferrer">Orion Hridoy</a>
        </div>
      </aside>
      <main className="main">
        <header className="topbar">
          <div className="topbar-left">
            <span className="status-dot" /> All systems operational
          </div>
          <div className="topbar-right">
            <NavLink to="/setup" className="btn btn-grad btn-sm">+ Set up my site</NavLink>
            <div className="acct">
              <button className="acct-btn" onClick={() => setMenu((v) => !v)} aria-haspopup="menu" aria-expanded={menu}>
                <span className="acct-avatar">{initial}</span>
                <span className="acct-email-sm">{email}</span>
                <span className="caret">▾</span>
              </button>
              {menu && (
                <>
                  <div className="acct-backdrop" onClick={() => setMenu(false)} />
                  <div className="acct-menu" role="menu">
                    <div className="who">
                      <div className="em">{email}</div>
                      <div className="rl">{session.merchant.role}</div>
                    </div>
                    <Link to="/settings" onClick={() => setMenu(false)}><IconSettings /> Settings</Link>
                    <div className="acct-sep" />
                    <button className="signout" onClick={() => { setMenu(false); void logout(); }}>
                      <IconSignout /> Sign out
                    </button>
                  </div>
                </>
              )}
            </div>
          </div>
        </header>
        <div className="main-inner">{children}</div>
      </main>
    </div>
  );
}
