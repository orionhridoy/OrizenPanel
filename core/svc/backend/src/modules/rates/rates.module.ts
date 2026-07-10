import { Global, Module } from '@nestjs/common';
import { RatesService } from './rates.service';
import { RatesController } from './rates.controller';

@Global()
@Module({
  controllers: [RatesController],
  providers: [RatesService],
  exports: [RatesService],
})
export class RatesModule {}
