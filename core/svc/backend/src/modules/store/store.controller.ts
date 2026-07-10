import {
  BadRequestException,
  Body,
  Controller,
  Get,
  Ip,
  Param,
  Patch,
  Post,
  Query,
  Req,
  UseGuards,
} from '@nestjs/common';
import { PageDto } from '../../common/dto/page.dto';
import {
  IsBoolean,
  IsEmail,
  IsIn,
  IsOptional,
  IsString,
  Matches,
  MaxLength,
  MinLength,
} from 'class-validator';
import { StoreBalance, StoreService } from './store.service';
import { InvoiceView } from '../invoices/invoices.service';
import { DatabaseService } from '../../database/database.service';
import { ApiKeyGuard, RequirePermission } from '../../common/guards/api-key.guard';
import { JwtAuthGuard } from '../../common/guards/jwt-auth.guard';
import { CurrentPrincipal } from '../../common/decorators/current-principal.decorator';
import { AssetCode, AuthenticatedRequest, AuthPrincipal } from '../../common/types';
import { ASSET_CODES } from '../invoices/invoices.dto';

class SessionDto {
  @IsString()
  @MinLength(1)
  @MaxLength(200)
  externalRef!: string;

  @IsOptional()
  @IsString()
  @MaxLength(120)
  displayName?: string;

  @IsOptional()
  @IsEmail()
  @MaxLength(254)
  email?: string;
}

class TopupDto {
  @IsIn(ASSET_CODES)
  assetCode!: AssetCode;

  // crypto amount OR fiatAmount + fiatCurrency
  @IsOptional()
  @IsString()
  @Matches(/^\d+(\.\d+)?$/, { message: 'amount must be a positive decimal string' })
  @MaxLength(40)
  amount?: string;

  @IsOptional()
  @IsString()
  @Matches(/^\d+(\.\d{1,2})?$/)
  @MaxLength(20)
  fiatAmount?: string;

  @IsOptional()
  @IsIn(['USD', 'EUR', 'GBP'])
  fiatCurrency?: string;
}

class PurchaseDto {
  @IsString()
  @MinLength(1)
  @MaxLength(200)
  externalRef!: string;

  @IsIn(ASSET_CODES)
  assetCode!: AssetCode;

  @IsString()
  @Matches(/^\d+(\.\d+)?$/, { message: 'amount must be a positive decimal string' })
  @MaxLength(40)
  amount!: string;

  @IsOptional()
  @IsString()
  @MaxLength(200)
  description?: string;

  @IsOptional()
  @IsString()
  @MaxLength(120)
  idempotencyKey?: string;
}

class StoreConfigDto {
  @IsOptional()
  @IsBoolean()
  storeEnabled?: boolean;

  @IsOptional()
  @IsString()
  @MaxLength(120)
  storeName?: string;
}

function bearer(request: AuthenticatedRequest): string {
  const header = request.headers.authorization;
  if (!header?.startsWith('Bearer ')) throw new BadRequestException('missing store session token');
  return header.slice('Bearer '.length);
}

/** Server-to-server integration for a merchant's store (API key + HMAC). */
@Controller('merchant/store')
@UseGuards(ApiKeyGuard)
export class MerchantStoreController {
  constructor(private readonly store: StoreService) {}

  /** Mint a hosted-page session for a customer (open store.url in their browser). */
  @Post('sessions')
  @RequirePermission('store:manage')
  async createSession(
    @CurrentPrincipal() principal: AuthPrincipal,
    @Body() dto: SessionDto,
  ): Promise<{ token: string; url: string; expiresIn: number; storeUserId: string }> {
    return this.store.createSession(principal.merchantId, dto.externalRef, dto.displayName, dto.email);
  }

  /** Spend a customer's balance to buy a tool (customer balance -> merchant balance). */
  @Post('purchase')
  @RequirePermission('store:manage')
  async purchase(
    @CurrentPrincipal() principal: AuthPrincipal,
    @Body() dto: PurchaseDto,
    @Ip() ip: string,
  ): Promise<{ purchaseId: string; assetCode: string; amount: string; remainingBalanceDecimal: string }> {
    return this.store.purchase({
      merchantId: principal.merchantId,
      externalRef: dto.externalRef,
      assetCode: dto.assetCode,
      amountDecimal: dto.amount,
      description: dto.description,
      idempotencyKey: dto.idempotencyKey,
      actorId: principal.apiKeyId as string,
      ip,
    });
  }

