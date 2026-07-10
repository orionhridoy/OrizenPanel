import { Global, Module } from '@nestjs/common';
import { NodesService } from './nodes.service';

@Global()
@Module({
  providers: [NodesService],
  exports: [NodesService],
})
export class NodesModule {}
