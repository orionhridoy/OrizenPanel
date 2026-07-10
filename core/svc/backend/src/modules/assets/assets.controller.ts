import { Body, Controller, Get, Ip, Param, Patch, UseGuards } from '@nestjs/common';
import { IsBoolean } from 'class-validator';
import { JwtAuthGuard } from '../../common/guards/jwt-auth.guard';
import { AdminGuard } from '../../common/guards/admin.guard';
import { CurrentPrincipal } from '../../common/decorators/current-principal.decorator';
import { AuthPrincipal } from '../../common/types';
import { AuditService } from '../audit/audit.service';
import { AssetsService, AssetRow } from './assets.service';

class ToggleAssetDto {
  @IsBoolean()
  enabled!: boolean;
}

/**
 * Enable/disable cryptocurrencies. System-wide setting -> ADMIN only.
 * The public list of enabled assets is served elsewhere (checkout / rates).
 */
@Controller('admin/assets')
@UseGuards(JwtAuthGuard, AdminGuard)
export class AssetsController {
  constructor(
    private readonly assets: AssetsService,
    private readonly audit: AuditService,
  ) {}

  @Get()
  async list(): Promise<AssetRow[]> {
    return this.assets.listAll();
  }

  @Patch(':code')
  async toggle(
    @CurrentPrincipal() principal: AuthPrincipal,
    @Param('code') code: string,
    @Body() dto: ToggleAssetDto,
    @Ip() ip: string,
  ): Promise<AssetRow> {
    const row = await this.assets.toggle(code, dto.enabled);
    await this.audit.log({
      actorType: 'ADMIN',
      actorId: principal.merchantId,
      action: dto.enabled ? 'asset.enabled' : 'asset.disabled',
      resourceType: 'asset',
      resourceId: code,
      ip,
    });
    return row;
  }
}