  @Get('users/:externalRef/balances')
  @RequirePermission('store:manage')
  async balances(
    @CurrentPrincipal() principal: AuthPrincipal,
    @Param('externalRef') externalRef: string,
  ): Promise<StoreBalance[]> {
    return this.store.balancesByRef(principal.merchantId, externalRef);
  }
}

/** Hosted customer page - authenticated by the signed store session token. */
@Controller('public/store')
export class PublicStoreController {
  constructor(private readonly store: StoreService) {}

  @Get('session')
  async session(@Req() request: AuthenticatedRequest): Promise<Record<string, unknown>> {
    return this.store.sessionView(this.store.verifySession(bearer(request)));
  }

  @Post('session/topup')
  async topup(@Req() request: AuthenticatedRequest, @Body() dto: TopupDto): Promise<InvoiceView> {
    const session = this.store.verifySession(bearer(request));
    return this.store.createTopup(session, dto.assetCode, {
      amountDecimal: dto.amount,
      fiatAmount: dto.fiatAmount,
      fiatCurrency: dto.fiatCurrency,
    });
  }

  @Get('session/balances')
  async balances(@Req() request: AuthenticatedRequest): Promise<StoreBalance[]> {
    const session = this.store.verifySession(bearer(request));
    return this.store.balances(session.storeUserId);
  }
}

/** Merchant dashboard: enable the store, name it, mint test sessions, view users. */
@Controller('dashboard/store')
@UseGuards(JwtAuthGuard)
export class DashboardStoreController {
  constructor(
    private readonly store: StoreService,
    private readonly db: DatabaseService,
  ) {}

  @Get('config')
  async getConfig(
    @CurrentPrincipal() principal: AuthPrincipal,
  ): Promise<{ storeEnabled: boolean; storeName: string | null; storeId: string }> {
    const row = await this.db.queryOne<{ store_enabled: boolean; store_name: string | null }>(
      `SELECT store_enabled, store_name FROM merchants WHERE id = $1`,
      [principal.merchantId],
    );
    return {
      storeEnabled: row?.store_enabled ?? false,
      storeName: row?.store_name ?? null,
      storeId: principal.merchantId,
    };
  }

  @Patch('config')
  async setConfig(
    @CurrentPrincipal() principal: AuthPrincipal,
    @Body() dto: StoreConfigDto,
  ): Promise<{ storeEnabled: boolean; storeName: string | null }> {
    const row = await this.db.queryOne<{ store_enabled: boolean; store_name: string | null }>(
      `UPDATE merchants SET
          store_enabled = COALESCE($2, store_enabled),
          store_name = COALESCE($3, store_name)
        WHERE id = $1
        RETURNING store_enabled, store_name`,
      [principal.merchantId, dto.storeEnabled ?? null, dto.storeName ?? null],
    );
    return { storeEnabled: row?.store_enabled ?? false, storeName: row?.store_name ?? null };
  }

  /** Generate a hosted-page link for a test customer, straight from the dashboard. */
  @Post('test-session')
  async testSession(
    @CurrentPrincipal() principal: AuthPrincipal,
    @Body() dto: SessionDto,
  ): Promise<{ token: string; url: string; expiresIn: number; storeUserId: string }> {
    return this.store.createSession(principal.merchantId, dto.externalRef, dto.displayName, dto.email);
  }

  @Get('users')
  async users(
    @CurrentPrincipal() principal: AuthPrincipal,
    @Query() query: PageDto,
  ): Promise<Array<Record<string, unknown>>> {
    return this.db.query(
      `SELECT su.id, su.external_ref, su.display_name, su.email, su.created_at,
              COALESCE(json_agg(json_build_object('asset', a.asset_code, 'balance', b.balance::text))
                       FILTER (WHERE a.id IS NOT NULL), '[]') AS balances
         FROM store_users su
         LEFT JOIN ledger_accounts a
                ON a.store_user_id = su.id AND a.type = 'STORE_USER_AVAILABLE'
         LEFT JOIN account_balances b ON b.account_id = a.id
        WHERE su.merchant_id = $1
        GROUP BY su.id
        ORDER BY su.created_at DESC
        LIMIT $2 OFFSET $3`,
      [principal.merchantId, query.limit, query.offset],
    );
  }
}
