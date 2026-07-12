import { useEffect, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { api } from '../api/client';
import { InvoiceView, explorerTxUrl, fromBase } from '../api/types';

interface PaymentRow {
  id: string;
  txid: string;
  amount: string;
  from_address: string | null;
  status: string;
  confirmations: number;
  is_rbf: boolean;
  replaced_by_txid: string | null;
  detected_at: string;
  confirmed_at: string | null;
}

export default function InvoiceDetail(): JSX.Element {
  const { id } = useParams<{ id: string }>();
  const [invoice, setInvoice] = useState<InvoiceView | null>(null);
  const [payments, setPayments] = useState<PaymentRow[]>([]);
  const [error, setError] = useState('');

  const load = async (): Promise<void> => {
    try {
      const [inv, pays] = await Promise.all([
        api<InvoiceView>(`/dashboard/invoices/${id}`),
        api<PaymentRow[]>(`/dashboard/invoices/${id}/payments`),
      ]);
      setInvoice(inv);
      setPayments(pays);
    } catch (err) {
      setError((err as Error).message);
    }
  };

  useEffect(() => {
    void load();
    const timer = setInterval(() => void load(), 10_000);
    return () => clearInterval(timer);
  }, [id]);

  if (error) return <div className="error">{error}</div>;
  if (!invoice) return <p className="muted">Loading...</p>;

  return (
    <>
      <h1>
        Invoice <span className="mono">{invoice.id.slice(0, 8)}</span>{' '}
        <span className={`badge ${invoice.status}`}>{invoice.status}</span>
      </h1>
      <p><Link to="/invoices">← back to invoices</Link></p>

      <div className="grid cols-2">
        <div className="panel">
          <h2>Details</h2>
          <table>
            <tbody>
              <tr><td className="muted">Asset</td><td>{invoice.asset}</td></tr>
              <tr><td className="muted">Amount due</td><td>{invoice.amountDueDecimal} {invoice.asset}</td></tr>
              <tr><td className="muted">Confirmed</td><td>{fromBase(invoice.amountPaidConfirmed, invoice.asset)}</td></tr>
              <tr><td className="muted">Pending</td><td>{fromBase(invoice.amountPaidPending, invoice.asset)}</td></tr>
              <tr><td className="muted">Order ID</td><td>{invoice.orderId ?? '-'}</td></tr>
              <tr><td className="muted">Description</td><td>{invoice.description ?? '-'}</td></tr>
              <tr><td className="muted">Created</td><td>{new Date(invoice.createdAt).toLocaleString()}</td></tr>
              <tr><td className="muted">Expires</td><td>{new Date(invoice.expiresAt).toLocaleString()}</td></tr>
              <tr><td className="muted">Paid at</td><td>{invoice.paidAt ? new Date(invoice.paidAt).toLocaleString() : '-'}</td></tr>
            </tbody>
          </table>
        </div>
        <div className="panel">
          <h2>Payment target</h2>
          <p className="muted">Address (never reused)</p>
          <p className="mono">{invoice.address}</p>
          {invoice.destinationTag !== null && (
            <>
              <p className="muted">Destination tag (required!)</p>
              <p className="mono">{invoice.destinationTag}</p>
            </>
          )}
          <p className="muted">Required confirmations: {invoice.requiredConfirmations}</p>
          <p>
            <a href={invoice.checkoutUrl} target="_blank" rel="noopener noreferrer">
              Open customer checkout ↗
            </a>
          </p>
          {['PAID', 'OVERPAID', 'UNDERPAID'].includes(invoice.status) && (
            <button
              className="danger"
              onClick={() => {
                void (async () => {
                  const address = window.prompt(
                    'Refund the confirmed amount to which address? (the payer’s wallet)',
                  );
                  if (!address) return;
                  try {
                    await api(`/dashboard/invoices/${invoice.id}/refund`, {
                      method: 'POST',
                      body: { destinationAddress: address.trim() },
                    });
                    window.alert('Refund created - track it on the Withdrawals page.');
                  } catch (e) {
                    window.alert((e as Error).message);
                  }
                })();
              }}
            >
              Refund payer
            </button>
          )}
        </div>
      </div>

      <div className="panel">
        <h2>Detected payments</h2>
        <table>
          <thead>
            <tr><th>Detected</th><th>TxID</th><th>Amount</th><th>Status</th><th>Confs</th><th>RBF</th></tr>
          </thead>
          <tbody>
            {payments.map((payment) => (
              <tr key={payment.id}>
                <td>{new Date(payment.detected_at).toLocaleString()}</td>
                <td className="mono">
                  {explorerTxUrl(invoice.asset, payment.txid) ? (
                    <a
                      href={explorerTxUrl(invoice.asset, payment.txid) as string}
                      target="_blank"
                      rel="noopener noreferrer"
                      title={payment.txid}
                    >
                      {payment.txid.slice(0, 18)}... ↗
                    </a>
                  ) : (
                    `${payment.txid.slice(0, 18)}...`
                  )}
                </td>
                <td>{fromBase(payment.amount, invoice.asset)}</td>
                <td>
                  <span className={`badge ${payment.status}`}>{payment.status}</span>
                  {payment.replaced_by_txid && (
                    <span className="muted"> → {payment.replaced_by_txid.slice(0, 10)}...</span>
                  )}
                </td>
                <td>{payment.confirmations}</td>
                <td>{payment.is_rbf ? '⚠' : ''}</td>
              </tr>
            ))}
            {payments.length === 0 && <tr><td colSpan={6} className="muted">Nothing detected yet.</td></tr>}
          </tbody>
        </table>
      </div>
    </>
  );
}
