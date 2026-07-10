import { Global, Inject, Injectable, Logger, Module, OnModuleDestroy } from '@nestjs/common';
import { ConnectionOptions, JobsOptions, Queue } from 'bullmq';
import IORedis from 'ioredis';
import { APP_CONFIG, AppConfig } from '../config/config';

// BullMQ 5 forbids ':' in queue names (it is the Redis key separator), so the
// segment separator is '-'.
export const QUEUE_NAMES = [
  'payments-scan',
  'payments-confirm',
  'webhooks-deliver',
  'sweeps-execute',
  'withdrawals-process',
  'ledger-reconcile',
  'nodes-poll',
  'invoices-expire',
  'settlements-run',
  'payouts-auto',
] as const;
export type QueueName = (typeof QUEUE_NAMES)[number];

export const REDIS_CONNECTION = 'REDIS_CONNECTION' as const;

const DEFAULT_JOB_OPTIONS: JobsOptions = {
  removeOnComplete: { count: 1000 },
  removeOnFail: { count: 5000 },
  attempts: 5,
  backoff: { type: 'exponential', delay: 3000 },
};

@Injectable()
export class QueueService implements OnModuleDestroy {
  private readonly logger = new Logger(QueueService.name);
  private readonly queues = new Map<QueueName, Queue>();

  constructor(@Inject(REDIS_CONNECTION) private readonly connection: IORedis) {
    for (const name of QUEUE_NAMES) {
      this.queues.set(
        name,
        new Queue(name, {
          // bullmq bundles its own ioredis copy; the shared instance is
          // structurally identical but nominally distinct, hence the cast
          connection: this.connection as unknown as ConnectionOptions,
          defaultJobOptions: DEFAULT_JOB_OPTIONS,
        }),
      );
    }
  }

  queue(name: QueueName): Queue {
    const q = this.queues.get(name);
    if (!q) throw new Error(`unknown queue: ${name}`);
    return q;
  }

  async add(
    name: QueueName,
    jobName: string,
    data: Record<string, unknown>,
    opts?: JobsOptions,
  ): Promise<void> {
    await this.queue(name).add(jobName, data, opts);
  }

  /** Idempotent scheduling of repeatable jobs (worker startup). */
  async ensureRepeatable(
    name: QueueName,
    jobName: string,
    everyMs: number,
    data: Record<string, unknown> = {},
  ): Promise<void> {
    await this.queue(name).add(jobName, data, {
      repeat: { every: everyMs },
      jobId: `repeat-${name}-${jobName}`,
    });
    this.logger.log(`repeatable ${name}/${jobName} every ${everyMs}ms`);
  }

  async onModuleDestroy(): Promise<void> {
    await Promise.all([...this.queues.values()].map((q) => q.close()));
  }
}

@Global()
@Module({
  providers: [
    {
      provide: REDIS_CONNECTION,
      useFactory: (config: AppConfig) =>
        new IORedis(config.redisUrl, { maxRetriesPerRequest: null, enableReadyCheck: true }),
      inject: [APP_CONFIG],
    },
    QueueService,
  ],
  exports: [REDIS_CONNECTION, QueueService],
})
export class QueueModule implements OnModuleDestroy {
  constructor(@Inject(REDIS_CONNECTION) private readonly connection: IORedis) {}
  async onModuleDestroy(): Promise<void> {
    await this.connection.quit().catch(() => this.connection.disconnect());
  }
}
