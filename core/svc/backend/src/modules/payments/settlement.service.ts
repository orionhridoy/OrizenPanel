import { Injectable, Logger } from '@nestjs/common';
import parser from 'cron-parser';
import { DatabaseService } from '../../database/database.service';
import { LedgerService } from '../ledger/ledger.service';
import { AuditService } from '../audit/audit.service';

/**
 * Settlement moves confirmed funds MERCHANT_PENDING -> MERCHANT_AVAILABLE.
 *
 *   AUTO_SETTLE - credited straight to AVAILABLE by the payment engine.
 *   HOLD        - released only by an explicit merchant/admin action.
 *   MANUAL      - same as HOLD (merchant triggers via API/dashboard).
 *   SCHEDULED   - released here whenever the merchant's cron fires.
 */
@Injectable()
export class SettlementService {
  private readonly logger = new Logger(SettlementService.name);

  constructor(
    private readonly db: DatabaseService,
    private readonly ledger: LedgerService,
    private readonly audit: AuditService,
  ) {}

  /** Worker tick: run all due SCHEDULED settlements. */
  async runScheduled(): Promise<void> {
    const merchants = await this.db.query<{ id: string; settlement_schedule_cron: string }>(
      `SELECT id, settlement_schedule_cron FROM merchants
        WHERE settlement_mode = 'SCHEDULED' AND status = 'ACTIVE'
          AND settlement_schedule_cron IS NOT NULL`,
    );
    for (const merchant of merchants) {
      try {
        if (await this.isDue(merchant.id, merchant.settlement_schedule_cron)) {
          await this.settleMerchant(merchant.id, 'SYSTEM', null);
          await this.markRun(merchant.id);
        }
      } catch (err) {
        this.logger.warn(`scheduled settlement for ${merchant.id} failed: ${(err as Error).message}`);
      }
    }
  }

  private async isDue(merchantId: string, cron: string): Promise<boolean> {
    const key = `settlement.last_run:${merchantId}`;
    const row = await this.db.queryOne<{ value: string }>(
      `SELECT value #>> '{}' AS value FROM settings WHERE key = $1`,
      [key],
    );
    const lastRun = row ? new Date(row.value) : new Date(0);
    const interval = parser.parseExpression(cron, { currentDate: lastRun });
    return interval.next().toDate().getTime() <= Date.now();
  }

  private async markRun(merchantId: string): Promise<void> {
    await this.db.query(
      `INSERT INTO settings (key, value) VALUES ($1, to_jsonb($2::text))
       ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value, updated_at = now()`,
      [`settlement.last_run:${merchantId}`, new Date().toISOString()],
    );
  }

  /**
   * Releases the full pending balance for every asset (or one asset).
   * Also used by the merchant "settle now" API for HOLD/MANUAL modes.
   */
  async settleMerchant(
    merchantId: string,
    actorType: 'MERCHANT' | 'ADMIN' | 'SYSTEM',
    actorId: string | null,
    assetCode?: string,
  ): Promise<Array<{ assetCode: string; amount: string }>> {
    const released: Array<{ assetCode: string; amount: string }> = [];
    await this.db.tx(async (client) => {
      const pending = await client.query<{ asset_code: string; balance: string; account_id: string }>(
        `SELECT a.asset_code, b.balance, a.id AS account_id
           FROM ledger_accounts a
           JOIN account_balances b ON b.account_id = a.id
          WHERE a.merchant_id = $1 AND a.type = 'MERCHANT_PENDING' AND b.balance::numeric > 0
            AND ($2::text IS NULL OR a.asset_code = $2)
          FOR UPDATE OF b`,
        [merchantId, assetCode ?? null],
      );
      for (const row of pending.rows) {
        const availableAcc = await this.ledger.ensureAccount(
          client,
          merchantId,
          row.asset_code,
          'MERCHANT_AVAILABLE',
        );
        const settlementId = (
          await client.query<{ id: string }>(`SELECT uuid_generate_v7() AS id`)
        ).rows[0].id;
        await this.ledger.postJournal(client, {
          journalType: 'SETTLEMENT',
          referenceType: 'settlement',
          referenceId: settlementId,
          description: `settle pending -> available (${actorType.toLowerCase()})`,
          createdBy: actorId ?? 'system',
          entries: [
            { accountId: row.account_id, direction: 'DEBIT', amount: row.balance, assetCode: row.asset_code },
            { accountId: availableAcc, direction: 'CREDIT', amount: row.balance, assetCode: row.asset_code },
          ],
        });
        released.push({ assetCode: row.asset_code, amount: row.balance });
      }
      if (released.length > 0) {
        await this.audit.log(
          {
            actorType: actorType === 'SYSTEM' ? 'SYSTEM' : actorType,
            actorId,
            action: 'settlement.released',
            resourceType: 'merchant',
            resourceId: merchantId,
            metadata: { released },
          },
          client,
        );
      }
    });
    return released;
  }
}
