import {
  BadRequestException,
  Inject,
  Injectable,
  NotFoundException,
} from '@nestjs/common';
import { APP_CONFIG, AppConfig } from '../../config/config';
import { DatabaseService } from '../../database/database.service';
import { InvoicesService, InvoiceView } from '../invoices/invoices.service';
import { AuditService } from '../audit/audit.service';
import { AssetCode } from '../../common/types';
import { randomHex } from '../../common/utils/crypto.util';

export interface PaymentLinkRow {
  id: string;
  merchant_id: string;
  slug: string;
  title: string;
  description: string | null;
  fiat_currency: string | null;
  fiat_amount: string | null;
  asset_code: AssetCode | null;
  crypto_amount: string | null;
  allow_custom_amount: boolean;
  redirect_url: string | null;
  is_active: boolean;
  times_used: number;
  created_at: string;
}

export interface CreateLinkInput {
  title: string;
  description?: string;
  fiatCurrency?: string;
  fiatAmount?: string;
  assetCode?: AssetCode;
  cryptoAmount?: string;
  allowCustomAmount?: boolean;
  redirectUrl?: string;
}

const LINK_SELECT = `SELECT id, merchant_id, slug, title, description, fiat_currency,
  fiat_amount::text, asset_code, crypto_amount, allow_custom_amount, redirect_url,
  is_active, times_used, created_at FROM payment_links`;

/**
 * Payment links: reusable, no-code checkout URLs (/pay/<slug>).
 * Pricing modes:
 *   fixed fiat   - fiat_amount + fiat_currency; payer picks the coin
 *   fixed crypto - asset_code + crypto_amount
 *   open amount  - allow_custom_amount; payer enters the amount (fiat or crypto)
 * Every visit that proceeds to payment creates a fresh single-use invoice, so
 * the never-reuse-addresses rule holds even for reusable links.
 */
@Injectable()
export class LinksService {
  constructor(
    private readonly db: DatabaseService,
    private readonly invoices: InvoicesService,
    private readonly audit: AuditService,
    @Inject(APP_CONFIG) private readonly config: AppConfig,
  ) {}

  async create(merchantId: string, input: CreateLinkInput, ip?: string): Promise<PaymentLinkRow & { url: string }> {
    const fixedFiat = Boolean(input.fiatAmount && input.fiatCurrency);
    const fixedCrypto = Boolean(input.assetCode && input.cryptoAmount);
    const open = Boolean(input.allowCustomAmount);
    if (!fixedFiat && !fixedCrypto && !open) {
      throw new BadRequestException(
        'choose a pricing mode: fiatAmount+fiatCurrency, assetCode+cryptoAmount, or allowCustomAmount',
      );
    }
    if (fixedFiat && fixedCrypto) {
      throw new BadRequestException('use either a fiat price or a crypto price, not both');
    }
    const slug = randomHex(5); // 10 hex chars - unguessable enough for a public link
    const row = await this.db.queryOne<PaymentLinkRow>(
      `INSERT INTO payment_links
         (merchant_id, slug, title, description, fiat_currency, fiat_amount,
          asset_code, crypto_amount, allow_custom_amount, redirect_url)
       VALUES ($1,$2,$3,$4,$5,$6,$7,$8,$9,$10)
       RETURNING id, merchant_id, slug, title, description, fiat_currency,
                 fiat_amount::text, asset_code, crypto_amount, allow_custom_amount,
                 redirect_url, is_active, times_used, created_at`,
      [
        merchantId,
        slug,
        input.title,
        input.description ?? null,
        fixedFiat || (open && input.fiatCurrency) ? (input.fiatCurrency as string).toUpperCase() : null,
        fixedFiat ? input.fiatAmount : null,
        input.assetCode ?? null,
        fixedCrypto ? input.cryptoAmount : null,
        open,
        input.redirectUrl ?? null,
      ],
    );
    await this.audit.log({
      actorType: 'MERCHANT',
      actorId: merchantId,
      action: 'payment_link.created',
      resourceType: 'payment_link',
      resourceId: row?.id,
      ip,
      metadata: { slug, title: input.title },
    });
    return { ...(row as PaymentLinkRow), url: this.url(slug) };
  }

  url(slug: string): string {
    return `${this.config.publicUrl}/pay/${slug}`;
  }

