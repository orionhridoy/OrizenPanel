import { FormEvent, useEffect, useState } from 'react';
import { api } from '../api/client';
import { ApiKeyRow } from '../api/types';
import { HelpButton } from '../components/Help';

const ALL_PERMISSIONS = [
  'invoices:read',
  'invoices:write',
  'balances:read',
  'withdrawals:write',
  'webhooks:manage',
];

export default function ApiKeys(): JSX.Element {
  const [keys, setKeys] = useState<ApiKeyRow[]>([]);
  const [label, setLabel] = useState('');
  const [permissions, setPermissions] = useState<string[]>(['invoices:read', 'invoices:write']);
  const [created, setCreated] = useState<{ apiKey: string; apiSecret: string } | null>(null);
  const [error, setError] = useState('');

  const load = async (): Promise<void> => {
    setKeys(await api<ApiKeyRow[]>('/dashboard/api-keys'));
  };

  useEffect(() => {
    void load().catch((err) => setError((err as Error).message));
  }, []);

  const create = async (event: FormEvent): Promise<void> => {
    event.preventDefault();
    setError('');
    try {
      const result = await api<{ apiKey: string; apiSecret: string }>('/dashboard/api-keys', {
        method: 'POST',
        body: { label, permissions },
      });
      setCreated(result);
      setLabel('');
      await load();
    } catch (err) {
      setError((err as Error).message);
    }
  };

  const revoke = async (id: string): Promise<void> => {
    if (!window.confirm('Revoke this API key? Integrations using it will stop working.')) return;
    await api(`/dashboard/api-keys/${id}`, { method: 'DELETE' });
    await load();
  };

  const togglePermission = (permission: string): void => {
    setPermissions((current) =>
      current.includes(permission)
        ? current.filter((p) => p !== permission)
        : [...current, permission],
    );
  };

  return (
    <>
      <h1>API Keys<HelpButton topic="apiKeys" /></h1>
      {created && (
        <div className="secret-box">
          <strong>Save these now - they are shown only once.</strong>
          <p className="mono">API key: {created.apiKey}</p>
          <p className="mono">API secret: {created.apiSecret}</p>
          <p className="muted">
            Sign requests with X-API-KEY, X-TIMESTAMP (ms) and X-SIGNATURE =
            HMAC-SHA256(secret, timestamp.METHOD.path.sha256(body)).
          </p>
          <button className="secondary" onClick={() => setCreated(null)}>Dismiss</button>
        </div>
      )}
      <div className="panel">
        <h2>Create key</h2>
        <form onSubmit={(e) => void create(e)}>
          <div className="row">
            <div style={{ width: 240 }}>
              <label>Label</label>
              <input value={label} onChange={(e) => setLabel(e.target.value)} required maxLength={120} />
            </div>
            <div style={{ flex: 1 }}>
              <label>Permissions</label>
              <div className="row" style={{ flexWrap: 'wrap' }}>
                {ALL_PERMISSIONS.map((permission) => (
                  <label key={permission} style={{ display: 'flex', gap: 4, margin: 0, alignItems: 'center' }}>
                    <input
                      type="checkbox"
                      style={{ width: 'auto' }}
                      checked={permissions.includes(permission)}
                      onChange={() => togglePermission(permission)}
                    />
                    {permission}
                  </label>
                ))}
              </div>
            </div>
            <button type="submit" disabled={permissions.length === 0}>Create</button>
          </div>
        </form>
        {error && <div className="error">{error}</div>}
      </div>

      <div className="panel">
        <table>
          <thead>
            <tr><th>Label</th><th>Prefix</th><th>Permissions</th><th>Last used</th><th>Status</th><th></th></tr>
          </thead>
          <tbody>
            {keys.map((key) => (
              <tr key={key.id}>
                <td>{key.label}</td>
                <td className="mono">{key.key_prefix}...</td>
                <td className="muted">{key.permissions.join(', ')}</td>
                <td>{key.last_used_at ? new Date(key.last_used_at).toLocaleString() : 'never'}</td>
                <td>
                  <span className={`badge ${key.revoked_at ? 'REJECTED' : 'ACTIVE'}`}>
                    {key.revoked_at ? 'REVOKED' : 'ACTIVE'}
                  </span>
                </td>
                <td>
                  {!key.revoked_at && (
                    <button className="danger" onClick={() => void revoke(key.id)}>Revoke</button>
                  )}
                </td>
              </tr>
            ))}
            {keys.length === 0 && <tr><td colSpan={6} className="muted">No API keys.</td></tr>}
          </tbody>
        </table>
      </div>
    </>
  );
}
