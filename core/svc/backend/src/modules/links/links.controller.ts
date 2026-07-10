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
import { PageDto } from '../../common/dto/page.dto';
import {
  IsBoolean,
  IsIn,
  IsNotEmpty,
  IsOptional,
  IsString,
  Matches,
  MaxLength,
} from 'class-validator';
import { CreateLinkInput, LinksService, PaymentLinkRow } from './links.service';
import { InvoiceView } from '../invoices/invoices.service';
import { JwtAuthGuard } from '../../common/guards/jwt-auth.guard';
import { ApiKeyGuard, RequirePermission } from '../../common/guards/api-key.guard';
import { CurrentPrincipal } from '../../common/decorators/current-principal.decorator';
import { AssetCode, AuthPrincipal } from '../../common/types';
import { ASSET_CODES } from '../invoices/invoices.dto';

class CreateLinkDto implements CreateLinkInput {
  @IsString()
  @IsNotEmpty()
  @MaxLength(140)
  title!: string;

  @IsOptional()
  @IsString()
  @MaxLength(500)
  description?: string;

  @IsOptional()
  @IsIn(['USD', 'EUR', 'GBP'])
  fiatCurrency?: string;

  @IsOptional()
  @IsString()
  @Matches(/^\d+(\.\d{1,2})?$/)
  @MaxLength(20)
  fiatAmount?: string;

  @IsOptional()
  @IsIn(ASSET_CODES)
  assetCode?: AssetCode;

  @IsOptional()
  @IsString()
  @Matches(/^\d+(\.\d+)?$/)
  @MaxLength(40)
  cryptoAmount?: string;

  @IsOptional()
  @IsBoolean()
  allowCustomAmount?: boolean;

  @IsOptional()
  @IsString()
  @Matches(/^https:\/\//)
  @MaxLength(500)
  redirectUrl?: string;
}

class SetActiveDto {
  @IsBoolean()
  isActive!: boolean;
}

class PayLinkDto {
  @IsOptional()
  @IsIn(ASSET_CODES)
  assetCode?: AssetCode;

  @IsOptional()
  @IsString()
  @Matches(/^\d+(\.\d+)?$/)
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

@Controller('dashboard/links')
@UseGuards(JwtAuthGuard)
export class DashboardLinksController {
  constructor(private readonly links: LinksService) {}

  @Post()
  async create(
    @CurrentPrincipal() principal: AuthPrincipal,
    @Body() dto: CreateLinkDto,
    @Ip() ip: string,
  ): Promise<PaymentLinkRow & { url: string }> {
    return this.links.create(principal.merchantId, dto, ip);
  }

  @Get()
  async list(
    @CurrentPrincipal() principal: AuthPrincipal,
    @Query() query: PageDto,
  ): Promise<{ items: Array<PaymentLinkRow & { url: string }>; total: number }> {
    return this.links.list(principal.merchantId, query.limit, query.offset);
  }

  @Patch(':id')
  @HttpCode(204)
  async setActive(
    @CurrentPrincipal() principal: AuthPrincipal,
    @Param('id', ParseUUIDPipe) linkId: string,
    @Body() dto: SetActiveDto,
  ): Promise<void> {
    await this.links.setActive(principal.merchantId, linkId, dto.isActive);
  }
}

/** Same capability for server-to-server integrations. */
@Controller('merchant/links')
@UseGuards(ApiKeyGuard)
export class MerchantLinksController {
  constructor(private readonly links: LinksService) {}

  @Post()
  @RequirePermission('invoices:write')
  async create(
    @CurrentPrincipal() principal: AuthPrincipal,
    @Body() dto: CreateLinkDto,
    @Ip() ip: string,
  ): Promise<PaymentLinkRow & { url: string }> {
    return this.links.create(principal.merchantId, dto, ip);
  }

  @Get()
  @RequirePermission('invoices:read')
  async list(
    @CurrentPrincipal() principal: AuthPrincipal,
    @Query() query: PageDto,
  ): Promise<{ items: Array<PaymentLinkRow & { url: string }>; total: number }> {
    return this.links.list(principal.merchantId, query.limit, query.offset);
  }
}

/** Public payer-facing endpoints (rate-limited at nginx via /public/). */
@Controller('public/links')
export class PublicLinksController {
  constructor(private readonly links: LinksService) {}

  @Get(':slug')
  async resolve(@Param('slug') slug: string): Promise<Record<string, unknown>> {
    return this.links.resolvePublic(slug);
  }

  @Post(':slug/invoice')
  async pay(@Param('slug') slug: string, @Body() dto: PayLinkDto): Promise<InvoiceView> {
    return this.links.createInvoiceFromLink(slug, dto);
  }
}
