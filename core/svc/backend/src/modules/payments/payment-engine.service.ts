import { Injectable, Logger } from '@nestjs/common';
import { PoolClient } from 'pg';
import { DatabaseService } from '../../database/database.service';
import { LedgerService } from '../ledger/ledger.service';
import { MetricsService } from '../metrics/metrics.service';
import { WebhooksService, WebhookEvent } from '../webhooks/webhooks.service';
import { WalletsService } from '../wallets/wallets.service';
import { AdapterRegistry } from '../blockchain/adapter.registry';
import { IncomingTransfer } from '../blockchain/chain-adapter.interface';
import { AssetCode, AssetRow, Chain } from '../../common/types';
import { meetsWithTolerance } from '../../common/utils/base-units.util';

interface InvoiceRow {
  id: string;
  merchant_id: string;
  asset_code: AssetCode;
  amount_due: string;
  amount_paid_pending: string;
  amount_paid_confirmed: string;
  status: string;
  underpayment_tolerance_bps: number;
  required_confirmations: number;
  expires_at: string;
  settlement_mode?: string;
  store_user_id?: string | null;
  purpose?: string;
  order_id?: string | null;
  description?: string | null;
}

interface PaymentRow {
  id: string;
  invoice_id: string | null;
  address_id: string;
  asset_code: AssetCode;
  txid: string;
  amount: string;
  status: string;
  block_height: string | null;
  block_hash: string | null;
  confirmations: number;
  credited: boolean;
  credit_epoch: number;
  missing_since: string | null;
  /** confirmation policy snapshotted on the invoice (null for unsolicited deposits) */
  invoice_required_confirmations: number | null;
}

/** statuses a payment can be resurrected from when its tx reappears on-chain */
const DEAD_PAYMENT_STATUSES = ['REPLACED', 'REJECTED', 'ORPHANED'] as const;

/**
 * How long a tx must stay missing from the chain before we act on it.
 * A single "not found" from a lagging node, a restarted mempool, or an
 * explorer hiccup must never reject a payment or reverse a credit.
 */
const MISSING_GRACE_MS = 15 * 60 * 1000;

interface PendingEvent {
  merchantId: string;
  event: WebhookEvent;
  payload: Record<string, unknown>;
}

/**
 * The payment engine: the only component allowed to move invoices and
 * payments through their state machines and to credit the ledger.
 *
 * Invoice states: NEW -> SEEN -> CONFIRMING -> PAID/OVERPAID
 *                 NEW -> EXPIRED (nothing seen in time)
 *                 late/short resolution -> UNDERPAID
 * Payment states: MEMPOOL -> CONFIRMING -> CONFIRMED
 *                 MEMPOOL -> REPLACED (RBF) / REJECTED (double-spend)
 *                 CONFIRMING/CONFIRMED -> ORPHANED (reorg)
 */
@Injectable()
export class PaymentEngineService {
  private readonly logger = new Logger(PaymentEngineService.name);

  constructor(
    private readonly db: DatabaseService,
    private readonly ledger: LedgerService,
    private readonly metrics: MetricsService,
    private readonly webhooks: WebhooksService,
    private readonly wallets: WalletsService,
    private readonly adapters: AdapterRegistry,
  ) {}

  private async asset(client: PoolClient, code: AssetCode): Promise<AssetRow> {
    const result = await client.query<AssetRow>(`SELECT * FROM assets WHERE code = $1`, [code]);
    if (!result.rows[0]) throw new Error(`unknown asset ${code}`);
    return result.rows[0];
  }

