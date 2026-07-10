import { Global, Module } from '@nestjs/common';
import { LedgerService } from './ledger.service';

@Global()
@Module({
  providers: [LedgerService],
  exports: [LedgerService],
})
export class LedgerModule {}
