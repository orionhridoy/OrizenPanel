import { Module } from '@nestjs/common';
import { StoreService } from './store.service';
import { InvoicesModule } from '../invoices/invoices.module';
import {
  DashboardStoreController,
  MerchantStoreController,
  PublicStoreController,
} from './store.controller';

@Module({
  imports: [InvoicesModule],
  controllers: [MerchantStoreController, PublicStoreController, DashboardStoreController],
  providers: [StoreService],
  exports: [StoreService],
})
export class StoreModule {}
