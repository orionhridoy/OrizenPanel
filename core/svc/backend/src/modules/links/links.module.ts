import { Module } from '@nestjs/common';
import { LinksService } from './links.service';
import { InvoicesModule } from '../invoices/invoices.module';
import {
  DashboardLinksController,
  MerchantLinksController,
  PublicLinksController,
} from './links.controller';

@Module({
  imports: [InvoicesModule],
  controllers: [DashboardLinksController, MerchantLinksController, PublicLinksController],
  providers: [LinksService],
  exports: [LinksService],
})
export class LinksModule {}
