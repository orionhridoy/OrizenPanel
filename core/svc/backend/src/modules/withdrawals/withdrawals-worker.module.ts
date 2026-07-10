import { Global, Module } from '@nestjs/common';
import { WithdrawalsService } from './withdrawals.service';
import { AutoPayoutService } from './auto-payout.service';

/** Worker-side withdrawals module: services only, no HTTP controllers. */
@Global()
@Module({
  providers: [WithdrawalsService, AutoPayoutService],
  exports: [WithdrawalsService, AutoPayoutService],
})
export class WithdrawalsWorkerModule {}
