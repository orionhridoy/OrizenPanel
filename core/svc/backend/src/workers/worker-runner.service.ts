import { Inject, Injectable, Logger, OnModuleDestroy } from '@nestjs/common';
import { ConnectionOptions, Job, Worker } from 'bullmq';
import IORedis from 'ioredis';
import { QueueName, QueueService, QUEUE_NAMES, REDIS_CONNECTION } from '../redis/queue.module';
import { NodesService } from '../modules/nodes/nodes.service';
import { ChainScanService } from '../modules/payments/chain-scan.service';
import { PaymentEngineService } from '../modules/payments/payment-engine.service';
import { SettlementService } from '../modules/payments/settlement.service';
import { WebhooksService } from '../modules/webhooks/webhooks.service';
import { SweepsService } from '../modules/sweeps/sweeps.service';
import { WithdrawalsService } from '../modules/withdrawals/withdrawals.service';
import { AutoPayoutService } from '../modules/withdrawals/auto-payout.service';
import { LedgerService } from '../modules/ledger/ledger.service';
import { AuditService } from '../modules/audit/audit.service';
import { MetricsService } from '../modules/metrics/metrics.service';
import { AssetCode, Chain } from '../common/types';

const CHAINS: Chain[] = ['bitcoin', 'litecoin', 'ethereum', 'xrp', 'tron'];
const ASSETS: AssetCode[] = ['BTC', 'LTC', 'ETH', 'XRP', 'USDT_TRC20', 'USDC_ERC20'];

/** per-chain scan cadence (ms) roughly matched to block time */
const SCAN_EVERY: Record<Chain, number> = {
  bitcoin: 20_000,
  litecoin: 20_000,
  ethereum: 12_000,
  xrp: 10_000,
  tron: 6_000,
};

@Injectable()
export class WorkerRunnerService implements OnModuleDestroy {
  private readonly logger = new Logger(WorkerRunnerService.name);
  private readonly workers: Worker[] = [];

  constructor(
    @Inject(REDIS_CONNECTION) private readonly connection: IORedis,
    private readonly queues: QueueService,
    private readonly nodes: NodesService,
    private readonly scanner: ChainScanService,
    private readonly engine: PaymentEngineService,
    private readonly settlement: SettlementService,
    private readonly webhooks: WebhooksService,
    private readonly sweeps: SweepsService,
    private readonly withdrawals: WithdrawalsService,
    private readonly autoPayout: AutoPayoutService,
    private readonly ledger: LedgerService,
    private readonly audit: AuditService,
    private readonly metrics: MetricsService,
  ) {}

  async start(): Promise<void> {
    this.register('nodes-poll', async () => this.nodes.pollAll());
    this.register('payments-scan', async (job) => this.scanner.scanChain(job.data.chain as Chain));
    this.register('payments-confirm', async (job) =>
      this.engine.reconcileOpenPayments(job.data.chain as Chain),
    );
    this.register('invoices-expire', async () => this.engine.expireInvoices());
    this.register('webhooks-deliver', async (job) => {
      if (job.name === 'requeue') {
        await this.webhooks.requeueDue();
      } else {
        await this.webhooks.deliver(job.data.deliveryId as string);
      }
    });
    this.register('sweeps-execute', async (job) =>
      this.sweeps.runForAsset(job.data.assetCode as AssetCode),
    );
    this.register('withdrawals-process', async (job) => {
      if (job.name === 'track') {
        await this.withdrawals.trackBroadcast();
      } else if (job.name === 'drive') {
        await this.driveApproved();
      } else {
        await this.withdrawals.processApproved(job.data.withdrawalId as string);
      }
    });
    this.register('ledger-reconcile', async () => this.reconcile());
    this.register('settlements-run', async () => this.settlement.runScheduled());
    this.register('payouts-auto', async () => this.autoPayout.runOnce());

    await this.scheduleRepeatables();
    this.startQueueDepthSampler();
    this.logger.log('payment engine workers started');
  }

