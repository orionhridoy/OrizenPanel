import { Global, Module } from '@nestjs/common';
import { APP_CONFIG, loadConfig } from './config';

@Global()
@Module({
  providers: [{ provide: APP_CONFIG, useFactory: loadConfig }],
  exports: [APP_CONFIG],
})
export class ConfigModule {}
