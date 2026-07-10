import {
  BadRequestException,
  Inject,
  Injectable,
  NotFoundException,
} from '@nestjs/common';
import { APP_CONFIG, AppConfig } from '../../config/config';
import { DatabaseService } from '../../database/database.service';
import { WalletsService } from '../wallets/wallets.service';
import { MetricsService } from '../metrics/metrics.service';
import { RatesService } from '../rates/rates.service';
import { AssetCode, AssetRow, Chain } from '../../common/types';
import { baseUnitsToDecimal, decimalToBaseUnits } from '../../common/utils/base-units.util';

export interface InvoiceView {
  id: string;
  orderId: string | null;
  status: string;
  asset: AssetCode;
  chain: Chain;
  amountDue: string;
  amountDueDecimal: string;
  fiatCurrency: string | null;
  fiatAmount: string | null;
  exchangeRate: string | null;
  amountPaidPending: string;
  amountPaidConfirmed: string;
  address: string;
  destinationTag: number | null;
  paymentUri: string;
  requiredConfirmations: number;
  description: string | null;
  metadata: Record<string, unknown>;
  redirectUrl: string | null;
  checkoutUrl: string;
  expiresAt: string;
  paidAt: string | null;
  createdAt: string;
}

interface InvoiceJoinRow {
  id: string;
  merchant_id: string;
  order_id: string | null;
  asset_code: AssetCode;
  chain: Chain;
  decimals: number;
  amount_due: string;
  amount_paid_pending: string;
  amount_paid_confirmed: string;
  status: string;
  required_confirmations: number;
  description: string | null;
  metadata: Record<string, unknown>;
  redirect_url: string | null;
  expires_at: string;
  paid_at: string | null;
  created_at: string;
  address: string;
  destination_tag: string | null;
  fiat_currency: string | null;
  fiat_amount: string | null;
  exchange_rate: string | null;
}

const INVOICE_SELECT = `
  SELECT i.id, i.merchant_id, i.order_id, i.asset_code, a.chain, a.decimals,
         i.amount_due::text, i.amount_paid_pending::text, i.amount_paid_confirmed::text,
         i.status, i.required_confirmations, i.description, i.metadata, i.redirect_url,
         i.expires_at, i.paid_at, i.created_at,
         i.fiat_currency, i.fiat_amount::text, i.exchange_rate::text,
         wa.address, wa.destination_tag::text
    FROM invoices i
    JOIN assets a ON a.code = i.asset_code
    JOIN wallet_addresses wa ON wa.id = i.address_id`;

@Injectable()
export class InvoicesService {
  constructor(
    private readonly db: DatabaseService,
    private readonly wallets: WalletsService,
    private readonly metrics: MetricsService,
    private readonly rates: RatesService,
    @Inject(APP_CONFIG) private readonly config: AppConfig,
  ) {}

