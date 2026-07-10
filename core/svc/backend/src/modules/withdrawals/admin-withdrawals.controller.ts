import {
  Controller,
  Get,
  HttpCode,
  Ip,
  Param,
  ParseUUIDPipe,
  Post,
  UseGuards,
} from '@nestjs/common';
import { WithdrawalsService } from './withdrawals.service';
import { QueueService } from '../../redis/queue.module';
import { DatabaseService } from '../../database/database.service';
import { JwtAuthGuard } from '../../common/guards/jwt-auth.guard';
import { AdminGuard } from '../../common/guards/admin.guard';
import { CurrentPrincipal } from '../../common/decorators/current-principal.decorator';
import { AuthPrincipal } from '../../common/types';

@Controller('admin/withdrawals')
@UseGuards(JwtAuthGuard, AdminGuard)
export class AdminWithdrawalsController {
  constructor(
    private readonly withdrawals: WithdrawalsService,
    private readonly queues: QueueService,
    private readonly db: DatabaseService,
  ) {}

  @Get('pending')
  async pending(): Promise<Array<Record<string, unknown>>> {
    return this.db.query(
      `SELECT w.id, w.merchant_id, m.email AS merchant_email, w.asset_code, w.amount::text,
              w.destination_address, w.destination_tag::text, w.risk_flags, w.created_at
         FROM withdrawals w JOIN merchants m ON m.id = w.merchant_id
        WHERE w.status = 'PENDING' ORDER BY w.created_at`,
    );
  }

  @Post(':id/approve')
  @HttpCode(204)
  async approve(
    @CurrentPrincipal() principal: AuthPrincipal,
    @Param('id', ParseUUIDPipe) withdrawalId: string,
    @Ip() ip: string,
  ): Promise<void> {
    await this.withdrawals.decide(withdrawalId, principal.merchantId, true, ip);
    await this.queues.add('withdrawals-process', 'process', { withdrawalId });
  }

  @Post(':id/reject')
  @HttpCode(204)
  async reject(
    @CurrentPrincipal() principal: AuthPrincipal,
    @Param('id', ParseUUIDPipe) withdrawalId: string,
    @Ip() ip: string,
  ): Promise<void> {
    await this.withdrawals.decide(withdrawalId, principal.merchantId, false, ip);
  }
}
