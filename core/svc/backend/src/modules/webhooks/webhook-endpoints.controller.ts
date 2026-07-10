import {
  ArrayNotEmpty,
  IsArray,
  IsBoolean,
  IsIn,
  IsOptional,
  IsString,
  Matches,
  MaxLength,
} from 'class-validator';
import {
  BadRequestException,
  Body,
  Controller,
  Delete,
  Get,
  HttpCode,
  Inject,
  Ip,
  Param,
  ParseUUIDPipe,
  Patch,
  Post,
  UseGuards,
} from '@nestjs/common';
import { APP_CONFIG, AppConfig } from '../../config/config';
import { DatabaseService } from '../../database/database.service';
import { AuditService } from '../audit/audit.service';
import { JwtAuthGuard } from '../../common/guards/jwt-auth.guard';
import { CurrentPrincipal } from '../../common/decorators/current-principal.decorator';
import { AuthPrincipal } from '../../common/types';
import { encryptSecret, randomHex } from '../../common/utils/crypto.util';
import { assertSafeWebhookUrl } from '../../common/utils/ssrf.util';

const WEBHOOK_EVENTS = [
  'invoice.seen',
  'invoice.confirming',
  'invoice.paid',
  'invoice.underpaid',
  'invoice.overpaid',
  'invoice.expired',
  'invoice.invalid',
  'withdrawal.broadcast',
  'withdrawal.confirmed',
  'withdrawal.failed',
] as const;

class CreateEndpointDto {
  @IsString()
  @MaxLength(500)
  @Matches(/^https?:\/\//)
  url!: string;

  @IsOptional()
  @IsArray()
  @ArrayNotEmpty()
  @IsIn(WEBHOOK_EVENTS as unknown as string[], { each: true })
  events?: string[];
}

class UpdateEndpointDto {
  @IsOptional()
  @IsBoolean()
  isActive?: boolean;

  @IsOptional()
  @IsArray()
  @ArrayNotEmpty()
  @IsIn(WEBHOOK_EVENTS as unknown as string[], { each: true })
  events?: string[];
}

@Controller('dashboard/webhooks')
@UseGuards(JwtAuthGuard)
export class WebhookEndpointsController {
  constructor(
    private readonly db: DatabaseService,
    private readonly audit: AuditService,
    @Inject(APP_CONFIG) private readonly config: AppConfig,
  ) {}

  /** The signing secret is returned exactly once at creation. */
  @Post()
  async create(
    @CurrentPrincipal() principal: AuthPrincipal,
    @Body() dto: CreateEndpointDto,
    @Ip() ip: string,
  ): Promise<Record<string, unknown>> {
    try {
      await assertSafeWebhookUrl(dto.url);
    } catch (err) {
      throw new BadRequestException((err as Error).message);
    }
    const secret = `whsec_${randomHex(32)}`;
    const events = dto.events ?? [...WEBHOOK_EVENTS];
    const row = await this.db.queryOne<Record<string, unknown>>(
      `INSERT INTO webhook_endpoints (merchant_id, url, secret_encrypted, events)
       VALUES ($1, $2, $3, $4)
       RETURNING id, url, events, is_active, created_at`,
      [principal.merchantId, dto.url, encryptSecret(secret, this.config.appEncryptionKey), events],
    );
    await this.audit.log({
      actorType: 'MERCHANT',
      actorId: principal.merchantId,
      action: 'webhook.endpoint_created',
      resourceType: 'webhook_endpoint',
      resourceId: (row as { id: string }).id,
      ip,
      metadata: { url: dto.url },
    });
    return { ...row, secret };
  }

  @Get()
  async list(@CurrentPrincipal() principal: AuthPrincipal): Promise<Array<Record<string, unknown>>> {
    return this.db.query(
      `SELECT id, url, events, is_active, created_at
         FROM webhook_endpoints WHERE merchant_id = $1 ORDER BY created_at DESC`,
      [principal.merchantId],
    );
  }

  @Patch(':id')
  async update(
    @CurrentPrincipal() principal: AuthPrincipal,
    @Param('id', ParseUUIDPipe) endpointId: string,
    @Body() dto: UpdateEndpointDto,
  ): Promise<Record<string, unknown> | null> {
    return this.db.queryOne(
      `UPDATE webhook_endpoints
          SET is_active = COALESCE($3, is_active),
              events = COALESCE($4, events)
        WHERE id = $1 AND merchant_id = $2
        RETURNING id, url, events, is_active, created_at`,
      [endpointId, principal.merchantId, dto.isActive ?? null, dto.events ?? null],
    );
  }

  @Delete(':id')
  @HttpCode(204)
  async remove(
    @CurrentPrincipal() principal: AuthPrincipal,
    @Param('id', ParseUUIDPipe) endpointId: string,
    @Ip() ip: string,
  ): Promise<void> {
    await this.db.query(
      `DELETE FROM webhook_endpoints WHERE id = $1 AND merchant_id = $2`,
      [endpointId, principal.merchantId],
    );
    await this.audit.log({
      actorType: 'MERCHANT',
      actorId: principal.merchantId,
      action: 'webhook.endpoint_deleted',
      resourceType: 'webhook_endpoint',
      resourceId: endpointId,
      ip,
    });
  }

  @Get(':id/deliveries')
  async deliveries(
    @CurrentPrincipal() principal: AuthPrincipal,
    @Param('id', ParseUUIDPipe) endpointId: string,
  ): Promise<Array<Record<string, unknown>>> {
    return this.db.query(
      `SELECT d.id, d.event_type, d.status, d.attempt_count, d.last_response_code,
              d.last_error, d.created_at, d.delivered_at
         FROM webhook_deliveries d
         JOIN webhook_endpoints e ON e.id = d.endpoint_id
        WHERE d.endpoint_id = $1 AND e.merchant_id = $2
        ORDER BY d.created_at DESC LIMIT 100`,
      [endpointId, principal.merchantId],
    );
  }
}
