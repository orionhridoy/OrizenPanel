import { FormEvent, useEffect, useState } from 'react';
import { api } from '../api/client';
import { WebhookEndpoint } from '../api/types';
import { HelpButton } from '../components/Help';

interface DeliveryRow {
  id: string;
  event_type: string;
  status: string;
  attempt_count: number;
  last_response_code: number | null;
  last_error: string | null;
  created_at: string;
}

// A ready-to-upload, self-contained PHP webhook receiver (verifies the signature, no SDK needed).
const WEBHOOK_PHP = `<?php
// orizen-webhook.php - receive payment events from your Orizen gateway.
// 1) Upload this ONE file to your website, e.g. https://yoursite.com/orizen-webhook.php
// 2) On this Webhooks page, add that URL as an endpoint.
// 3) Paste the whsec_... signing secret it shows you into $WEBHOOK_SECRET below.

$WEBHOOK_SECRET = 'whsec_PASTE_YOUR_SECRET_HERE';

$raw = file_get_contents('php://input');
$sig = $_SERVER['HTTP_X_ORIZEN_SIGNATURE'] ?? '';
$ts  = $_SERVER['HTTP_X_ORIZEN_TIMESTAMP'] ?? '';

// Reject missing headers or deliveries older than 5 minutes (blocks replay attacks).
// The timestamp is in milliseconds.
if (!$sig || !$ts || abs(time() - (int) round(((float) $ts) / 1000)) > 300) {
    http_response_code(400);
    exit('stale');
}

// ALWAYS verify the signature on the RAW body before trusting the event.
$provided = preg_replace('/^v1=/', '', $sig);
$expected = hash_hmac('sha256', $ts . '.' . $raw, $WEBHOOK_SECRET);
if (!hash_equals($expected, $provided)) {
    http_response_code(400);
    exit('bad signature');
}

$event = json_decode($raw, true);

switch ($event['type'] ?? '') {
    case 'invoice.paid':
        // Fully paid & confirmed - mark the order paid, grant access, fulfil, etc.
        // $event['data'] holds the invoice (id, orderId, asset, amount, ...).
        // error_log('PAID: ' . ($event['data']['orderId'] ?? ''));
        break;

    case 'invoice.underpaid':
    case 'invoice.expired':
        // Short or expired payment - notify the customer / reopen the cart.
        break;

    case 'withdrawal.confirmed':
        // A payout you requested confirmed on-chain.
        break;
}

// ACK fast. Any non-2xx response is retried by the gateway with exponential backoff.
http_response_code(200);
echo 'ok';
`;

