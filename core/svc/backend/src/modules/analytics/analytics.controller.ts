import { Controller, Get, Query, UseGuards } from '@nestjs/common';
import { Type } from 'class-transformer';
import { IsInt, IsOptional, Max, Min } from 'class-validator';
import { DatabaseService } from '../../database/database.service';
import { JwtAuthGuard } from '../../common/guards/jwt-auth.guard';
import { CurrentPrincipal } from '../../common/decorators/current-principal.decorator';
import { AuthPrincipal } from '../../common/types';

class RangeDto {
  @IsOptional()
  @Type(() => Number)
  @IsInt()
  @Min(1)
  @Max(365)
  days = 30;
}

/**
 * Merchant revenue analytics, computed from the invoices table (which is in
 * turn backed by the immutable ledger). Amounts are returned in base units;
 * the dashboard converts for display.
 */
@Controller('dashboard/analytics')
@UseGuards(JwtAuthGuard)
export class AnalyticsController {
  constructor(private readonly db: DatabaseService) {}

  @Get('summary')
  async summary(
    @CurrentPrincipal() principal: AuthPrincipal,
    @Query() range: RangeDto,
  ): Promise<Record<string, unknown>> {
    const [byAsset, series, funnel, links] = await Promise.all([
      this.db.query(
        `SELECT asset_code,
                COUNT(*) FILTER (WHERE status IN ('PAID','OVERPAID'))::int AS paid_count,
                COALESCE(SUM(amount_paid_confirmed) FILTER (WHERE status IN ('PAID','OVERPAID','UNDERPAID')), 0)::text AS revenue_base_units
           FROM invoices
          WHERE merchant_id = $1 AND created_at > now() - make_interval(days => $2)
          GROUP BY asset_code ORDER BY asset_code`,
        [principal.merchantId, range.days],
      ),
      this.db.query(
        `SELECT to_char(date_trunc('day', created_at), 'YYYY-MM-DD') AS day,
                asset_code,
                COUNT(*) FILTER (WHERE status IN ('PAID','OVERPAID'))::int AS paid_count,
                COALESCE(SUM(amount_paid_confirmed) FILTER (WHERE status IN ('PAID','OVERPAID','UNDERPAID')), 0)::text AS revenue_base_units
           FROM invoices
          WHERE merchant_id = $1 AND created_at > now() - make_interval(days => $2)
          GROUP BY 1, 2 ORDER BY 1`,
        [principal.merchantId, range.days],
      ),
      this.db.queryOne(
        `SELECT COUNT(*)::int AS created,
                COUNT(*) FILTER (WHERE status <> 'NEW')::int AS engaged,
                COUNT(*) FILTER (WHERE status IN ('PAID','OVERPAID'))::int AS paid,
                COUNT(*) FILTER (WHERE status = 'EXPIRED')::int AS expired,
                COUNT(*) FILTER (WHERE status = 'UNDERPAID')::int AS underpaid
           FROM invoices
          WHERE merchant_id = $1 AND created_at > now() - make_interval(days => $2)`,
        [principal.merchantId, range.days],
      ),
      this.db.query(
        `SELECT pl.title, pl.slug, pl.times_used,
                COUNT(i.id) FILTER (WHERE i.status IN ('PAID','OVERPAID'))::int AS paid_count
           FROM payment_links pl
           LEFT JOIN invoices i ON i.payment_link_id = pl.id
          WHERE pl.merchant_id = $1
          GROUP BY pl.id ORDER BY paid_count DESC LIMIT 10`,
        [principal.merchantId],
      ),
    ]);
    return { days: range.days, byAsset, series, funnel, topLinks: links };
  }
}
