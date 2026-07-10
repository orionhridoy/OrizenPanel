import { Module } from '@nestjs/common';
import { MerchantsService } from './merchants.service';
import { AdminMerchantsController, MerchantsController } from './merchants.controller';

@Module({
  controllers: [MerchantsController, AdminMerchantsController],
  providers: [MerchantsService],
  exports: [MerchantsService],
})
export class MerchantsModule {}
