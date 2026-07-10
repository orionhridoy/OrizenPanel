import { useEffect, useState } from 'react';
import { api } from '../api/client';
import { fromBase } from '../api/types';
import { HelpButton } from '../components/Help';

interface Summary {
  days: number;
  byAsset: Array<{ asset_code: string; paid_count: number; revenue_base_units: string }>;
  series: Array<{ day: string; asset_code: string; paid_count: number; revenue_base_units: string }>;
  funnel: { created: number; engaged: number; paid: number; expired: number; underpaid: number };
  topLinks: Array<{ title: string; slug: string; times_used: number; paid_count: number }>;
}

const COLORS = ['#6d5efc', '#22d3ee', '#a855f7', '#34d399', '#fbbf24', '#fb7185'];

export default function Analytics(): JSX.Element {
  const [days, setDays] = useState(30);
  const [data, setData] = useState<Summary | null>(null);
  const [error, setError] = useState('');

  useEffect(() => {
    api<Summary>(`/dashboard/analytics/summary?days=${days}`)
      .then(setData)
      .catch((e) => setError((e as Error).message));
  }, [days]);

  if (error) return <div className="error">{error}</div>;
  if (!data) return <p className="muted">Loading...</p>;

  // build a daily paid-count series (all assets combined) for the chart
  const dayMap = new Map<string, number>();
  for (const row of data.series) {
    dayMap.set(row.day, (dayMap.get(row.day) ?? 0) + row.paid_count);
  }
  const daysList: string[] = [];
  for (let i = days - 1; i >= 0; i--) {
    const d = new Date(Date.now() - i * 86400_000);
    daysList.push(d.toISOString().slice(0, 10));
  }
  const values = daysList.map((d) => dayMap.get(d) ?? 0);
  const max = Math.max(1, ...values);
  const W = 720, H = 160, bw = W / values.length;

  const conversion = data.funnel.created > 0 ? Math.round((data.funnel.paid / data.funnel.created) * 100) : 0;

  return (
    <>
      <h1>Analytics<HelpButton topic="analytics" /></h1>

      <div className="row" style={{ marginBottom: 16 }}>
        {[7, 30, 90].map((d) => (
          <span key={d} className={`chip ${days === d ? 'active' : ''}`} onClick={() => setDays(d)}>
            {d} days
          </span>
        ))}
      </div>

      <div className="grid cols-3">
        <div className="stat"><div className="label">Invoices created</div><div className="value">{data.funnel.created}</div></div>
        <div className="stat"><div className="label">Paid</div><div className="value" style={{ color: 'var(--green)' }}>{data.funnel.paid}</div></div>
        <div className="stat"><div className="label">Conversion</div><div className="value">{conversion}%</div></div>
      </div>

      <div className="panel" style={{ marginTop: 20 }}>
        <h2>Paid invoices per day</h2>
        <svg viewBox={`0 0 ${W} ${H + 24}`} style={{ width: '100%', height: 'auto' }} role="img" aria-label="paid invoices per day">
          {values.map((v, i) => (
            <rect key={i}
              x={i * bw + 1.5} y={H - (v / max) * (H - 10)}
              width={Math.max(1, bw - 3)} height={(v / max) * (H - 10)}
              rx={2.5} fill="url(#nvx-bar)" opacity={v === 0 ? 0.18 : 0.95}
            />
          ))}
          <defs>
            <linearGradient id="nvx-bar" x1="0" y1="0" x2="0" y2="1">
              <stop stopColor="#6d5efc" /><stop offset="1" stopColor="#22d3ee" />
            </linearGradient>
          </defs>
          <text x={0} y={H + 16} fill="#626b8f" fontSize={11}>{daysList[0]}</text>
          <text x={W} y={H + 16} fill="#626b8f" fontSize={11} textAnchor="end">{daysList[daysList.length - 1]}</text>
        </svg>
      </div>

      <div className="grid cols-2">
        <div className="panel">
          <h2>Revenue by asset</h2>
          <table>
            <thead><tr><th></th><th>Asset</th><th>Paid</th><th>Confirmed revenue</th></tr></thead>
            <tbody>
              {data.byAsset.map((row, i) => (
                <tr key={row.asset_code}>
                  <td style={{ width: 16 }}><span style={{ display: 'inline-block', width: 10, height: 10, borderRadius: 3, background: COLORS[i % COLORS.length] }} /></td>
                  <td><strong>{row.asset_code}</strong></td>
                  <td>{row.paid_count}</td>
                  <td>{fromBase(row.revenue_base_units, row.asset_code)} {row.asset_code}</td>
                </tr>
              ))}
              {data.byAsset.length === 0 && <tr><td colSpan={4} className="muted">No invoices in this period.</td></tr>}
            </tbody>
          </table>
        </div>

        <div className="panel">
          <h2>Funnel &amp; top links</h2>
          <table>
            <tbody>
              <tr><td className="muted">Created</td><td>{data.funnel.created}</td></tr>
              <tr><td className="muted">Payment detected</td><td>{data.funnel.engaged}</td></tr>
              <tr><td className="muted">Paid</td><td style={{ color: 'var(--green)' }}>{data.funnel.paid}</td></tr>
              <tr><td className="muted">Expired</td><td>{data.funnel.expired}</td></tr>
              <tr><td className="muted">Underpaid</td><td>{data.funnel.underpaid}</td></tr>
            </tbody>
          </table>
          {data.topLinks.length > 0 && (
            <>
              <div className="divider" />
              <table>
                <thead><tr><th>Payment link</th><th>Opens</th><th>Paid</th></tr></thead>
                <tbody>
                  {data.topLinks.map((l) => (
                    <tr key={l.slug}><td>{l.title}</td><td>{l.times_used}</td><td>{l.paid_count}</td></tr>
                  ))}
                </tbody>
              </table>
            </>
          )}
        </div>
      </div>
    </>
  );
}
