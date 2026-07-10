import {
  BadRequestException,
  Body,
  Controller,
  Get,
  HttpCode,
  Ip,
  Param,
  ParseUUIDPipe,
  Patch,
  Post,
  Query,
  UnauthorizedException,
  UseGuards,
} from '@nestjs/common';
import {
  ArrayMaxSize,
  IsArray,
  IsBoolean,
  IsIn,
  IsInt,
  IsOptional,
  IsString,
  Length,
  Matches,
  Max,
  MaxLength,
  Min,
  ValidateNested,
} from 'class-validator';
import { Type } from 'class-transformer';
import { WithdrawalRow, WithdrawalsService } from './withdrawals.service';
import { QueueService } from '../../redis/queue.module';
import { DatabaseService } from '../../database/database.service';
import { AuthService } from '../auth/auth.service';
import { AuditService } from '../audit/audit.service';
import { AdapterRegistry } from '../blockchain/adapter.registry';
import { JwtAuthGuard } from '../../common/guards/jwt-auth.guard';
import { ApiKeyGuard, RequirePermission } from '../../common/guards/api-key.guard';
import { CurrentPrincipal } from '../../common/decorators/current-principal.decorator';
import { AssetCode, AuthPrincipal, Chain } from '../../common/types';
import { ASSET_CODES } from '../invoices/invoices.dto';
import { baseUnitsToDecimal, decimalToBaseUnits } from '../../common/utils/base-units.util';

class AutoPayoutTargetDto {
  @IsIn(ASSET_CODES)
  asset!: AssetCode;

  @IsString()
  @MaxLength(128)
  address!: string;

  /** Human-decimal minimum balance that triggers an automatic payout. */
  @IsString()
  @Matches(/^\d+(\.\d+)?$/)
  @MaxLength(40)
  minAmount!: string;
}

class SetAutoPayoutDto {
  @IsBoolean()
  enabled!: boolean;

  @IsArray()
  @ArrayMaxSize(20)
  @ValidateNested({ each: true })
  @Type(() => AutoPayoutTargetDto)
  targets!: AutoPayoutTargetDto[];

  /** Required only when "require 2FA to withdraw" is on - changing the payout address is
   *  a money-moving change, so it is protected by the same factor. */
  @IsOptional()
  @IsString()
  @Length(6, 6)
  @Matches(/^\d{6}$/)
  twofaCode?: string;
}

export class RequestWithdrawalDto {
  @IsIn(ASSET_CODES)
  assetCode!: AssetCode;

  @IsString()
  @Matches(/^\d+(\.\d+)?$/, { message: 'amount must be a positive decimal string' })
  @MaxLength(40)
  amount!: string;

  @IsString()
  @MaxLength(128)
  destinationAddress!: string;

  @IsOptional()
  @Type(() => Number)
  @IsInt()
  @Min(0)
  @Max(4294967295)
  destinationTag?: number;

  @IsOptional()
  @IsString()
  @MaxLength(120)
  idempotencyKey?: string;

  /** Required only when the merchant has switched on "require 2FA to withdraw". */
  @IsOptional()
  @IsString()
  @Length(6, 6)
  @Matches(/^\d{6}$/)
  twofaCode?: string;
}

class ListWithdrawalsDto {
  @IsOptional()
  @Type(() => Number)
  @IsInt()
  @Min(1)
  @Max(100)
  limit = 25;

  @IsOptional()
  @Type(() => Number)
  @IsInt()
  @Min(0)
  offset = 0;
}

const WITHDRAWAL_LIST_SELECT = `
  SELECT id, asset_code, amount::text, network_fee::text, destination_address,
         destination_tag::text, status, requires_admin_approval, txid, error,
         created_at, broadcast_at, confirmed_at
    FROM withdrawals`;

@Controller('dashboard/withdrawals')
@UseGuards(JwtAuthGuard)
export class WithdrawalsController {
  constructor(
    private readonly withdrawals: WithdrawalsService,
    private readonly queues: QueueService,
    private readonly db: DatabaseService,
    private readonly auth: AuthService,
    private readonly adapters: AdapterRegistry,
    private readonly audit: AuditService,
  ) {}

