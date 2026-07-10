import { Module } from '@nestjs/common';
import { ConfigModule } from './config/config.module';
import { DatabaseModule } from './database/database.module';
import { QueueModule } from './redis/queue.module';
import { MetricsModule } from './modules/metrics/metrics.module';
import { AuditModule } from './modules/audit/audit.module';
import { LedgerModule } from './modules/ledger/ledger.module';
import { BlockchainModule } from './modules/blockchain/blockchain.module';
import { WalletsModule } from './modules/wallets/wallets.module';
import { WebhooksModule } from './modules/webhooks/webhooks.module';
import { NodesModule } from './modules/nodes/nodes.module';
import { PaymentsModule } from './modules/payments/payments.module';
import { SyncModule } from './modules/sync/sync.service';
import { SweepsModule } from './modules/sweeps/sweeps.module';
import { WithdrawalsWorkerModule } from './modules/withdrawals/withdrawals-worker.module';
import { WorkerRunnerService } from './workers/worker-runner.service';
import { WorkerHealthController } from './workers/worker-health.controller';

/**
 * Worker process composition root: queue processors + health/metrics HTTP.
 * No public controllers - this process is attached to the signing network and
 * is never reachable from the edge.
 */
@Module({
  imports: [
    ConfigModule,
    DatabaseModule,
    QueueModule,
    MetricsModule,
    AuditModule,
    LedgerModule,
    BlockchainModule,
    WalletsModule,
    WebhooksModule,
    NodesModule,
    PaymentsModule,
    SweepsModule,
    WithdrawalsWorkerModule,
    SyncModule,
  ],
  controllers: [WorkerHealthController],
  providers: [WorkerRunnerService],
})
export class WorkerModule {}