  /** Registers a transfer discovered by a scan. Safe to call repeatedly. */
  async registerTransfer(chain: Chain, transfer: IncomingTransfer): Promise<void> {
    const resolved = await this.wallets.resolveAddress(
      chain,
      transfer.address,
      transfer.destinationTag,
    );
    if (!resolved) return; // not one of our issued addresses

    const events: PendingEvent[] = [];
    await this.db.tx(async (client) => {
      const asset = await this.asset(client, transfer.assetCode);
      if (BigInt(transfer.amountBaseUnits) < BigInt(asset.dust_threshold)) {
        this.metrics.paymentAnomalies.labels(chain, 'dust').inc();
        this.logger.warn(
          `dust ignored: ${transfer.amountBaseUnits} ${transfer.assetCode} to ${transfer.address} (${transfer.txid})`,
        );
        return;
      }

      const status = transfer.blockHeight === null ? 'MEMPOOL' : 'CONFIRMING';
      const inserted = await client.query<{ id: string }>(
        `INSERT INTO payments
           (invoice_id, address_id, asset_code, txid, output_index, log_index, amount,
            from_address, status, block_height, block_hash, is_rbf)
         VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, $10, $11, $12)
         ON CONFLICT (asset_code, txid, output_index, log_index)
         DO UPDATE SET block_height = COALESCE(EXCLUDED.block_height, payments.block_height),
                       block_hash   = COALESCE(EXCLUDED.block_hash, payments.block_hash),
                       missing_since = NULL,
                       status = CASE
                         WHEN payments.status IN ('MEMPOOL') AND EXCLUDED.status = 'CONFIRMING'
                           THEN 'CONFIRMING'
                         -- a rejected/replaced/orphaned tx observed on-chain again
                         -- (rebroadcast, re-mined after a reorg, or a prior false
                         -- rejection from a flaky RPC) re-enters the state machine
                         WHEN payments.status IN ('REPLACED', 'REJECTED', 'ORPHANED')
                           THEN EXCLUDED.status
                         ELSE payments.status
                       END
         RETURNING id, (xmax = 0) AS inserted`,
        [
          resolved.invoice_id,
          resolved.id,
          transfer.assetCode,
          transfer.txid,
          transfer.outputIndex,
          transfer.logIndex,
          transfer.amountBaseUnits,
          transfer.fromAddress,
          status,
          transfer.blockHeight,
          transfer.blockHash,
          transfer.isRbf,
        ],
      );
      if ((inserted.rows[0] as unknown as { inserted: boolean }).inserted) {
        this.metrics.paymentsDetected.labels(chain).inc();
        if (transfer.isRbf) this.metrics.paymentAnomalies.labels(chain, 'rbf_flagged').inc();
      }
      if (resolved.invoice_id) {
        events.push(...(await this.recomputeInvoice(client, resolved.invoice_id)));
      }
    });
    await this.flushEvents(events);
  }

  /** Re-checks all open payments of a chain against the node. */
  async reconcileOpenPayments(chain: Chain): Promise<void> {
    const adapter = this.adapters.forChain(chain);
    const open = await this.db.query<PaymentRow>(
      `SELECT p.id, p.invoice_id, p.address_id, p.asset_code, p.txid, p.amount, p.status,
              p.block_height::text, p.block_hash, p.confirmations, p.credited,
              p.credit_epoch, p.missing_since,
              i.required_confirmations AS invoice_required_confirmations
         FROM payments p
         JOIN assets a ON a.code = p.asset_code
         LEFT JOIN invoices i ON i.id = p.invoice_id
        WHERE a.chain = $1
          AND (p.status IN ('MEMPOOL', 'CONFIRMING')
               OR (p.status = 'CONFIRMED' AND p.confirmed_at > now() - interval '24 hours')
               -- recently-dead payments are re-checked (backed off to ~10 min) so a
               -- tx that reappears in an already-scanned block is still recovered
               OR (p.status IN ('REPLACED', 'REJECTED', 'ORPHANED')
                   AND p.detected_at > now() - interval '48 hours'
                   AND p.updated_at < now() - interval '10 minutes'))
        ORDER BY p.detected_at
        LIMIT 500`,
      [chain],
    );

    for (const payment of open) {
      try {
        await this.reconcileOnePayment(chain, adapter, payment);
      } catch (err) {
        this.logger.warn(`reconcile payment ${payment.id} failed: ${(err as Error).message}`);
      }
    }
  }

