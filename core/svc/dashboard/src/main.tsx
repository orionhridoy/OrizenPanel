import React from 'react';
import ReactDOM from 'react-dom/client';
import { BrowserRouter } from 'react-router-dom';
import App from './App';
import './styles.css';

// Panel SSO: adopt a #sso=<base64url TokenPair> session from the URL fragment before the app
// boots. This lives in the bundled module (served from /assets, so allowed by the gateway's
// Content-Security-Policy `script-src 'self'`); an inline <script> would be blocked by that CSP.
(() => {
  const h = window.location.hash;
  if (h.indexOf('#sso=') !== 0) return;
  try {
    let s = h.slice(5).replace(/-/g, '+').replace(/_/g, '/');
    while (s.length % 4) s += '=';
    const o = JSON.parse(atob(s)) as { accessToken?: string; refreshToken?: string; merchant?: unknown };
    if (o && o.accessToken && o.refreshToken && o.merchant) {
      localStorage.setItem('orizen.session', JSON.stringify(o));
    }
  } catch {
    /* ignore malformed sso */
  }
  // strip the token from the address bar / history so the JWT never lingers
  try {
    window.history.replaceState(null, '', window.location.pathname + window.location.search);
  } catch {
    /* noop */
  }
})();

ReactDOM.createRoot(document.getElementById('root') as HTMLElement).render(
  <React.StrictMode>
    <BrowserRouter>
      <App />
    </BrowserRouter>
  </React.StrictMode>,
);
