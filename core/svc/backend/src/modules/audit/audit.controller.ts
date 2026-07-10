import { Controller, Get, Query, UseGuards } from '@nestjs/common';
import { IsIn, IsInt, IsOptional, IsString, Max, MaxLength, Min } from 'class-validator';
import { Type } from 'class-transformer';
import { DatabaseService } from '../../database/database.service';
import { JwtAuthGuard } from '../../common/guards/jwt-auth.guard';
import { AdminGuard } from '../../common/guards/admin.guard';

class ListAuditDto {
  @IsOptional()
  @IsIn(['MERCHANT', 'ADMIN', 'API_KEY', 'SYSTEM'])
  actorType?: string;

  @IsOptional()
  @IsString()
  @MaxLength(100)
  action?: string;

  @IsOptional()
  @Type(() => Number)
  @IsInt()
  @Min(1)
  @Max(200)
  limit = 50;

  @IsOptional()
  @Type(() => Number)
  @IsInt()
  @Min(0)
  offset = 0;
}

@Controller('admin/audit-logs')
@UseGuards(JwtAuthGuard, AdminGuard)
export class AuditController {
  constructor(private readonly db: DatabaseService) {}

  @Get()
  async list(@Query() query: ListAuditDto): Promise<Array<Record<string, unknown>>> {
    return this.db.query(
      `SELECT id, actor_type, actor_id, action, resource_type, resource_id,
              ip, user_agent, metadata, created_at
         FROM audit_logs
        WHERE ($1::text IS NULL OR actor_type = $1)
          AND ($2::text IS NULL OR action LIKE $2 || '%')
        ORDER BY created_at DESC
        LIMIT $3 OFFSET $4`,
      [query.actorType ?? null, query.action ?? null, query.limit, query.offset],
    );
  }
}