  private async reconcileOnePayment(
    chain: Chain,
    adapter: ReturnType<AdapterRegistry['forChain']>,
    payment: PaymentRow,
  ): Promise<void> {
    const txStatus = await adapter.getTransactionStatus(payment.txid);
    const events: PendingEvent[] = [];
    const isDead = (DEAD_PAYMENT_STATUSES as readonly string[]).includes(payment.status);

    await this.db.tx(async (client) => {
      const asset = await this.asset(client, payment.asset_code);
      const requiredConfirmations =
        payment.invoice_required_confirmations ?? asset.min_confirmations;

      if (!txStatus.exists) {
        if (isDead) {
          // still gone; touching updated_at backs off the periodic re-check
          await client.query(
            `UPDATE payments SET missing_since = COALESCE(missing_since, now()) WHERE id = $1`,
            [payment.id],
          );
          return;
        }
        // arm / check the missing-grace window before acting: transient RPC or
        // explorer gaps (restarted mempool, lagging failover node) must never
        // reject a live payment or reverse a posted credit
        if (!payment.missing_since) {
          await client.query(`UPDATE payments SET missing_since = now() WHERE id = $1`, [
            payment.id,
          ]);
          return;
        }
        if (Date.now() - new Date(payment.missing_since).getTime() < MISSING_GRACE_MS) return;

        if (payment.status === 'CONFIRMED' && payment.credited) {
          // reorg deeper than the confirmation policy - reverse the credit
          await this.reverseCredit(client, payment, asset);
          await client.query(
            `UPDATE payments
                SET status = 'ORPHANED', credited = false, credit_epoch = credit_epoch + 1
              WHERE id = $1`,
            [payment.id],
          );
          this.metrics.paymentAnomalies.labels(chain, 'reorg_reversal').inc();
          this.logger.error(
            `REORG REVERSAL: credited payment ${payment.id} (${payment.txid}) vanished`,
          );
        } else {
          const newStatus = txStatus.replacedByTxid ? 'REPLACED' : 'REJECTED';
          await client.query(
            `UPDATE payments SET status = $2, replaced_by_txid = $3 WHERE id = $1`,
            [payment.id, newStatus, txStatus.replacedByTxid ?? null],
          );
          this.metrics.paymentAnomalies
            .labels(chain, txStatus.replacedByTxid ? 'rbf_replaced' : 'double_spend')
            .inc();
        }
        if (payment.invoice_id) {
          events.push(...(await this.recomputeInvoice(client, payment.invoice_id)));
        }
        return;
      }

      // tx is on-chain: clear any missing marker and resurrect dead payments
      // (rebroadcast after eviction, re-mined after a reorg, false rejection)
      let status = payment.status;
      if (payment.missing_since !== null || isDead) {
        if (isDead) {
          status = txStatus.blockHeight === null ? 'MEMPOOL' : 'CONFIRMING';
          this.metrics.paymentAnomalies.labels(chain, 'payment_resurrected').inc();
          this.logger.warn(
            `payment ${payment.id} (${payment.txid}) reappeared on-chain - resurrected from ${payment.status}`,
          );
        }
        await client.query(
          `UPDATE payments SET missing_since = NULL, status = $2, replaced_by_txid = NULL
            WHERE id = $1`,
          [payment.id, status],
        );
      }

      // update confirmation state
      if (txStatus.blockHeight === null) {
        if (status === 'CONFIRMING') {
          // fell back out of a block (shallow reorg)
          await client.query(
            `UPDATE payments SET status = 'MEMPOOL', block_height = NULL, block_hash = NULL,
                    confirmations = 0 WHERE id = $1`,
            [payment.id],
          );
          this.metrics.paymentAnomalies.labels(chain, 'reorg').inc();
          if (payment.invoice_id) {
            events.push(...(await this.recomputeInvoice(client, payment.invoice_id)));
          }
        } else if (isDead && payment.invoice_id) {
          events.push(...(await this.recomputeInvoice(client, payment.invoice_id)));
        }
        return;
      }

      const confirmed = txStatus.confirmations >= requiredConfirmations;
      if (status !== 'CONFIRMED') {
        await client.query(
          `UPDATE payments
              SET status = $2, block_height = $3, block_hash = $4, confirmations = $5,
                  confirmed_at = CASE WHEN $2 = 'CONFIRMED' THEN now() ELSE confirmed_at END
            WHERE id = $1`,
          [
            payment.id,
            confirmed ? 'CONFIRMED' : 'CONFIRMING',
            txStatus.blockHeight,
            txStatus.blockHash,
            txStatus.confirmations,
          ],
        );
        if (confirmed) {
          await this.creditPayment(client, { ...payment, block_height: String(txStatus.blockHeight) });
          this.metrics.paymentsConfirmed.labels(chain).inc();
        }
        if (payment.invoice_id) {
          events.push(...(await this.recomputeInvoice(client, payment.invoice_id)));
        }
      } else {
        await client.query(`UPDATE payments SET confirmations = $2 WHERE id = $1`, [
          payment.id,
          txStatus.confirmations,
        ]);
      }
    });

    await this.flushEvents(events);
  }

