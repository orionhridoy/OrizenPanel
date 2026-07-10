import {
  ArrayNotEmpty,
  IsArray,
  IsIn,
  IsNotEmpty,
  IsString,
  MaxLength,
} from 'class-validator';
import {
  Body,
  Controller,
  Delete,
  Get,
  HttpCode,
  Ip,
  Param,
  ParseUUIDPipe,
  Post,
  UseGuards,
} from '@nestjs/common';
import {
  API_KEY_PERMISSIONS,
  ApiKeyPermission,
  ApiKeyPublicRow,
  ApiKeysService,
} from './api-keys.service';
import { JwtAuthGuard } from '../../common/guards/jwt-auth.guard';
import { CurrentPrincipal } from '../../common/decorators/current-principal.decorator';
import { AuthPrincipal } from '../../common/types';

class CreateApiKeyDto {
  @IsString()
  @IsNotEmpty()
  @MaxLength(120)
  label!: string;

  @IsArray()
  @ArrayNotEmpty()
  @IsIn(API_KEY_PERMISSIONS as unknown as string[], { each: true })
  permissions!: ApiKeyPermission[];
}

@Controller('dashboard/api-keys')
@UseGuards(JwtAuthGuard)
export class ApiKeysController {
  constructor(private readonly apiKeys: ApiKeysService) {}

  @Post()
  async create(
    @CurrentPrincipal() principal: AuthPrincipal,
    @Body() dto: CreateApiKeyDto,
    @Ip() ip: string,
  ): Promise<{ apiKey: string; apiSecret: string; row: ApiKeyPublicRow }> {
    return this.apiKeys.create(principal.merchantId, dto.label, dto.permissions, ip);
  }

  @Get()
  async list(@CurrentPrincipal() principal: AuthPrincipal): Promise<ApiKeyPublicRow[]> {
    return this.apiKeys.list(principal.merchantId);
  }

  @Delete(':id')
  @HttpCode(204)
  async revoke(
    @CurrentPrincipal() principal: AuthPrincipal,
    @Param('id', ParseUUIDPipe) keyId: string,
    @Ip() ip: string,
  ): Promise<void> {
    await this.apiKeys.revoke(principal.merchantId, keyId, ip);
  }
}