  /** When "require 2FA to withdraw" is on and the merchant uses Telegram, send them a code. */
  @Post('2fa/send')
  @HttpCode(200)
  async sendWithdrawalCode(@CurrentPrincipal() principal: AuthPrincipal): Promise<{ ok: true }> {
    await this.auth.sendWithdrawalTelegramCode(principal.merchantId);
    return { ok: true };
  }

  /** "Withdraw everything" helper: max sendable = available balance minus the est. network fee. */
  @Get('max')
  async max(
    @CurrentPrincipal() principal: AuthPrincipal,
    @Query('asset') asset: string,
  ): Promise<{ asset: string; available: string; feeEstimate: string; max: string }> {
    if (!ASSET_CODES.includes(asset as AssetCode)) throw new BadRequestException('unknown asset');
    const code = asset as AssetCode;
    const available = await this.withdrawals.availableBalance(principal.merchantId, code);
    const fee = (await this.withdrawals.estimateWithdrawalFeeBaseUnits(code)).toString();
    const max = await this.withdrawals.maxWithdrawable(principal.merchantId, code);
    return { asset: code, available, feeEstimate: fee, max };
  }

  /** Current auto-payout configuration for the Settings screen. */
  @Get('auto-payout')
  async getAutoPayout(
    @CurrentPrincipal() principal: AuthPrincipal,
  ): Promise<{ enabled: boolean; targets: Array<{ asset: string; address: string; minAmount: string }> }> {
    const row = await this.db.queryOne<{ auto_payout_enabled: boolean; auto_payout_targets: Record<string, { address?: string; minBaseUnits?: string }> }>(
      `SELECT auto_payout_enabled, auto_payout_targets FROM merchants WHERE id = $1`,
      [principal.merchantId],
    );
    const targets: Array<{ asset: string; address: string; minAmount: string }> = [];
    const raw = row?.auto_payout_targets ?? {};
    for (const [asset, t] of Object.entries(raw)) {
      if (!ASSET_CODES.includes(asset as AssetCode)) continue;
      const decimals = await this.assetDecimals(asset as AssetCode);
      targets.push({
        asset,
        address: t.address ?? '',
        minAmount: t.minBaseUnits ? baseUnitsToDecimal(t.minBaseUnits, decimals) : '0',
      });
    }
    return { enabled: !!row?.auto_payout_enabled, targets };
  }

  /** Save auto-payout: your wallet + threshold per asset; the gateway sends automatically. */
  @Patch('auto-payout')
  async setAutoPayout(
    @CurrentPrincipal() principal: AuthPrincipal,
    @Body() dto: SetAutoPayoutDto,
  ): Promise<{ enabled: boolean }> {
    // Redirecting your payouts is a money-moving change: when withdrawal 2FA is on,
    // require a fresh code here too, so a hijacked session can't silently repoint funds.
    if (await this.auth.isWithdrawal2fa(principal.merchantId)) {
      if (!dto.twofaCode || !(await this.auth.verifyWithdrawalCode(principal.merchantId, dto.twofaCode))) {
        throw new UnauthorizedException('Enter a valid 2FA code to change auto-payout.');
      }
    }
    const targets: Record<string, { address: string; minBaseUnits: string }> = {};
    for (const t of dto.targets) {
      const address = t.address.trim();
      if (address === '') continue;
      const chain = await this.assetChain(t.asset);
      if (!this.adapters.forChain(chain).validateAddress(address)) {
        throw new BadRequestException(`invalid ${t.asset} address`);
      }
      const own = await this.db.queryOne(`SELECT 1 AS x FROM wallet_addresses WHERE address = $1`, [address]);
      if (own) throw new BadRequestException(`${t.asset} address is a gateway-owned address`);
      const decimals = await this.assetDecimals(t.asset);
      let minBaseUnits: string;
      try {
        minBaseUnits = decimalToBaseUnits(t.minAmount, decimals);
      } catch {
        throw new BadRequestException(`invalid ${t.asset} threshold`);
      }
      targets[t.asset] = { address, minBaseUnits };
    }
    if (dto.enabled && Object.keys(targets).length === 0) {
      throw new BadRequestException('Add at least one wallet address to enable auto-payout.');
    }
    await this.db.query(
      `UPDATE merchants SET auto_payout_enabled = $2, auto_payout_targets = $3::jsonb WHERE id = $1`,
      [principal.merchantId, dto.enabled, JSON.stringify(targets)],
    );
    await this.audit.log({
      actorType: 'MERCHANT',
      actorId: principal.merchantId,
      action: 'auto_payout.configured',
      resourceType: 'merchant',
      resourceId: principal.merchantId,
      metadata: { enabled: dto.enabled, assets: Object.keys(targets), addresses: Object.values(targets).map((t) => t.address) },
    });
    return { enabled: dto.enabled };
  }

