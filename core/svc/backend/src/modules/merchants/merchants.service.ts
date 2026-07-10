import {
  BadRequestException,
  Inject,
  Injectable,
  Logger,
  NotFoundException,
  OnApplicationBootstrap,
} from '@nestjs/common';
import parser from 'cron-parser';
import { APP_CONFIG, AppConfig } from '../../config/config';
import { DatabaseService } from '../../database/database.service';
import { AuditService } from '../audit/audit.service';
import { hashPassword } from '../../common/utils/crypto.util';

export interface MerchantProfile {
  id: string;
  email: string;
  name: string;
  role: string;
  status: string;
  totp_enabled: boolean;
  settlement_mode: string;
  settlement_schedule_cron: string | null;
  underpayment_tolerance_bps: number;
  default_invoice_ttl_seconds: number | null;
  created_at: string;
}

@Injectable()
export class MerchantsService implements OnApplicationBootstrap {
  private readonly logger = new Logger(MerchantsService.name);

  constructor(
    private readonly db: DatabaseService,
    private readonly audit: AuditService,
    @Inject(APP_CONFIG) private readonly config: AppConfig,
  ) {}

  /** First boot: create the admin account if no admin exists (api role only). */
  async onApplicationBootstrap(): Promise<void> {
    if (this.config.appRole !== 'api') return;
    const existing = await this.db.queryOne(
      `SELECT 1 AS x FROM merchants WHERE role = 'ADMIN' LIMIT 1`,
    );
    if (existing) return;
    const passwordHash = await hashPassword(this.config.adminInitialPassword);
    const row = await this.db.queryOne<{ id: string }>(
      `INSERT INTO merchants (email, password_hash, name, role, force_password_change)
       VALUES ($1, $2, 'Administrator', 'ADMIN', true)
       ON CONFLICT (email) DO NOTHING
       RETURNING id`,
      [this.config.adminEmail, passwordHash],
    );
    if (row) {
      this.logger.log(`created initial admin account ${this.config.adminEmail}`);
      await this.audit.log({
        actorType: 'SYSTEM',
        action: 'admin.bootstrap_created',
        resourceType: 'merchant',
        resourceId: row.id,
      });
    }
  }

  async profile(merchantId: string): Promise<MerchantProfile> {
    const row = await this.db.queryOne<MerchantProfile>(
      `SELECT id, email, name, role, status, totp_enabled, settlement_mode,
              settlement_schedule_cron, underpayment_tolerance_bps,
              default_invoice_ttl_seconds, created_at
         FROM merchants WHERE id = $1`,
      [merchantId],
    );
    if (!row) throw new NotFoundException('merchant not found');
    return row;
  }

  async updateSettings(
    merchantId: string,
    input: {
      name?: string;
      settlementMode?: 'HOLD' | 'AUTO_SETTLE' | 'MANUAL' | 'SCHEDULED';
      settlementScheduleCron?: string | null;
      underpaymentToleranceBps?: number;
      /** merchant default invoice expiry in minutes (5 min to 30 days); 0 clears it (asset default) */
      defaultInvoiceExpiryMinutes?: number | null;
    },
    ip?: string,
  ): Promise<MerchantProfile> {
    if (input.settlementMode === 'SCHEDULED') {
      if (!input.settlementScheduleCron) {
        throw new BadRequestException('SCHEDULED settlement requires a cron expression');
      }
      try {
        parser.parseExpression(input.settlementScheduleCron);
      } catch {
        throw new BadRequestException('invalid cron expression');
      }
    }
    // default invoice expiry: undefined = leave unchanged, 0/null = clear (use asset default),
    // otherwise clamp between 5 minutes and 30 days.
    let defaultTtlSeconds: number | null | undefined = undefined;
    if (input.defaultInvoiceExpiryMinutes !== undefined) {
      const mins = input.defaultInvoiceExpiryMinutes;
      if (mins === null || mins === 0) {
        defaultTtlSeconds = null;
      } else if (mins < 5 || mins > 43_200) {
        throw new BadRequestException(
          'defaultInvoiceExpiryMinutes must be between 5 (5 min) and 43200 (30 days), or 0 for the default',
        );
      } else {
        defaultTtlSeconds = mins * 60;
      }
    }
    await this.db.query(
      `UPDATE merchants SET
          name = COALESCE($2, name),
          settlement_mode = COALESCE($3, settlement_mode),
          settlement_schedule_cron = CASE WHEN $3 = 'SCHEDULED' THEN $4
                                          WHEN $3 IS NOT NULL THEN NULL
                                          ELSE settlement_schedule_cron END,
          underpayment_tolerance_bps = COALESCE($5, underpayment_tolerance_bps),
          default_invoice_ttl_seconds = CASE WHEN $6::boolean THEN $7 ELSE default_invoice_ttl_seconds END
        WHERE id = $1`,
      [
        merchantId,
        input.name ?? null,
        input.settlementMode ?? null,
        input.settlementScheduleCron ?? null,
        input.underpaymentToleranceBps ?? null,
        defaultTtlSeconds !== undefined,
        defaultTtlSeconds ?? null,
      ],
    );
    await this.audit.log({
      actorType: 'MERCHANT',
      actorId: merchantId,
      action: 'merchant.settings_updated',
      ip,
      metadata: input as Record<string, unknown>,
    });
    return this.profile(merchantId);
  }

  // -- admin --------------------------------------------------------------------
  async adminList(limit = 25, offset = 0): Promise<MerchantProfile[]> {
    return this.db.query<MerchantProfile>(
      `SELECT id, email, name, role, status, totp_enabled, settlement_mode,
              settlement_schedule_cron, underpayment_tolerance_bps,
              default_invoice_ttl_seconds, created_at
         FROM merchants ORDER BY created_at DESC LIMIT $1 OFFSET $2`,
      [limit, offset],
    );
  }

  async adminSetStatus(
    adminId: string,
    merchantId: string,
    status: 'ACTIVE' | 'SUSPENDED',
    ip?: string,
  ): Promise<void> {
    const result = await this.db.query<{ id: string }>(
      `UPDATE merchants SET status = $2 WHERE id = $1 AND role = 'MERCHANT' RETURNING id`,
      [merchantId, status],
    );
    if (result.length === 0) throw new NotFoundException('merchant not found (admins cannot be suspended)');
    if (status === 'SUSPENDED') {
      await this.db.query(
        `UPDATE refresh_tokens SET revoked_at = now() WHERE merchant_id = $1 AND revoked_at IS NULL`,
        [merchantId],
      );
    }
    await this.audit.log({
      actorType: 'ADMIN',
      actorId: adminId,
      action: `merchant.${status.toLowerCase()}`,
      resourceType: 'merchant',
      resourceId: merchantId,
      ip,
    });
  }
}