  async list(
    merchantId: string,
    limit = 25,
    offset = 0,
  ): Promise<{ items: Array<PaymentLinkRow & { url: string }>; total: number }> {
    const rows = await this.db.query<PaymentLinkRow>(
      `${LINK_SELECT} WHERE merchant_id = $1 ORDER BY created_at DESC LIMIT $2 OFFSET $3`,
      [merchantId, limit, offset],
    );
    const count = await this.db.queryOne<{ n: string }>(
      `SELECT count(*)::text AS n FROM payment_links WHERE merchant_id = $1`,
      [merchantId],
    );
    return { items: rows.map((r) => ({ ...r, url: this.url(r.slug) })), total: Number(count?.n ?? 0) };
  }

  async setActive(merchantId: string, linkId: string, active: boolean): Promise<void> {
    const updated = await this.db.query(
      `UPDATE payment_links SET is_active = $3 WHERE id = $1 AND merchant_id = $2 RETURNING id`,
      [linkId, merchantId, active],
    );
    if (updated.length === 0) throw new NotFoundException('payment link not found');
  }

  /** Public: resolve a link for the /pay page (no merchant internals leaked). */
  async resolvePublic(slug: string): Promise<{
    slug: string;
    title: string;
    description: string | null;
    merchantName: string;
    fiatCurrency: string | null;
    fiatAmount: string | null;
    assetCode: AssetCode | null;
    cryptoAmount: string | null;
    allowCustomAmount: boolean;
    assets: Array<{ code: string; displayName: string }>;
  }> {
    const link = await this.db.queryOne<PaymentLinkRow & { merchant_name: string; merchant_status: string }>(
      `SELECT pl.*, pl.fiat_amount::text AS fiat_amount, m.name AS merchant_name, m.status AS merchant_status
         FROM payment_links pl JOIN merchants m ON m.id = pl.merchant_id
        WHERE pl.slug = $1`,
      [slug],
    );
    if (!link || !link.is_active || link.merchant_status !== 'ACTIVE') {
      throw new NotFoundException('payment link not found');
    }
    const assets = await this.db.query<{ code: string; display_name: string }>(
      `SELECT code, display_name FROM assets WHERE enabled ORDER BY code`,
    );
    return {
      slug: link.slug,
      title: link.title,
      description: link.description,
      merchantName: link.merchant_name,
      fiatCurrency: link.fiat_currency,
      fiatAmount: link.fiat_amount,
      assetCode: link.asset_code,
      cryptoAmount: link.crypto_amount,
      allowCustomAmount: link.allow_custom_amount,
      assets: assets.map((a) => ({ code: a.code, displayName: a.display_name })),
    };
  }

  /** Public: payer proceeds - create a fresh invoice from the link. */
  async createInvoiceFromLink(
    slug: string,
    input: { assetCode?: AssetCode; amount?: string; fiatAmount?: string; fiatCurrency?: string },
  ): Promise<InvoiceView> {
    const link = await this.db.queryOne<PaymentLinkRow>(`${LINK_SELECT} WHERE slug = $1`, [slug]);
    if (!link || !link.is_active) throw new NotFoundException('payment link not found');

    const assetCode = (link.asset_code ?? input.assetCode) as AssetCode | undefined;
    if (!assetCode) throw new BadRequestException('choose an asset to pay with');

    let amountDecimal: string | undefined;
    let fiatAmount: string | undefined;
    let fiatCurrency: string | undefined;

    if (link.crypto_amount) {
      amountDecimal = link.crypto_amount; // fixed crypto price
    } else if (link.fiat_amount && link.fiat_currency) {
      fiatAmount = link.fiat_amount; // fixed fiat price, payer chose the coin
      fiatCurrency = link.fiat_currency;
    } else if (link.allow_custom_amount) {
      // payer-entered amount, in the link's fiat currency if set, else crypto
      if (input.fiatAmount && (input.fiatCurrency || link.fiat_currency)) {
        fiatAmount = input.fiatAmount;
        fiatCurrency = (input.fiatCurrency ?? link.fiat_currency) as string;
      } else if (input.amount) {
        amountDecimal = input.amount;
      } else {
        throw new BadRequestException('enter an amount');
      }
    } else {
      throw new BadRequestException('link has no payable price');
    }

    const invoice = await this.invoices.create(link.merchant_id, {
      assetCode,
      amountDecimal,
      fiatAmount,
      fiatCurrency,
      description: link.title,
      redirectUrl: link.redirect_url ?? undefined,
      metadata: { paymentLink: link.slug },
    });
    await this.db.query(
      `UPDATE invoices SET payment_link_id = $2 WHERE id = $1`,
      [invoice.id, link.id],
    );
    await this.db.query(
      `UPDATE payment_links SET times_used = times_used + 1 WHERE id = $1`,
      [link.id],
    );
    return invoice;
  }
}