  private register(queue: QueueName, handler: (job: Job) => Promise<void>): void {
    const worker = new Worker(
      queue,
      async (job) => {
        try {
          await handler(job);
        } catch (err) {
          this.logger.error(`${queue}/${job.name} failed: ${(err as Error).message}`);
          throw err; // BullMQ retry/backoff takes over
        }
      },
      {
        // each Worker gets its own connection: BullMQ uses blocking commands
        // that must not share the pooled connection. Cast for the bundled-ioredis
        // nominal-type mismatch (see queue.module.ts).
        connection: this.connection.duplicate() as unknown as ConnectionOptions,
        concurrency: queue === 'webhooks-deliver' ? 8 : 1,
        lockDuration: 120_000,
      },
    );
    worker.on('error', (err) => this.logger.error(`worker ${queue} error: ${err.message}`));
    this.workers.push(worker);
  }

  private async scheduleRepeatables(): Promise<void> {
    await this.queues.ensureRepeatable('nodes-poll', 'poll', 15_000);
    for (const chain of CHAINS) {
      await this.queues.ensureRepeatable('payments-scan', `scan-${chain}`, SCAN_EVERY[chain], {
        chain,
      });
      await this.queues.ensureRepeatable('payments-confirm', `confirm-${chain}`, 30_000, { chain });
    }
    await this.queues.ensureRepeatable('invoices-expire', 'expire', 60_000);
    await this.queues.ensureRepeatable('webhooks-deliver', 'requeue', 60_000);
    const sweepInterval = await this.sweepIntervalMs();
    for (const assetCode of ASSETS) {
      await this.queues.ensureRepeatable('sweeps-execute', `sweep-${assetCode}`, sweepInterval, {
        assetCode,
      });
    }
    await this.queues.ensureRepeatable('withdrawals-process', 'track', 60_000);
    // drive job: gather deposit funds to the treasury and push APPROVED withdrawals
    // (incl. auto-payouts) through to broadcast, retrying until funds are gathered.
    await this.queues.ensureRepeatable('withdrawals-process', 'drive', 60_000);
    await this.queues.ensureRepeatable('ledger-reconcile', 'reconcile', 300_000);
    await this.queues.ensureRepeatable('settlements-run', 'run', 60_000);
    await this.queues.ensureRepeatable('payouts-auto', 'run', 120_000);
  }

  /**
   * Gathers deposit funds into the treasury for any asset with an APPROVED
   * withdrawal waiting, then (re)processes each APPROVED withdrawal. This is what
   * makes "withdraw / auto-payout" reliable for balances still sitting in deposit
   * addresses below the normal sweep batch size.
   */
  private async driveApproved(): Promise<void> {
    const approved = await this.withdrawals.approvedIds();
    if (approved.length === 0) return;
    const assets = [...new Set(approved.map((w) => w.asset_code))];
    for (const asset of assets) {
      try {
        await this.sweeps.gatherToTreasury(asset);
      } catch (err) {
        this.logger.warn(`gather ${asset} failed: ${(err as Error).message}`);
      }
    }
    for (const w of approved) {
      try {
        await this.withdrawals.processApproved(w.id);
      } catch (err) {
        this.logger.warn(`drive withdrawal ${w.id} failed: ${(err as Error).message}`);
      }
    }
  }

  private async sweepIntervalMs(): Promise<number> {
    return 900_000; // settings-driven refinement happens per-tick inside SweepsService
  }

  /** Proves account_balances against SUM(ledger_entries). Drift must be zero. */
  private async reconcile(): Promise<void> {
    const drift = await this.ledger.findDrift();
    this.metrics.reconciliationDrift.set(drift.length);
    if (drift.length > 0) {
      this.logger.error(
        `LEDGER DRIFT on ${drift.length} account(s): ${JSON.stringify(drift.slice(0, 5))}`,
      );
      await this.audit.log({
        actorType: 'SYSTEM',
        action: 'ledger.drift_detected',
        metadata: { count: drift.length, sample: drift.slice(0, 5) },
      });
    }
  }

  private startQueueDepthSampler(): void {
    const sample = async (): Promise<void> => {
      for (const name of QUEUE_NAMES) {
        try {
          const counts = await this.queues.queue(name).getJobCounts('waiting', 'delayed');
          this.metrics.queueDepth.labels(name).set((counts.waiting ?? 0) + (counts.delayed ?? 0));
        } catch {
          // redis hiccup - next sample will catch up
        }
      }
    };
    const timer = setInterval(() => void sample(), 30_000);
    timer.unref();
  }

  async onModuleDestroy(): Promise<void> {
    await Promise.all(this.workers.map((worker) => worker.close()));
  }
}
