import { Injectable, Logger } from '@nestjs/common';
import { PoolClient } from 'pg';
import { DatabaseService } from '../../database/database.service';

export type LedgerAccountType =
  | 'MERCHANT_AVAILABLE'
  | 'MERCHANT_PENDING'
  | 'MERCHANT_LOCKED'
  | 'GATEWAY_FEES'
  | 'GATEWAY_TREASURY'
  | 'EXTERNAL_DEPOSITS'
  | 'EXTERNAL_WITHDRAWALS'
  | 'STORE_USER_AVAILABLE';

export type JournalType =
  | 'PAYMENT_CONFIRMED'
  | 'PAYMENT_REVERSED'
  | 'SETTLEMENT'
  | 'SWEEP'
  | 'WITHDRAWAL_LOCK'
  | 'WITHDRAWAL_EXECUTE'
  | 'WITHDRAWAL_RELEASE'
  | 'FEE'
  | 'ADJUSTMENT'
  | 'STORE_PURCHASE';

export interface JournalEntry {
  accountId: string;
  direction: 'DEBIT' | 'CREDIT';
  amount: string; // base units, positive
  assetCode: string;
}

export interface JournalRequest {
  journalType: JournalType;
  referenceType: string;
  referenceId: string;
  description: string;
  createdBy?: string;
  entries: JournalEntry[];
}

export interface BalanceRow {
  asset_code: string;
  type: LedgerAccountType;
  balance: string;
}

/**
 * The ONLY way money moves in Orizen Pay. Balances are never written directly:
 * inserting balanced journal entries drives the account_balances cache via a
 * database trigger, and DB-level triggers make the journal append-only.
 */
@Injectable()
export class LedgerService {
  private readonly logger = new Logger(LedgerService.name);

  constructor(private readonly db: DatabaseService) {}

  /** Get-or-create a merchant/system account. merchantId null for system. */
  async ensureAccount(
    client: PoolClient,
    merchantId: string | null,
    assetCode: string,
    type: LedgerAccountType,
  ): Promise<string> {
    return this.ensureAccountInternal(client, merchantId, null, assetCode, type);
  }

  /** Get-or-create a per-store-user balance account. */
  async ensureStoreUserAccount(
    client: PoolClient,
    merchantId: string,
    storeUserId: string,
    assetCode: string,
    type: LedgerAccountType = 'STORE_USER_AVAILABLE',
  ): Promise<string> {
    return this.ensureAccountInternal(client, merchantId, storeUserId, assetCode, type);
  }

  private async ensureAccountInternal(
    client: PoolClient,
    merchantId: string | null,
    storeUserId: string | null,
    assetCode: string,
    type: LedgerAccountType,
  ): Promise<string> {
    const existing = await client.query<{ id: string }>(
      `SELECT id FROM ledger_accounts
        WHERE merchant_id IS NOT DISTINCT FROM $1
          AND store_user_id IS NOT DISTINCT FROM $2
          AND asset_code = $3 AND type = $4`,
      [merchantId, storeUserId, assetCode, type],
    );
    if (existing.rows[0]) return existing.rows[0].id;
    const inserted = await client.query<{ id: string }>(
      `INSERT INTO ledger_accounts (merchant_id, store_user_id, asset_code, type)
       VALUES ($1, $2, $3, $4)
       ON CONFLICT (merchant_id, store_user_id, asset_code, type) DO UPDATE SET type = EXCLUDED.type
       RETURNING id`,
      [merchantId, storeUserId, assetCode, type],
    );
    return inserted.rows[0].id;
  }

