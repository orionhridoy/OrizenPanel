import { Module } from '@nestjs/common';
import { InvoicesService } from './invoices.service';
import {
  DashboardInvoicesController,
  MerchantInvoicesController,
  PublicInvoicesController,
} from './invoices.controller';

@Module({
  controllers: [
    MerchantInvoicesController,
    DashboardInvoicesController,
    PublicInvoicesController,
  ],
  providers: [InvoicesService],
  exports: [InvoicesService],
})
export class InvoicesModule {}
