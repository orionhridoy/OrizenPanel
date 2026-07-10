import { Global, Module } from '@nestjs/common';
import { PaymentEngineService } from './payment-engine.service';
import { ChainScanService } from './chain-scan.service';
import { SettlementService } from './settlement.service';

@Global()
@Module({
  providers: [PaymentEngineService, ChainScanService, SettlementService],
  exports: [PaymentEngineService, ChainScanService, SettlementService],
})
export class PaymentsModule {}
