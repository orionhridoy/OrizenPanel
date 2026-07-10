import {
  BadRequestException,
  ConflictException,
  Inject,
  Injectable,
  NotFoundException,
  UnauthorizedException,
} from '@nestjs/common';
import * as jwt from 'jsonwebtoken';
import { APP_CONFIG, AppConfig } from '../../config/config';
import { DatabaseService } from '../../database/database.service';
import { LedgerService } from '../ledger/ledger.service';
import { InvoicesService, InvoiceView } from '../invoices/invoices.service';
import { AuditService } from '../audit/audit.service';
import { AssetCode } from '../../common/types';
import { baseUnitsToDecimal, decimalToBaseUnits } from '../../common/utils/base-units.util';

const SESSION_TTL_SECONDS = 24 * 60 * 60;

export interface StoreSession {
  merchantId: string;
  storeUserId: string;
  externalRef: string;
  type: 'store_session';
}

export interface StoreUserRow {
  id: string;
  merchant_id: string;
  external_ref: string;
  display_name: string | null;
  email: string | null;
}

export interface StoreBalance {
  asset: string;
  balanceBaseUnits: string;
  balanceDecimal: string;
}

@Injectable()
export class StoreService {
  constructor(
    private readonly db: DatabaseService,
    private readonly ledger: LedgerService,
    private readonly invoices: InvoicesService,
    private readonly audit: AuditService,
    @Inject(APP_CONFIG) private readonly config: AppConfig,
  ) {}

  private async assertStoreEnabled(merchantId: string): Promise<{ name: string; storeName: string | null }> {
    const row = await this.db.queryOne<{ store_enabled: boolean; name: string; store_name: string | null; status: string }>(
      `SELECT store_enabled, name, store_name, status FROM merchants WHERE id = $1`,
      [merchantId],
    );
    if (!row || row.status !== 'ACTIVE') throw new NotFoundException('store not found');
    if (!row.store_enabled) throw new BadRequestException('store is not enabled');
    return { name: row.name, storeName: row.store_name };
  }

  async ensureStoreUser(
    merchantId: string,
    externalRef: string,
    displayName?: string,
    email?: string,
  ): Promise<StoreUserRow> {
    const ref = externalRef.trim();
    if (ref === '' || ref.length > 200) throw new BadRequestException('invalid externalRef');
    const existing = await this.db.queryOne<StoreUserRow>(
      `SELECT id, merchant_id, external_ref, display_name, email
         FROM store_users WHERE merchant_id = $1 AND external_ref = $2`,
      [merchantId, ref],
    );
    if (existing) {
      if (displayName || email) {
        await this.db.query(
          `UPDATE store_users SET display_name = COALESCE($3, display_name),
                  email = COALESCE($4, email) WHERE id = $1 AND merchant_id = $2`,
          [existing.id, merchantId, displayName ?? null, email ?? null],
        );
      }
      return existing;
    }
    const created = await this.db.queryOne<StoreUserRow>(
      `INSERT INTO store_users (merchant_id, external_ref, display_name, email)
       VALUES ($1, $2, $3, $4)
       ON CONFLICT (merchant_id, external_ref) DO UPDATE SET external_ref = EXCLUDED.external_ref
       RETURNING id, merchant_id, external_ref, display_name, email`,
      [merchantId, ref, displayName ?? null, email ?? null],
    );
    return created as StoreUserRow;
  }

  /** Merchant (API key) mints a signed session so a customer can open the hosted page. */
  async createSession(
    merchantId: string,
    externalRef: string,
    displayName?: string,
    email?: string,
  ): Promise<{ token: string; url: string; expiresIn: number; storeUserId: string }> {
    await this.assertStoreEnabled(merchantId);
    const user = await this.ensureStoreUser(merchantId, externalRef, displayName, email);
    const payload: StoreSession = {
      merchantId,
      storeUserId: user.id,
      externalRef: user.external_ref,
      type: 'store_session',
    };
    const token = jwt.sign(payload, this.config.jwtAccessSecret, {
      algorithm: 'HS256',
      expiresIn: SESSION_TTL_SECONDS,
    });
    return {
      token,
      url: `${this.config.publicUrl}/store?t=${token}`,
      expiresIn: SESSION_TTL_SECONDS,
      storeUserId: user.id,
    };
  }

