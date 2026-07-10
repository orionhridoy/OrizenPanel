import { useEffect, useState } from 'react';
import { api } from '../api/client';
import { BalanceRow, ChainStatus, fromBase } from '../api/types';
import { HelpButton } from '../components/Help';
import GettingStarted from '../components/GettingStarted';

const TYPE_LABELS: Record<string, string> = {
  MERCHANT_AVAILABLE: 'Available',
  MERCHANT_PENDING: 'Pending (hold)',
  MERCHANT_LOCKED: 'Locked (withdrawing)',
};

export default function Overview(): JSX.Element {
  const [balances, setBalances] = useState<BalanceRow[]>([]);
  const [chains, setChains] = useState<ChainStatus[]>([]);
  const [error, setError] = useState('');

  const load = async (): Promise<void> => {
    try {
      const [balanceRows, status] = await Promise.all([
        api<BalanceRow[]>('/dashboard/merchant/balances'),
        api<{ chains: ChainStatus[] }>('/public/status', { auth: false }),
      ]);
      setBalances(balanceRows);
      setChains(status.chains);
    } catch (err) {
      setError((err as Error).message);
    }
  };

  useEffect(() => {
    void load();
    const timer = setInterval(() => void load(), 15_000);
    return () => clearInterval(timer);
  }, []);

  const assets = [...new Set(balances.map((b) => b.asset_code))];

  return (
    <>
      <h1>Overview<HelpButton topic="overview" /></h1>
      {error && <div className="error">{error}</div>}
      <GettingStarted />
      <div className="panel">
        <h2>Balances</h2>
        {assets.length === 0 ? (
          <p className="muted">No funds yet - create an invoice to get paid.</p>
        ) : (
          <table>
            <thead>
              <tr><th>Asset</th><th>Available</th><th>Pending</th><th>Locked</th></tr>
            </thead>
            <tbody>
              {assets.map((asset) => {
                const of = (type: string): string => {
                  const row = balances.find((b) => b.asset_code === asset && b.type === type);
                  return row ? fromBase(row.balance, asset) : '0';
                };
                return (
                  <tr key={asset}>
                    <td><strong>{asset}</strong></td>
                    <td>{of('MERCHANT_AVAILABLE')}</td>
                    <td>{of('MERCHANT_PENDING')}</td>
                    <td>{of('MERCHANT_LOCKED')}</td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        )}
        <p className="muted" style={{ fontSize: 12 }}>
          {Object.entries(TYPE_LABELS).map(([k, v]) => `${v} = ${k}`).join(' · ')}
        </p>
      </div>

      <div className="panel">
        <h2>Blockchain nodes</h2>
        <table>
          <thead>
            <tr><th>Chain</th><th>Synced</th><th>Height</th><th>Peers</th><th>Progress</th><th>Payment engine</th></tr>
          </thead>
          <tbody>
            {chains.map((chain) => (
              <tr key={chain.chain}>
                <td><strong>{chain.chain}</strong></td>
                <td>{chain.synced ? '✓' : '...'}</td>
                <td>{chain.height.toLocaleString()}</td>
                <td>{chain.peers}</td>
                <td style={{ width: 160 }}>
                  {(chain.progress * 100).toFixed(1)}%
                  <div className="progressbar"><div style={{ width: `${chain.progress * 100}%` }} /></div>
                </td>
                <td>
                  <span className={`badge ${chain.engineActive ? 'ACTIVE' : 'PENDING'}`}>
                    {chain.engineActive ? 'ACTIVE' : 'waiting for sync'}
                  </span>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </>
  );
}
