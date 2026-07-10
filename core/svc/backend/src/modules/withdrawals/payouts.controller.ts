import {
  BadRequestException,
  Body,
  Controller,
  Get,
  Ip,
  Param,
  ParseUUIDPipe,
  Post,
  UnauthorizedException,
  UseGuards,
} from '@nestjs/common';
import { Type } from 'class-transformer';
import {
  ArrayMaxSize,
  ArrayMinSize,
  IsArray,
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
import { PayoutItemInput, PayoutsService } from './payouts.service';
import { WithdrawalsService, WithdrawalRow } from './withdrawals.service';
import { AuthService } from '../auth/auth.service';
import { DatabaseService } from '../../database/database.service';
import { QueueService } from '../../redis/queue.module';
import { JwtAuthGuard } from '../../common/guards/jwt-auth.guard';
import { ApiKeyGuard, RequirePermission } from '../../common/guards/api-key.guard';
import { CurrentPrincipal } from '../../common/decorators/current-principal.decorator';
import { AssetCode, AuthPrincipal } from '../../common/types';
import { ASSET_CODES } from '../invoices/invoices.dto';
import { baseUnitsToDecimal } from '../../common/utils/base-units.util';

class PayoutItemDto implements PayoutItemInput {
  @IsIn(ASSET_CODES)
  assetCode!: AssetCode;

  @IsString()
  @Matches(/^\d+(\.\d+)?$/)
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
  reference?: string;
}

class CreatePayoutBatchDto {
  @IsArray()
  @ArrayMinSize(1)
  @ArrayMaxSize(100)
  @ValidateNested({ each: true })
  @Type(() => PayoutItemDto)
  items!: PayoutItemDto[];

  @IsOptional()
  @IsString()
  @MaxLength(140)
  label?: string;

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

class RefundDto {
  @IsString()
  @MaxLength(128)
  destinationAddress!: string;

  @IsOptional()
  @Type(() => Number)
  @IsInt()
  @Min(0)
  @Max(4294967295)
  destinationTag?: number;

  // default: full confirmed amount
  @IsOptional()
  @IsString()
  @Matches(/^\d+(\.\d+)?$/)
  @MaxLength(40)
  amount?: string;
}

@Controller('dashboard/payouts')
@UseGuards(JwtAuthGuard)
export class DashboardPayoutsController {
  constructor(
    private readonly payouts: PayoutsService,
    private readonly auth: AuthService,
  ) {}

  @Post()
  async create(
    @CurrentPrincipal() principal: AuthPrincipal,
    @Body() dto: CreatePayoutBatchDto,
    @Ip() ip: string,
  ): Promise<Record<string, unknown>> {
    if (await this.auth.isWithdrawal2fa(principal.merchantId)) {
      if (!dto.twofaCode || !(await this.auth.verifyWithdrawalCode(principal.merchantId, dto.twofaCode))) {
        throw new UnauthorizedException('Enter a valid 2FA code to send a payout.');
      }
    }
    return this.payouts.createBatch({
      merchantId: principal.merchantId,
      items: dto.items,
      label: dto.label,
      idempotencyKey: dto.idempotencyKey,
      actorType: 'MERCHANT',
      actorId: principal.merchantId,
      ip,
    }) as unknown as Record<string, unknown>;
  }

  @Get(':id')
  async get(
    @CurrentPrincipal() principal: AuthPrincipal,
    @Param('id', ParseUUIDPipe) batchId: string,
  ): Promise<Record<string, unknown>> {
    return this.payouts.getBatch(principal.merchantId, batchId) as unknown as Record<string, unknown>;
  }
}

@Controller('merchant/payouts')
@UseGuards(ApiKeyGuard)
export class MerchantPayoutsController {
  constructor(private readonly payouts: PayoutsService) {}

  @Post()
  @RequirePermission('withdrawals:write')
  async create(
    @CurrentPrincipal() principal: AuthPrincipal,
    @Body() dto: CreatePayoutBatchDto,
    @Ip() ip: string,
  ): Promise<Record<string, unknown>> {
    return this.payouts.createBatch({
      merchantId: principal.merchantId,
      items: dto.items,
      label: dto.label,
      idempotencyKey: dto.idempotencyKey,
      actorType: 'API_KEY',
      actorId: principal.apiKeyId as string,
      ip,
    }) as unknown as Record<string, unknown>;
  }

  @Get(':id')
  @RequirePermission('withdrawals:write')
  async get(
    @CurrentPrincipal() principal: AuthPrincipal,
    @Param('id', ParseUUIDPipe) batchId: string,
  ): Promise<Record<string, unknown>> {
    return this.payouts.getBatch(principal.merchantId, batchId) as unknown as Record<string, unknown>;
  }
}

/**
 * Refunds: return a paid invoice to the payer. Runs through the standard
 * withdrawal pipeline (balance lock, approval threshold, signer policy) and is
 * linked to the invoice for the audit trail.
 */
@Controller('dashboard/invoices')
@UseGuards(JwtAuthGuard)
export class RefundsController {
  constructor(
    private readonly withdrawals: WithdrawalsService,
    private readonly db: DatabaseService,
    private readonly queues: QueueService,
  ) {}

  @Post(':id/refund')
  async refund(
    @CurrentPrincipal() principal: AuthPrincipal,
    @Param('id', ParseUUIDPipe) invoiceId: string,
    @Body() dto: RefundDto,
    @Ip() ip: string,
  ): Promise<WithdrawalRow> {
    const invoice = await this.db.queryOne<{
      id: string;
      asset_code: AssetCode;
      status: string;
      amount_paid_confirmed: string;
      decimals: number;
    }>(
      `SELECT i.id, i.asset_code, i.status, i.amount_paid_confirmed::text, a.decimals
         FROM invoices i JOIN assets a ON a.code = i.asset_code
        WHERE i.id = $1 AND i.merchant_id = $2`,
      [invoiceId, principal.merchantId],
    );
    if (!invoice) throw new BadRequestException('invoice not found');
    if (!['PAID', 'OVERPAID', 'UNDERPAID'].includes(invoice.status)) {
      throw new BadRequestException(`invoice is ${invoice.status} - nothing confirmed to refund`);
    }
    if (BigInt(invoice.amount_paid_confirmed) <= 0n) {
      throw new BadRequestException('no confirmed funds on this invoice');
    }
    const existing = await this.db.queryOne(
      `SELECT 1 AS x FROM withdrawals
        WHERE refund_invoice_id = $1 AND status NOT IN ('FAILED','REJECTED','CANCELLED')`,
      [invoiceId],
    );
    if (existing) throw new BadRequestException('a refund for this invoice is already in progress');

    const amountDecimal =
      dto.amount ?? baseUnitsToDecimal(invoice.amount_paid_confirmed, invoice.decimals);
    const row = await this.withdrawals.request({
      merchantId: principal.merchantId,
      assetCode: invoice.asset_code,
      amountDecimal,
      destinationAddress: dto.destinationAddress,
      destinationTag: dto.destinationTag ?? null,
      idempotencyKey: `refund:${invoiceId}`,
      ip,
      actorType: 'MERCHANT',
      actorId: principal.merchantId,
      refundInvoiceId: invoiceId,
    });
    if (row.status === 'APPROVED') {
      await this.queues.add('withdrawals-process', 'process', { withdrawalId: row.id });
    }
    return row;
  }
}
