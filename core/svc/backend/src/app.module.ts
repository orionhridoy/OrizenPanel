import { Module } from '@nestjs/common';
import { APP_FILTER, APP_INTERCEPTOR } from '@nestjs/core';
import { ConfigModule } from './config/config.module';
import { DatabaseModule } from './database/database.module';
import { QueueModule } from './redis/queue.module';
import { MetricsModule } from './modules/metrics/metrics.module';
import { AuditModule } from './modules/audit/audit.module';
import { LedgerModule } from './modules/ledger/ledger.module';
import { HealthModule } from './modules/health/health.module';
import { BlockchainModule } from './modules/blockchain/blockchain.module';
import { WalletsModule } from './modules/wallets/wallets.module';
import { AuthModule } from './modules/auth/auth.module';
import { MerchantsModule } from './modules/merchants/merchants.module';
import { ApiKeysModule } from './modules/api-keys/api-keys.module';
import { InvoicesModule } from './modules/invoices/invoices.module';
import { PaymentsModule } from './modules/payments/payments.module';
import { WebhooksModule } from './modules/webhooks/webhooks.module';
import { WithdrawalsModule } from './modules/withdrawals/withdrawals.module';
import { NodesModule } from './modules/nodes/nodes.module';
import { RatesModule } from './modules/rates/rates.module';
import { StoreModule } from './modules/store/store.module';
import { LinksModule } from './modules/links/links.module';
import { AnalyticsModule } from './modules/analytics/analytics.module';
import { SyncModule } from './modules/sync/sync.service';
import { AssetsModule } from './modules/assets/assets.module';
import { ApiControllersModule } from './modules/api-controllers.module';
import { HttpExceptionFilter } from './common/filters/http-exception.filter';
import { MetricsInterceptor } from './common/interceptors/metrics.interceptor';

/** API process composition root. Workers use WorkerModule instead. */
@Module({
  imports: [
    ConfigModule,
    DatabaseModule,
    QueueModule,
    MetricsModule,
    AuditModule,
    LedgerModule,
    HealthModule,
    BlockchainModule,
    WalletsModule,
    AuthModule,
    MerchantsModule,
    ApiKeysModule,
    InvoicesModule,
    PaymentsModule,
    WebhooksModule,
    WithdrawalsModule,
    NodesModule,
    RatesModule,
    StoreModule,
    LinksModule,
    AnalyticsModule,
    AssetsModule,
    SyncModule,
    ApiControllersModule,
  ],
  providers: [
    { provide: APP_FILTER, useClass: HttpExceptionFilter },
    { provide: APP_INTERCEPTOR, useClass: MetricsInterceptor },
  ],
})
export class AppModule {}