  /**
   * Journal reference for a payment's credit/reversal pair. The first credit
   * uses the bare payment id (backward compatible with existing journals);
   * re-credits after a reversal get a fresh reference per credit_epoch so the
   * idempotency key doesn't swallow a legitimate re-credit.
   */
  private creditReference(payment: PaymentRow): string {
    return payment.credit_epoch === 0 ? payment.id : `${payment.id}:r${payment.credit_epoch}`;
  }

  /**
   * Credits a confirmed payment to the merchant via the immutable ledger.
   * Idempotent through the journal's unique (type, ref_type, ref_id) key.
   */
  private async creditPayment(client: PoolClient, payment: PaymentRow): Promise<void> {
    const invoice = payment.invoice_id
      ? (
          await client.query<InvoiceRow & { settlement_mode: string }>(
            `SELECT i.*, m.settlement_mode FROM invoices i
              JOIN merchants m ON m.id = i.merchant_id WHERE i.id = $1 FOR UPDATE`,
            [payment.invoice_id],
          )
        ).rows[0]
      : null;
    if (!invoice) {
      // unsolicited deposit to an old address: hold in gateway treasury account
      const external = await this.ledger.ensureAccount(
        client,
        null,
        payment.asset_code,
        'EXTERNAL_DEPOSITS',
      );
      const treasury = await this.ledger.ensureAccount(
        client,
        null,
        payment.asset_code,
        'GATEWAY_TREASURY',
      );
      await this.ledger.postJournal(client, {
        journalType: 'PAYMENT_CONFIRMED',
        referenceType: 'payment',
        referenceId: this.creditReference(payment),
        description: `unsolicited deposit ${payment.txid}`,
        entries: [
          { accountId: external, direction: 'DEBIT', amount: payment.amount, assetCode: payment.asset_code },
          { accountId: treasury, direction: 'CREDIT', amount: payment.amount, assetCode: payment.asset_code },
        ],
      });
    } else if (invoice.purpose === 'TOPUP' && invoice.store_user_id) {
      // store-credit top-up: credit the customer's per-asset balance
      const external = await this.ledger.ensureAccount(
        client,
        null,
        payment.asset_code,
        'EXTERNAL_DEPOSITS',
      );
      const userAccount = await this.ledger.ensureStoreUserAccount(
        client,
        invoice.merchant_id,
        invoice.store_user_id,
        payment.asset_code,
      );
      await this.ledger.postJournal(client, {
        journalType: 'PAYMENT_CONFIRMED',
        referenceType: 'payment',
        referenceId: this.creditReference(payment),
        description: `top-up ${payment.txid} for store user ${invoice.store_user_id}`,
        entries: [
          { accountId: external, direction: 'DEBIT', amount: payment.amount, assetCode: payment.asset_code },
          { accountId: userAccount, direction: 'CREDIT', amount: payment.amount, assetCode: payment.asset_code },
        ],
      });
    } else {
      const target =
        invoice.settlement_mode === 'AUTO_SETTLE' ? 'MERCHANT_AVAILABLE' : 'MERCHANT_PENDING';
      const external = await this.ledger.ensureAccount(
        client,
        null,
        payment.asset_code,
        'EXTERNAL_DEPOSITS',
      );
      const merchantAccount = await this.ledger.ensureAccount(
        client,
        invoice.merchant_id,
        payment.asset_code,
        target,
      );
      await this.ledger.postJournal(client, {
        journalType: 'PAYMENT_CONFIRMED',
        referenceType: 'payment',
        referenceId: this.creditReference(payment),
        description: `payment ${payment.txid} for invoice ${invoice.id}`,
        entries: [
          { accountId: external, direction: 'DEBIT', amount: payment.amount, assetCode: payment.asset_code },
          { accountId: merchantAccount, direction: 'CREDIT', amount: payment.amount, assetCode: payment.asset_code },
        ],
      });
    }
    await client.query(`UPDATE payments SET credited = true WHERE id = $1`, [payment.id]);
  }