  async create(
    merchantId: string,
    input: {
      assetCode: AssetCode;
      amountDecimal?: string;
      fiatAmount?: string;
      fiatCurrency?: string;
      orderId?: string;
      description?: string;
      redirectUrl?: string;
      metadata?: Record<string, unknown>;
      storeUserId?: string;
      purpose?: 'MERCHANT' | 'TOPUP';
      /** custom expiry in minutes (5 min to 30 days); defaults to the asset TTL */
      expiresInMinutes?: number;
    },
  ): Promise<InvoiceView> {
    const asset = await this.db.queryOne<AssetRow>(
      `SELECT * FROM assets WHERE code = $1 AND enabled`,
      [input.assetCode],
    );
    if (!asset) throw new BadRequestException(`asset ${input.assetCode} not available`);

    let amountDue: string;
    let fiatCurrency: string | null = null;
    let fiatAmount: string | null = null;
    let exchangeRate: number | null = null;
    if (input.fiatAmount && input.fiatCurrency) {
      // fiat-priced: convert to crypto at the current market rate
      const conv = await this.rates.fiatToCrypto(
        input.fiatAmount,
        asset.code,
        input.fiatCurrency,
        asset.decimals,
      );
      amountDue = conv.amountBaseUnits;
      fiatCurrency = input.fiatCurrency.toUpperCase();
      fiatAmount = input.fiatAmount;
      exchangeRate = conv.rate;
    } else if (input.amountDecimal) {
      try {
        amountDue = decimalToBaseUnits(input.amountDecimal, asset.decimals);
      } catch (err) {
        throw new BadRequestException((err as Error).message);
      }
    } else {
      throw new BadRequestException('provide either amount (crypto) or fiatAmount + fiatCurrency');
    }
    if (BigInt(amountDue) <= BigInt(asset.dust_threshold)) {
      throw new BadRequestException(
        `amount is at or below the dust threshold for ${asset.code}`,
      );
    }
    if (input.metadata && JSON.stringify(input.metadata).length > 4096) {
      throw new BadRequestException('metadata exceeds 4KB');
    }

    const tolerance = await this.db.queryOne<{
      underpayment_tolerance_bps: number;
      default_invoice_ttl_seconds: number | null;
    }>(
      `SELECT underpayment_tolerance_bps, default_invoice_ttl_seconds
         FROM merchants WHERE id = $1 AND status = 'ACTIVE'`,
      [merchantId],
    );
    if (!tolerance) throw new BadRequestException('merchant not active');

    // Expiry precedence: explicit per-invoice value > merchant default (Settings) > asset default.
    // Explicit values are clamped between 5 minutes and 30 days.
    let ttlSeconds = tolerance.default_invoice_ttl_seconds ?? asset.invoice_ttl_seconds;
    if (input.expiresInMinutes !== undefined) {
      if (input.expiresInMinutes < 5 || input.expiresInMinutes > 43_200) {
        throw new BadRequestException('expiresInMinutes must be between 5 (5 min) and 43200 (30 days)');
      }
      ttlSeconds = input.expiresInMinutes * 60;
    }

    const invoiceId = await this.db.tx(async (client) => {
      const issued = await this.wallets.issueAddress(
        client,
        asset.chain,
        asset.code,
        `invoice:${input.orderId ?? 'direct'}`,
      );
      const inserted = await client.query<{ id: string }>(
        `INSERT INTO invoices
           (merchant_id, order_id, asset_code, amount_due, address_id, status,
            underpayment_tolerance_bps, required_confirmations, description, metadata,
            redirect_url, expires_at, store_user_id, purpose,
            fiat_currency, fiat_amount, exchange_rate)
         VALUES ($1, $2, $3, $4, $5, 'NEW', $6, $7, $8, $9, $10,
                 now() + make_interval(secs => $11), $12, $13, $14, $15, $16)
         RETURNING id`,
        [
          merchantId,
          input.orderId ?? null,
          asset.code,
          amountDue,
          issued.addressId,
          tolerance.underpayment_tolerance_bps,
          asset.min_confirmations,
          input.description ?? null,
          JSON.stringify(input.metadata ?? {}),
          input.redirectUrl ?? null,
          ttlSeconds,
          input.storeUserId ?? null,
          input.purpose ?? 'MERCHANT',
          fiatCurrency,
          fiatAmount,
          exchangeRate,
        ],
      );
      return inserted.rows[0].id;
    });

    this.metrics.invoicesCreated.labels(asset.code).inc();
    return this.getForMerchant(merchantId, invoiceId);
  }

  async getForMerchant(merchantId: string, invoiceId: string): Promise<InvoiceView> {
    const row = await this.db.queryOne<InvoiceJoinRow>(
      `${INVOICE_SELECT} WHERE i.id = $1 AND i.merchant_id = $2`,
      [invoiceId, merchantId],
    );
    if (!row) throw new NotFoundException('invoice not found');
    return this.toView(row);
  }

  async getPublic(invoiceId: string): Promise<InvoiceView & { merchantName: string }> {
    const row = await this.db.queryOne<InvoiceJoinRow>(`${INVOICE_SELECT} WHERE i.id = $1`, [
      invoiceId,
    ]);
    if (!row) throw new NotFoundException('invoice not found');
    const merchant = await this.db.queryOne<{ name: string }>(
      `SELECT name FROM merchants WHERE id = $1`,
      [row.merchant_id],
    );
    return { ...this.toView(row), merchantName: merchant?.name ?? 'Merchant' };
  }