export default function Webhooks(): JSX.Element {
  const [endpoints, setEndpoints] = useState<WebhookEndpoint[]>([]);
  const [url, setUrl] = useState('');
  const [secret, setSecret] = useState<string | null>(null);
  const [selected, setSelected] = useState<string | null>(null);
  const [deliveries, setDeliveries] = useState<DeliveryRow[]>([]);
  const [error, setError] = useState('');
  const [copied, setCopied] = useState(false);

  const copyScript = (): void => {
    void navigator.clipboard.writeText(WEBHOOK_PHP);
    setCopied(true);
    setTimeout(() => setCopied(false), 1600);
  };
  const downloadScript = (): void => {
    const blob = new Blob([WEBHOOK_PHP], { type: 'application/x-php' });
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = 'orizen-webhook.php';
    a.click();
    URL.revokeObjectURL(a.href);
  };

  const load = async (): Promise<void> => {
    setEndpoints(await api<WebhookEndpoint[]>('/dashboard/webhooks'));
  };

  useEffect(() => {
    void load().catch((err) => setError((err as Error).message));
  }, []);

  const create = async (event: FormEvent): Promise<void> => {
    event.preventDefault();
    setError('');
    try {
      const created = await api<WebhookEndpoint>('/dashboard/webhooks', {
        method: 'POST',
        body: { url },
      });
      setSecret(created.secret ?? null);
      setUrl('');
      await load();
    } catch (err) {
      setError((err as Error).message);
    }
  };

  const toggle = async (endpoint: WebhookEndpoint): Promise<void> => {
    await api(`/dashboard/webhooks/${endpoint.id}`, {
      method: 'PATCH',
      body: { isActive: !endpoint.is_active },
    });
    await load();
  };

  const remove = async (id: string): Promise<void> => {
    if (!window.confirm('Delete this webhook endpoint?')) return;
    await api(`/dashboard/webhooks/${id}`, { method: 'DELETE' });
    if (selected === id) setSelected(null);
    await load();
  };

  const showDeliveries = async (id: string): Promise<void> => {
    setSelected(id);
    setDeliveries(await api<DeliveryRow[]>(`/dashboard/webhooks/${id}/deliveries`));
  };

  return (
    <>
      <h1>Webhooks<HelpButton topic="webhooks" /></h1>
      {secret && (
        <div className="secret-box">
          <strong>Endpoint signing secret - shown only once.</strong>
          <p className="mono">{secret}</p>
          <p className="muted">
            Verify deliveries: X-Orizen-Signature is v1=HMAC-SHA256(secret, timestamp.body)
            with X-Orizen-Timestamp.
          </p>
          <button className="secondary" onClick={() => setSecret(null)}>Dismiss</button>
        </div>
      )}
      <div className="panel">
        <form onSubmit={(e) => void create(e)}>
          <div className="row">
            <div style={{ flex: 1 }}>
              <label>Endpoint URL (https, public network)</label>
              <input value={url} onChange={(e) => setUrl(e.target.value)} placeholder="https://example.com/orizen-webhook" required />
            </div>
            <button type="submit">Add endpoint</button>
          </div>
        </form>
        {error && <div className="error">{error}</div>}
      </div>

      <div className="panel">
        <div className="row" style={{ alignItems: 'center', justifyContent: 'space-between', gap: 10 }}>
          <div>
            <h2 style={{ margin: 0 }}>Ready-made webhook handler</h2>
            <p className="muted" style={{ margin: '4px 0 0' }}>
              Copy or download this one PHP file, upload it to your website, paste your endpoint&apos;s{' '}
              <span className="mono">whsec_…</span> secret into it, then add its URL above. It verifies every
              delivery&apos;s signature for you.
            </p>
          </div>
          <div style={{ whiteSpace: 'nowrap' }}>
            <button type="button" className="secondary" onClick={copyScript}>{copied ? 'Copied ✓' : 'Copy'}</button>{' '}
            <button type="button" onClick={downloadScript}>Download .php</button>
          </div>
        </div>
        <div className="codeblock" style={{ maxHeight: 320, overflow: 'auto', marginTop: 12 }}>{WEBHOOK_PHP}</div>
      </div>

      <div className="panel">
        <table>
          <thead>
            <tr><th>URL</th><th>Events</th><th>Status</th><th></th></tr>
          </thead>
          <tbody>
            {endpoints.map((endpoint) => (
              <tr key={endpoint.id}>
                <td className="mono">{endpoint.url}</td>
                <td className="muted">{endpoint.events.length} events</td>
                <td>
                  <span className={`badge ${endpoint.is_active ? 'ACTIVE' : 'REJECTED'}`}>
                    {endpoint.is_active ? 'ACTIVE' : 'DISABLED'}
                  </span>
                </td>
                <td style={{ whiteSpace: 'nowrap' }}>
                  <button className="secondary" onClick={() => void showDeliveries(endpoint.id)}>Deliveries</button>{' '}
                  <button className="secondary" onClick={() => void toggle(endpoint)}>
                    {endpoint.is_active ? 'Disable' : 'Enable'}
                  </button>{' '}
                  <button className="danger" onClick={() => void remove(endpoint.id)}>Delete</button>
                </td>
              </tr>
            ))}
            {endpoints.length === 0 && <tr><td colSpan={4} className="muted">No endpoints.</td></tr>}
          </tbody>
        </table>
      </div>

      {selected && (
        <div className="panel">
          <h2>Recent deliveries</h2>
          <table>
            <thead>
              <tr><th>Created</th><th>Event</th><th>Status</th><th>Attempts</th><th>HTTP</th><th>Error</th></tr>
            </thead>
            <tbody>
              {deliveries.map((delivery) => (
                <tr key={delivery.id}>
                  <td>{new Date(delivery.created_at).toLocaleString()}</td>
                  <td>{delivery.event_type}</td>
                  <td><span className={`badge ${delivery.status}`}>{delivery.status}</span></td>
                  <td>{delivery.attempt_count}</td>
                  <td>{delivery.last_response_code ?? '-'}</td>
                  <td className="muted">{delivery.last_error ?? ''}</td>
                </tr>
              ))}
              {deliveries.length === 0 && <tr><td colSpan={6} className="muted">No deliveries yet.</td></tr>}
            </tbody>
          </table>
        </div>
      )}
    </>
  );
}