  /**
   * Posts a balanced journal inside the caller's transaction.
   * Idempotent per (journalType, referenceType, referenceId): posting the same
   * business event twice is a no-op and returns null.
   */
  async postJournal(client: PoolClient, request: JournalRequest): Promise<string | null> {
    this.assertBalanced(request.entries);

    const journal = await client.query<{ id: string }>(
      `INSERT INTO ledger_journal (journal_type, reference_type, reference_id, description, created_by)
       VALUES ($1, $2, $3, $4, $5)
       ON CONFLICT (journal_type, reference_type, reference_id) DO NOTHING
       RETURNING id`,
      [
        request.journalType,
        request.referenceType,
        request.referenceId,
        request.description,
        request.createdBy ?? 'system',
      ],
    );
    if (!journal.rows[0]) {
      this.logger.warn(
        `journal ${request.journalType}/${request.referenceType}/${request.referenceId} already posted - skipping`,
      );
      return null;
    }
    const journalId = journal.rows[0].id;
    for (const entry of request.entries) {
      await client.query(
        `INSERT INTO ledger_entries (journal_id, account_id, asset_code, direction, amount)
         VALUES ($1, $2, $3, $4, $5)`,
        [journalId, entry.accountId, entry.assetCode, entry.direction, entry.amount],
      );
    }
    return journalId;
  }

  /** Signed balance (CREDIT - DEBIT) from the cache; 0 if account not created yet. */
  async balanceOf(
    client: PoolClient,
    merchantId: string | null,
    assetCode: string,
    type: LedgerAccountType,
  ): Promise<string> {
    const row = await client.query<{ balance: string }>(
      `SELECT b.balance
         FROM ledger_accounts a
         JOIN account_balances b ON b.account_id = a.id
        WHERE a.merchant_id IS NOT DISTINCT FROM $1 AND a.asset_code = $2 AND a.type = $3`,
      [merchantId, assetCode, type],
    );
    return row.rows[0]?.balance ?? '0';
  }

  async merchantBalances(merchantId: string): Promise<BalanceRow[]> {
    return this.db.query<BalanceRow>(
      `SELECT a.asset_code, a.type, COALESCE(b.balance, '0') AS balance
         FROM ledger_accounts a
         LEFT JOIN account_balances b ON b.account_id = a.id
        WHERE a.merchant_id = $1 AND a.store_user_id IS NULL
        ORDER BY a.asset_code, a.type`,
      [merchantId],
    );
  }

  /** Per-asset available balances for one store user. */
  async storeUserBalances(storeUserId: string): Promise<BalanceRow[]> {
    return this.db.query<BalanceRow>(
      `SELECT a.asset_code, a.type, COALESCE(b.balance, '0') AS balance
         FROM ledger_accounts a
         LEFT JOIN account_balances b ON b.account_id = a.id
        WHERE a.store_user_id = $1
        ORDER BY a.asset_code`,
      [storeUserId],
    );
  }

  async storeUserBalanceOf(
    client: PoolClient,
    merchantId: string,
    storeUserId: string,
    assetCode: string,
  ): Promise<string> {
    const row = await client.query<{ balance: string }>(
      `SELECT b.balance FROM ledger_accounts a
         JOIN account_balances b ON b.account_id = a.id
        WHERE a.merchant_id = $1 AND a.store_user_id = $2
          AND a.asset_code = $3 AND a.type = 'STORE_USER_AVAILABLE'`,
      [merchantId, storeUserId, assetCode],
    );
    return row.rows[0]?.balance ?? '0';
  }

  /**
   * Reconciliation: prove the balance cache against SUM(entries).
   * Returns drifted accounts - anything here indicates a serious defect.
   */
  async findDrift(): Promise<Array<{ account_id: string; cached: string; computed: string }>> {
    return this.db.query(
      `SELECT c.account_id, COALESCE(b.balance, '0') AS cached, c.balance AS computed
         FROM ledger_balances_computed c
         LEFT JOIN account_balances b ON b.account_id = c.account_id
        WHERE COALESCE(b.balance, '0') <> c.balance`,
    );
  }

  private assertBalanced(entries: JournalEntry[]): void {
    if (entries.length < 2) throw new Error('journal requires at least two entries');
    const perAsset = new Map<string, bigint>();
    for (const entry of entries) {
      const amount = BigInt(entry.amount);
      if (amount <= 0n) throw new Error('entry amounts must be positive');
      const delta = entry.direction === 'DEBIT' ? amount : -amount;
      perAsset.set(entry.assetCode, (perAsset.get(entry.assetCode) ?? 0n) + delta);
    }
    for (const [asset, net] of perAsset) {
      if (net !== 0n) throw new Error(`unbalanced journal for ${asset}: net ${net}`);
    }
  }
}
