import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { api } from '../api/client';

interface Step {
  key: string;
  label: string;
  hint: string;
  to: string;
  done: boolean;
}

export default function GettingStarted(): JSX.Element | null {
  const navigate = useNavigate();
  const [steps, setSteps] = useState<Step[]>([]);
  const [dismissed, setDismissed] = useState(sessionStorage.getItem('nvx.gs.hide') === '1');

  useEffect(() => {
    (async () => {
      const [inv, keys, store, hooks] = await Promise.all([
        api<{ total: number }>('/dashboard/invoices?limit=1').catch(() => ({ total: 0 })),
        api<unknown[]>('/dashboard/api-keys').catch(() => []),
        api<{ storeEnabled: boolean }>('/dashboard/store/config').catch(() => ({ storeEnabled: false })),
        api<unknown[]>('/dashboard/webhooks').catch(() => []),
      ]);
      setSteps([
        { key: 'invoice', label: 'Create your first invoice', hint: 'Request a crypto (or $/€/£) payment', to: '/invoices', done: inv.total > 0 },
        { key: 'apikey', label: 'Create an API key', hint: 'Integrate the gateway with your store', to: '/api-keys', done: (keys as unknown[]).length > 0 },
        { key: 'store', label: 'Enable your store', hint: 'Let customers top up a balance & pay', to: '/store', done: store.storeEnabled },
        { key: 'webhook', label: 'Add a webhook', hint: 'Get notified the instant a payment lands', to: '/webhooks', done: (hooks as unknown[]).length > 0 },
      ]);
    })().catch(() => undefined);
  }, []);

  if (dismissed || steps.length === 0) return null;
  const done = steps.filter((s) => s.done).length;
  if (done === steps.length) return null;

  return (
    <div className="panel" style={{ background: 'var(--grad-soft)' }}>
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
        <h2 style={{ margin: 0 }}>🚀 Get started <span className="faint" style={{ fontWeight: 400 }}>· {done}/{steps.length} done</span></h2>
        <button className="ghost" onClick={() => { sessionStorage.setItem('nvx.gs.hide', '1'); setDismissed(true); }}>Dismiss</button>
      </div>
      <div className="progressbar" style={{ margin: '10px 0 16px' }}><div style={{ width: `${(done / steps.length) * 100}%` }} /></div>
      <div style={{ display: 'grid', gap: 10 }}>
        {steps.map((s) => (
          <div key={s.key} style={{ display: 'flex', alignItems: 'center', gap: 12, padding: '10px 12px', borderRadius: 10, background: 'rgba(255,255,255,0.03)', border: '1px solid var(--border)' }}>
            <div style={{
              width: 26, height: 26, borderRadius: '50%', flexShrink: 0, display: 'flex', alignItems: 'center', justifyContent: 'center',
              fontWeight: 700, fontSize: 13,
              background: s.done ? 'var(--green)' : 'rgba(255,255,255,0.08)', color: s.done ? '#04120a' : 'var(--muted)',
            }}>{s.done ? '✓' : '•'}</div>
            <div style={{ flex: 1 }}>
              <div style={{ fontWeight: 600, textDecoration: s.done ? 'line-through' : 'none', opacity: s.done ? 0.6 : 1 }}>{s.label}</div>
              <div className="faint" style={{ fontSize: 12 }}>{s.hint}</div>
            </div>
            {!s.done && <button className="secondary" onClick={() => navigate(s.to)}>Go →</button>}
          </div>
        ))}
      </div>
    </div>
  );
}