  private async reverseCredit(
    client: PoolClient,
    payment: PaymentRow,
    _asset: AssetRow,
  ): Promise<void> {
    const invoice = payment.invoice_id
      ? (
          await client.query<InvoiceRow & { settlement_mode: string }>(
            `SELECT i.*, m.settlement_mode FROM invoices i
              JOIN merchants m ON m.id = i.merchant_id WHERE i.id = $1 FOR UPDATE`,
            [payment.invoice_id],
          )
        ).rows[0]
      : null;
    const external = await this.ledger.ensureAccount(
      client,
      null,
      payment.asset_code,
      'EXTERNAL_DEPOSITS',
    );
    // reverse from wherever it was credited; a balance can go negative here,
    // which is exactly what an audit needs to see
    let counterAccount: string;
    if (invoice && invoice.purpose === 'TOPUP' && invoice.store_user_id) {
      counterAccount = await this.ledger.ensureStoreUserAccount(
        client,
        invoice.merchant_id,
        invoice.store_user_id,
        payment.asset_code,
      );
    } else if (invoice) {
      counterAccount = await this.ledger.ensureAccount(
        client,
        invoice.merchant_id,
        payment.asset_code,
        invoice.settlement_mode === 'AUTO_SETTLE' ? 'MERCHANT_AVAILABLE' : 'MERCHANT_PENDING',
      );
    } else {
      counterAccount = await this.ledger.ensureAccount(
        client,
        null,
        payment.asset_code,
        'GATEWAY_TREASURY',
      );
    }
    await this.ledger.postJournal(client, {
      journalType: 'PAYMENT_REVERSED',
      referenceType: 'payment',
      // pairs with the credit posted at the same epoch
      referenceId: this.creditReference(payment),
      description: `reorg reversal of ${payment.txid}`,
      entries: [
        { accountId: counterAccount, direction: 'DEBIT', amount: payment.amount, assetCode: payment.asset_code },
        { accountId: external, direction: 'CREDIT', amount: payment.amount, assetCode: payment.asset_code },
      ],
    });
    if (invoice) {
      await client.query(`UPDATE invoices SET status = 'INVALID' WHERE id = $1`, [invoice.id]);
    }
  }