  verifySession(token: string): StoreSession {
    let payload: StoreSession;
    try {
      payload = jwt.verify(token, this.config.jwtAccessSecret, { algorithms: ['HS256'] }) as StoreSession;
    } catch {
      throw new UnauthorizedException('invalid or expired store session');
    }
    if (payload.type !== 'store_session' || !payload.storeUserId || !payload.merchantId) {
      throw new UnauthorizedException('invalid store session');
    }
    return payload;
  }

  async sessionView(session: StoreSession): Promise<{
    storeName: string;
    merchantName: string;
    externalRef: string;
    assets: Array<{ code: string; displayName: string; decimals: number; minTopupDecimal: string }>;
    balances: StoreBalance[];
    publicUrl: string;
  }> {
    const store = await this.assertStoreEnabled(session.merchantId);
    const assets = await this.db.query<{ code: string; display_name: string; decimals: number; dust_threshold: string }>(
      `SELECT code, display_name, decimals, dust_threshold::text
         FROM assets WHERE enabled ORDER BY code`,
    );
    const balances = await this.balances(session.storeUserId);
    return {
      storeName: store.storeName ?? store.name,
      merchantName: store.name,
      externalRef: session.externalRef,
      assets: assets.map((a) => ({
        code: a.code,
        displayName: a.display_name,
        decimals: a.decimals,
        minTopupDecimal: baseUnitsToDecimal(
          (BigInt(a.dust_threshold) + 1n).toString(),
          a.decimals,
        ),
      })),
      balances,
      publicUrl: this.config.publicUrl,
    };
  }

  async balances(storeUserId: string): Promise<StoreBalance[]> {
    const rows = await this.ledger.storeUserBalances(storeUserId);
    const decimals = await this.assetDecimals();
    return rows
      .filter((r) => r.type === 'STORE_USER_AVAILABLE')
      .map((r) => ({
        asset: r.asset_code,
        balanceBaseUnits: r.balance,
        balanceDecimal: baseUnitsToDecimal(r.balance, decimals[r.asset_code] ?? 8),
      }));
  }

  async balancesByRef(merchantId: string, externalRef: string): Promise<StoreBalance[]> {
    const user = await this.db.queryOne<{ id: string }>(
      `SELECT id FROM store_users WHERE merchant_id = $1 AND external_ref = $2`,
      [merchantId, externalRef],
    );
    if (!user) throw new NotFoundException('store user not found');
    return this.balances(user.id);
  }

  /** Customer creates a top-up invoice from the hosted page (crypto or fiat amount). */
  async createTopup(
    session: StoreSession,
    assetCode: AssetCode,
    opts: { amountDecimal?: string; fiatAmount?: string; fiatCurrency?: string },
  ): Promise<InvoiceView> {
    await this.assertStoreEnabled(session.merchantId);
    const invoice = await this.invoices.create(session.merchantId, {
      assetCode,
      amountDecimal: opts.amountDecimal,
      fiatAmount: opts.fiatAmount,
      fiatCurrency: opts.fiatCurrency,
      description: `Store credit top-up (${session.externalRef})`,
      storeUserId: session.storeUserId,
      purpose: 'TOPUP',
    });
    return invoice;
  }

