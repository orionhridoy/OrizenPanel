import { useEffect, useState } from 'react';
import { Navigate, Route, Routes, useLocation } from 'react-router-dom';
import { getSession } from './api/client';
import Layout from './components/Layout';
import Login from './pages/Login';
import Register from './pages/Register';
import Overview from './pages/Overview';
import Invoices from './pages/Invoices';
import InvoiceDetail from './pages/InvoiceDetail';
import Withdrawals from './pages/Withdrawals';
import ApiKeys from './pages/ApiKeys';
import Webhooks from './pages/Webhooks';
import Settings from './pages/Settings';
import Admin from './pages/Admin';
import Checkout from './pages/Checkout';
import Store from './pages/Store';
import StorePage from './pages/StorePage';
import PayLink from './pages/PayLink';
import Links from './pages/Links';
import Analytics from './pages/Analytics';
import Connect from './pages/Connect';
import EasySetup from './pages/EasySetup';
import Tutorial from './pages/Tutorial';
import ApiDocs from './pages/ApiDocs';

function useSession(): ReturnType<typeof getSession> {
  const [session, set] = useState(getSession());
  useEffect(() => {
    const update = (): void => set(getSession());
    window.addEventListener('orizen:session', update);
    window.addEventListener('storage', update);
    return () => {
      window.removeEventListener('orizen:session', update);
      window.removeEventListener('storage', update);
    };
  }, []);
  return session;
}

export default function App(): JSX.Element {
  const session = useSession();
  const location = useLocation();

  // public pages work without a session
  if (location.pathname.startsWith('/checkout/') || location.pathname.startsWith('/pay/')) {
    return (
      <Routes>
        <Route path="/checkout/:id" element={<Checkout />} />
        <Route path="/pay/:slug" element={<PayLink />} />
      </Routes>
    );
  }

  // hosted store top-up page: public, authenticated by a ?t= session token
  const storeToken = new URLSearchParams(location.search).get('t');
  if (location.pathname === '/store' && storeToken) {
    return <StorePage token={storeToken} />;
  }

  if (!session) {
    return (
      <Routes>
        <Route path="/login" element={<Login />} />
        <Route path="/register" element={<Register />} />
        <Route path="*" element={<Navigate to="/login" replace />} />
      </Routes>
    );
  }

  const isAdmin = session.merchant.role === 'ADMIN';
  return (
    <Layout session={session}>
      <Routes>
        <Route path="/" element={<Overview />} />
        <Route path="/setup" element={<EasySetup />} />
        <Route path="/tutorial" element={<Tutorial />} />
        <Route path="/invoices" element={<Invoices />} />
        <Route path="/invoices/:id" element={<InvoiceDetail />} />
        <Route path="/withdrawals" element={<Withdrawals />} />
        <Route path="/connect" element={<Connect />} />
        <Route path="/links" element={<Links />} />
        <Route path="/analytics" element={<Analytics />} />
        <Route path="/store" element={<Store />} />
        <Route path="/api-keys" element={<ApiKeys />} />
        <Route path="/api-docs" element={<ApiDocs />} />
        <Route path="/webhooks" element={<Webhooks />} />
        <Route path="/settings" element={<Settings />} />
        {isAdmin && <Route path="/admin" element={<Admin />} />}
        <Route path="*" element={<Navigate to="/" replace />} />
      </Routes>
    </Layout>
  );
}