  async list(
    merchantId: string,
    filter: { status?: string; limit: number; offset: number },
  ): Promise<{ items: InvoiceView[]; total: number }> {
    const where = filter.status ? `AND i.status = $2` : '';
    const params: unknown[] = filter.status
      ? [merchantId, filter.status, filter.limit, filter.offset]
      : [merchantId, filter.limit, filter.offset];
    const rows = await this.db.query<InvoiceJoinRow>(
      `${INVOICE_SELECT}
        WHERE i.merchant_id = $1 ${where}
        ORDER BY i.created_at DESC
        LIMIT $${filter.status ? 3 : 2} OFFSET $${filter.status ? 4 : 3}`,
      params,
    );
    const total = await this.db.queryOne<{ n: string }>(
      `SELECT COUNT(*)::text AS n FROM invoices i WHERE i.merchant_id = $1 ${
        filter.status ? 'AND i.status = $2' : ''
      }`,
      filter.status ? [merchantId, filter.status] : [merchantId],
    );
    return { items: rows.map((r) => this.toView(r)), total: Number(total?.n ?? 0) };
  }

  async paymentsOf(merchantId: string, invoiceId: string): Promise<Array<Record<string, unknown>>> {
    await this.getForMerchant(merchantId, invoiceId); // ownership check
    return this.db.query(
      `SELECT id, txid, output_index, log_index, amount::text, from_address, status,
              block_height::text, confirmations, is_rbf, replaced_by_txid,
              detected_at, confirmed_at
         FROM payments WHERE invoice_id = $1 ORDER BY detected_at`,
      [invoiceId],
    );
  }

  /** Lightweight status row for the public SSE stream. */
  async publicStatus(invoiceId: string): Promise<{
    status: string;
    amountPaidPending: string;
    amountPaidConfirmed: string;
    expiresAt: string;
  } | null> {
    const row = await this.db.queryOne<{
      status: string;
      amount_paid_pending: string;
      amount_paid_confirmed: string;
      expires_at: string;
    }>(
      `SELECT status, amount_paid_pending::text, amount_paid_confirmed::text, expires_at
         FROM invoices WHERE id = $1`,
      [invoiceId],
    );
    if (!row) return null;
    return {
      status: row.status,
      amountPaidPending: row.amount_paid_pending,
      amountPaidConfirmed: row.amount_paid_confirmed,
      expiresAt: row.expires_at,
    };
  }

  private toView(row: InvoiceJoinRow): InvoiceView {
    const destinationTag = row.destination_tag !== null ? Number(row.destination_tag) : null;
    return {
      id: row.id,
      orderId: row.order_id,
      status: row.status,
      asset: row.asset_code,
      chain: row.chain,
      amountDue: row.amount_due,
      amountDueDecimal: baseUnitsToDecimal(row.amount_due, row.decimals),
      fiatCurrency: row.fiat_currency,
      fiatAmount: row.fiat_amount,
      exchangeRate: row.exchange_rate,
      amountPaidPending: row.amount_paid_pending,
      amountPaidConfirmed: row.amount_paid_confirmed,
      address: row.address,
      destinationTag,
      paymentUri: this.paymentUri(row, destinationTag),
      requiredConfirmations: row.required_confirmations,
      description: row.description,
      metadata: row.metadata,
      redirectUrl: row.redirect_url,
      checkoutUrl: `${this.config.publicUrl}/checkout/${row.id}`,
      expiresAt: row.expires_at,
      paidAt: row.paid_at,
      createdAt: row.created_at,
    };
  }

  private paymentUri(row: InvoiceJoinRow, destinationTag: number | null): string {
    const decimalAmount = baseUnitsToDecimal(row.amount_due, row.decimals);
    switch (row.chain) {
      case 'bitcoin':
        return `bitcoin:${row.address}?amount=${decimalAmount}`;
      case 'litecoin':
        return `litecoin:${row.address}?amount=${decimalAmount}`;
      case 'ethereum':
        return row.asset_code === 'ETH'
          ? `ethereum:${row.address}?value=${row.amount_due}`
          : row.address;
      case 'xrp':
        return `xrpl:${row.address}?dt=${destinationTag}&amount=${decimalAmount}`;
      case 'tron':
        return row.address;
    }
  }
}
