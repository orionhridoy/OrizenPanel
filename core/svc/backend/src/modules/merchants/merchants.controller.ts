import {
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
  UseGuards,
} from '@nestjs/common';
import { IsIn, IsInt, IsOptional, IsString, Max, MaxLength, Min } from 'class-validator';
import { PageDto } from '../../common/dto/page.dto';
import { MerchantsService, MerchantProfile } from './merchants.service';
import { LedgerService, BalanceRow } from '../ledger/ledger.service';
import { SettlementService } from '../payments/settlement.service';
import { JwtAuthGuard } from '../../common/guards/jwt-auth.guard';
import { AdminGuard } from '../../common/guards/admin.guard';
import { CurrentPrincipal } from '../../common/decorators/current-principal.decorator';
import { AuthPrincipal } from '../../common/types';

class UpdateSettingsDto {
  @IsOptional()
  @IsString()
  @MaxLength(120)
  name?: string;

  @IsOptional()
  @IsIn(['HOLD', 'AUTO_SETTLE', 'MANUAL', 'SCHEDULED'])
  settlementMode?: 'HOLD' | 'AUTO_SETTLE' | 'MANUAL' | 'SCHEDULED';

  @IsOptional()
  @IsString()
  @MaxLength(100)
  settlementScheduleCron?: string;

  @IsOptional()
  @IsInt()
  @Min(0)
  @Max(2000)
  underpaymentToleranceBps?: number;

  // Merchant default invoice expiry in minutes. 0 clears it (fall back to the asset default),
  // otherwise 5 minutes to 30 days.
  @IsOptional()
  @IsInt()
  @Min(0)
  @Max(43200)
  defaultInvoiceExpiryMinutes?: number;
}

class SetStatusDto {
  @IsIn(['ACTIVE', 'SUSPENDED'])
  status!: 'ACTIVE' | 'SUSPENDED';
}

@Controller('dashboard/merchant')
@UseGuards(JwtAuthGuard)
export class MerchantsController {
  constructor(
    private readonly merchants: MerchantsService,
    private readonly ledger: LedgerService,
    private readonly settlement: SettlementService,
  ) {}

  @Get('me')
  async me(@CurrentPrincipal() principal: AuthPrincipal): Promise<MerchantProfile> {
    return this.merchants.profile(principal.merchantId);
  }

  @Patch('me')
  async update(
    @CurrentPrincipal() principal: AuthPrincipal,
    @Body() dto: UpdateSettingsDto,
    @Ip() ip: string,
  ): Promise<MerchantProfile> {
    return this.merchants.updateSettings(principal.merchantId, dto, ip);
  }

  @Get('balances')
  async balances(@CurrentPrincipal() principal: AuthPrincipal): Promise<BalanceRow[]> {
    return this.ledger.merchantBalances(principal.merchantId);
  }

  /** HOLD/MANUAL settlement: release all pending funds now. */
  @Post('settle')
  @HttpCode(200)
  async settle(
    @CurrentPrincipal() principal: AuthPrincipal,
  ): Promise<Array<{ assetCode: string; amount: string }>> {
    return this.settlement.settleMerchant(principal.merchantId, 'MERCHANT', principal.merchantId);
  }
}

@Controller('admin/merchants')
@UseGuards(JwtAuthGuard, AdminGuard)
export class AdminMerchantsController {
  constructor(private readonly merchants: MerchantsService) {}

  @Get()
  async list(@Query() query: PageDto): Promise<MerchantProfile[]> {
    return this.merchants.adminList(query.limit, query.offset);
  }

  @Patch(':id/status')
  @HttpCode(204)
  async setStatus(
    @CurrentPrincipal() principal: AuthPrincipal,
    @Param('id', ParseUUIDPipe) merchantId: string,
    @Body() dto: SetStatusDto,
    @Ip() ip: string,
  ): Promise<void> {
    await this.merchants.adminSetStatus(principal.merchantId, merchantId, dto.status, ip);
  }
}