  /**
   * Recomputes an invoice's paid amounts and status from its payments.
   * Returns webhook events to emit AFTER the surrounding tx commits.
   */
  async recomputeInvoice(client: PoolClient, invoiceId: string): Promise<PendingEvent[]> {
    const invoiceResult = await client.query<InvoiceRow>(
      `SELECT * FROM invoices WHERE id = $1 FOR UPDATE`,
      [invoiceId],
    );
    const invoice = invoiceResult.rows[0];
    if (!invoice) return [];

    const sums = (
      await client.query<{ mempool: string; inblock: string; confirmed: string }>(
        `SELECT
           COALESCE(SUM(amount) FILTER (WHERE status = 'MEMPOOL'), 0)::text AS mempool,
           COALESCE(SUM(amount) FILTER (WHERE status = 'CONFIRMING'), 0)::text AS inblock,
           COALESCE(SUM(amount) FILTER (WHERE status = 'CONFIRMED'), 0)::text AS confirmed
         FROM payments WHERE invoice_id = $1`,
        [invoiceId],
      )
    ).rows[0];

    const due = invoice.amount_due;
    const tolerance = invoice.underpayment_tolerance_bps;
    const expired = new Date(invoice.expires_at).getTime() < Date.now();
    const mempool = BigInt(sums.mempool);
    const inblock = BigInt(sums.inblock);
    const confirmed = BigInt(sums.confirmed);
    const pending = mempool + inblock;
    const pendingText = pending.toString();

    // INVALID (credit was reversed) stays terminal only while no live payment
    // remains; a re-mined tx or a fresh payment resurrects the invoice
    if (invoice.status === 'INVALID' && confirmed === 0n && pending === 0n) return [];

    // Pure function of the payments (plus the expiry clock). Expiry only ever
    // applies when nothing was detected: a detected payment can never expire,
    // no matter how long its confirmations take.
    let next: string;
    if (confirmed > BigInt(due)) {
      next = 'OVERPAID';
    } else if (confirmed > 0n && meetsWithTolerance(sums.confirmed, due, tolerance)) {
      next = 'PAID';
    } else if (confirmed > 0n && expired && pending === 0n) {
      next = 'UNDERPAID';
    } else if (inblock > 0n || confirmed > 0n) {
      next = 'CONFIRMING';
    } else if (mempool > 0n) {
      next = 'SEEN';
    } else {
      // every detected payment was replaced/rejected (or none ever arrived)
      next = expired ? 'EXPIRED' : 'NEW';
    }

    await client.query(
      `UPDATE invoices
          SET amount_paid_pending = $2, amount_paid_confirmed = $3, status = $4,
              paid_at = CASE WHEN $4 IN ('PAID','OVERPAID') AND paid_at IS NULL THEN now() ELSE paid_at END
        WHERE id = $1`,
      [invoiceId, pendingText, sums.confirmed, next],
    );

    if (next === invoice.status) return [];
    const eventByStatus: Record<string, WebhookEvent | undefined> = {
      SEEN: 'invoice.seen',
      CONFIRMING: 'invoice.confirming',
      PAID: 'invoice.paid',
      OVERPAID: 'invoice.overpaid',
      UNDERPAID: 'invoice.underpaid',
      EXPIRED: 'invoice.expired',
      INVALID: 'invoice.invalid',
    };
    const event = eventByStatus[next];
    if (!event) return [];

    // store top-ups: include the customer ref so a webhook receiver can credit
    // the right user without a follow-up lookup ("verify + add balance")
    let storeUserRef: string | null = null;
    if (invoice.purpose === 'TOPUP' && invoice.store_user_id) {
      const su = await client.query<{ external_ref: string }>(
        `SELECT external_ref FROM store_users WHERE id = $1`,
        [invoice.store_user_id],
      );
      storeUserRef = su.rows[0]?.external_ref ?? null;
    }

    return [
      {
        merchantId: invoice.merchant_id,
        event,
        payload: {
          invoiceId: invoice.id,
          status: next,
          asset: invoice.asset_code,
          amountDue: due,
          amountPaidPending: pendingText,
          amountPaidConfirmed: sums.confirmed,
          purpose: invoice.purpose ?? 'CHECKOUT',
          orderId: invoice.order_id ?? null,
          storeUserRef,
          description: invoice.description ?? null,
        },
      },
    ];
  }

  /**
   * Resolves open invoices past their deadline. Expiry only concerns payment
   * DETECTION: an invoice with any pending (detected) payment is never touched
   * here - confirmation tracking continues independently for as long as it
   * takes. What this tick resolves:
   *   NEW/SEEN/CONFIRMING with nothing detected (or all detections dead) -> EXPIRED
   *   CONFIRMING with a partial confirmed amount and no pending             -> UNDERPAID
   */
  async expireInvoices(): Promise<void> {
    const stale = await this.db.query<{ id: string }>(
      `SELECT id FROM invoices
        WHERE status IN ('NEW', 'SEEN', 'CONFIRMING')
          AND expires_at < now()
          AND amount_paid_pending = 0
        LIMIT 500`,
    );
    for (const row of stale) {
      const events = await this.db.tx((client) => this.recomputeInvoice(client, row.id));
      await this.flushEvents(events);
    }
  }

  private async flushEvents(events: PendingEvent[]): Promise<void> {
    for (const event of events) {
      await this.webhooks.emit(event.merchantId, event.event, event.payload);
    }
  }
}