  /**
   * Merchant (API key / server-to-server) spends a customer's balance to buy a
   * tool: DEBIT the customer's store balance, CREDIT the merchant's available
   * balance. Idempotent per (merchant, idempotencyKey).
   */
  async purchase(input: {
    merchantId: string;
    externalRef: string;
    assetCode: AssetCode;
    amountDecimal: string;
    description?: string;
    idempotencyKey?: string;
    actorId: string;
    ip?: string | null;
  }): Promise<{ purchaseId: string; assetCode: string; amount: string; remainingBalanceDecimal: string }> {
    const asset = await this.db.queryOne<{ decimals: number }>(
      `SELECT decimals FROM assets WHERE code = $1 AND enabled`,
      [input.assetCode],
    );
    if (!asset) throw new BadRequestException(`asset ${input.assetCode} not available`);
    let amount: bigint;
    try {
      amount = BigInt(decimalToBaseUnits(input.amountDecimal, asset.decimals));
    } catch (err) {
      throw new BadRequestException((err as Error).message);
    }
    if (amount <= 0n) throw new BadRequestException('amount must be positive');

    return this.db.tx(async (client) => {
      const user = (
        await client.query<{ id: string }>(
          `SELECT id FROM store_users WHERE merchant_id = $1 AND external_ref = $2 FOR UPDATE`,
          [input.merchantId, input.externalRef],
        )
      ).rows[0];
      if (!user) throw new NotFoundException('store user not found');

      if (input.idempotencyKey) {
        const dup = (
          await client.query<{ id: string; amount: string }>(
            `SELECT id, amount::text FROM store_purchases WHERE merchant_id = $1 AND idempotency_key = $2`,
            [input.merchantId, input.idempotencyKey],
          )
        ).rows[0];
        if (dup) {
          const remaining = await this.ledger.storeUserBalanceOf(
            client, input.merchantId, user.id, input.assetCode);
          return {
            purchaseId: dup.id,
            assetCode: input.assetCode,
            amount: dup.amount,
            remainingBalanceDecimal: baseUnitsToDecimal(remaining, asset.decimals),
          };
        }
      }

      const available = BigInt(
        await this.ledger.storeUserBalanceOf(client, input.merchantId, user.id, input.assetCode),
      );
      if (available < amount) {
        throw new ConflictException(
          `insufficient store balance: have ${baseUnitsToDecimal(available.toString(), asset.decimals)}, need ${input.amountDecimal} ${input.assetCode}`,
        );
      }

      const purchase = (
        await client.query<{ id: string }>(
          `INSERT INTO store_purchases (merchant_id, store_user_id, asset_code, amount, description, idempotency_key)
           VALUES ($1, $2, $3, $4, $5, $6) RETURNING id`,
          [
            input.merchantId,
            user.id,
            input.assetCode,
            amount.toString(),
            input.description ?? null,
            input.idempotencyKey ?? null,
          ],
        )
      ).rows[0];

      const userAcc = await this.ledger.ensureStoreUserAccount(
        client, input.merchantId, user.id, input.assetCode);
      const merchantAcc = await this.ledger.ensureAccount(
        client, input.merchantId, input.assetCode, 'MERCHANT_AVAILABLE');
      await this.ledger.postJournal(client, {
        journalType: 'STORE_PURCHASE',
        referenceType: 'store_purchase',
        referenceId: purchase.id,
        description: input.description ?? `store purchase ${purchase.id}`,
        entries: [
          { accountId: userAcc, direction: 'DEBIT', amount: amount.toString(), assetCode: input.assetCode },
          { accountId: merchantAcc, direction: 'CREDIT', amount: amount.toString(), assetCode: input.assetCode },
        ],
      });

      await this.audit.log(
        {
          actorType: 'API_KEY',
          actorId: input.actorId,
          action: 'store.purchase',
          resourceType: 'store_purchase',
          resourceId: purchase.id,
          ip: input.ip,
          metadata: { externalRef: input.externalRef, asset: input.assetCode, amount: amount.toString() },
        },
        client,
      );

      const remaining = await this.ledger.storeUserBalanceOf(
        client, input.merchantId, user.id, input.assetCode);
      return {
        purchaseId: purchase.id,
        assetCode: input.assetCode,
        amount: amount.toString(),
        remainingBalanceDecimal: baseUnitsToDecimal(remaining, asset.decimals),
      };
    });
  }

  private async assetDecimals(): Promise<Record<string, number>> {
    const rows = await this.db.query<{ code: string; decimals: number }>(
      `SELECT code, decimals FROM assets`,
    );
    return Object.fromEntries(rows.map((r) => [r.code, r.decimals]));
  }
}
