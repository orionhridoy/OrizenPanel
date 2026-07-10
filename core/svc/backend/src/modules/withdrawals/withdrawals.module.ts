import { Global, Module } from '@nestjs/common';
import { WithdrawalsService } from './withdrawals.service';
import { PayoutsService } from './payouts.service';
import { AutoPayoutService } from './auto-payout.service';
import { WithdrawalsController } from './withdrawals.controller';
import { AdminWithdrawalsController } from './admin-withdrawals.controller';
import { AuthModule } from '../auth/auth.module';
import {
  DashboardPayoutsController,
  MerchantPayoutsController,
  RefundsController,
} from './payouts.controller';

@Global()
@Module({
  imports: [AuthModule],
  controllers: [
    WithdrawalsController,
    AdminWithdrawalsController,
    DashboardPayoutsController,
    MerchantPayoutsController,
    RefundsController,
  ],
  providers: [WithdrawalsService, PayoutsService, AutoPayoutService],
  exports: [WithdrawalsService, PayoutsService, AutoPayoutService],
})
export class WithdrawalsModule {}
