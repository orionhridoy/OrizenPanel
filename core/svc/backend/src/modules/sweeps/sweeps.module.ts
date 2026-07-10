import { Global, Module } from '@nestjs/common';
import { SweepsService } from './sweeps.service';

@Global()
@Module({
  providers: [SweepsService],
  exports: [SweepsService],
})
export class SweepsModule {}