  private async assetDecimals(asset: AssetCode): Promise<number> {
    const row = await this.db.queryOne<{ decimals: number }>(`SELECT decimals FROM assets WHERE code = $1`, [asset]);
    return row?.decimals ?? 0;
  }

  private async assetChain(asset: AssetCode): Promise<Chain> {
    const row = await this.db.queryOne<{ chain: Chain }>(`SELECT chain FROM assets WHERE code = $1`, [asset]);
    if (!row) throw new BadRequestException('unknown asset');
    return row.chain;
  }

  @Post()
  async request(
    @CurrentPrincipal() principal: AuthPrincipal,
    @Body() dto: RequestWithdrawalDto,
    @Ip() ip: string,
  ): Promise<WithdrawalRow> {
    if (await this.auth.isWithdrawal2fa(principal.merchantId)) {
      if (!dto.twofaCode || !(await this.auth.verifyWithdrawalCode(principal.merchantId, dto.twofaCode))) {
        throw new UnauthorizedException('Enter a valid 2FA code to withdraw.');
      }
    }
    const row = await this.withdrawals.request({
      merchantId: principal.merchantId,
      assetCode: dto.assetCode,
      amountDecimal: dto.amount,
      destinationAddress: dto.destinationAddress,
      destinationTag: dto.destinationTag ?? null,
      idempotencyKey: dto.idempotencyKey ?? null,
      ip,
      actorType: 'MERCHANT',
      actorId: principal.merchantId,
    });
    if (row.status === 'APPROVED') {
      await this.queues.add('withdrawals-process', 'process', { withdrawalId: row.id });
    }
    return row;
  }

  @Get()
  async list(
    @CurrentPrincipal() principal: AuthPrincipal,
    @Query() query: ListWithdrawalsDto,
  ): Promise<Array<Record<string, unknown>>> {
    return this.db.query(
      `${WITHDRAWAL_LIST_SELECT}
        WHERE merchant_id = $1 ORDER BY created_at DESC LIMIT $2 OFFSET $3`,
      [principal.merchantId, query.limit, query.offset],
    );
  }
}

@Controller('merchant/withdrawals')
@UseGuards(ApiKeyGuard)
export class MerchantWithdrawalsController {
  constructor(
    private readonly withdrawals: WithdrawalsService,
    private readonly queues: QueueService,
    private readonly db: DatabaseService,
  ) {}

  @Post()
  @RequirePermission('withdrawals:write')
  async request(
    @CurrentPrincipal() principal: AuthPrincipal,
    @Body() dto: RequestWithdrawalDto,
    @Ip() ip: string,
  ): Promise<WithdrawalRow> {
    const row = await this.withdrawals.request({
      merchantId: principal.merchantId,
      assetCode: dto.assetCode,
      amountDecimal: dto.amount,
      destinationAddress: dto.destinationAddress,
      destinationTag: dto.destinationTag ?? null,
      idempotencyKey: dto.idempotencyKey ?? null,
      ip,
      actorType: 'API_KEY',
      actorId: principal.apiKeyId as string,
    });
    if (row.status === 'APPROVED') {
      await this.queues.add('withdrawals-process', 'process', { withdrawalId: row.id });
    }
    return row;
  }

  @Get(':id')
  @RequirePermission('withdrawals:write')
  async get(
    @CurrentPrincipal() principal: AuthPrincipal,
    @Param('id', ParseUUIDPipe) withdrawalId: string,
  ): Promise<Record<string, unknown> | null> {
    return this.db.queryOne(
      `${WITHDRAWAL_LIST_SELECT} WHERE id = $1 AND merchant_id = $2`,
      [withdrawalId, principal.merchantId],
    );
  }
}
