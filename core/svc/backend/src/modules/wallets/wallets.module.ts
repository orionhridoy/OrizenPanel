import { Global, Module } from '@nestjs/common';
import { SignerClientService } from './signer-client.service';
import { WalletsService } from './wallets.service';

@Global()
@Module({
  providers: [WalletsService, SignerClientService],
  exports: [WalletsService, SignerClientService],
})
export class WalletsModule {}
