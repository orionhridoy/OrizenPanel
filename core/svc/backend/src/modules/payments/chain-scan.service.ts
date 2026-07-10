import { Injectable, Logger } from '@nestjs/common';
import { DatabaseService } from '../../database/database.service';
import { AdapterRegistry } from '../blockchain/adapter.registry';
import { WalletsService } from '../wallets/wallets.service';
import { NodesService } from '../nodes/nodes.service';
import { MetricsService } from '../metrics/metrics.service';
import { PaymentEngineService } from './payment-engine.service';
import { SyncService } from '../sync/sync.service';
import { Chain } from '../../common/types';

/** blocks per scan tick - bounded so one tick never monopolizes a worker */
const SCAN_BATCH: Record<Chain, number> = {
  bitcoin: 20,
  litecoin: 40,
  ethereum: 30,
  xrp: 500,
  // Tron mints a block every ~3s; a small batch keeps catch-up bursts under the
  // public TronGrid rate limit (use a TRON_API_KEY to lift the limit entirely).
  tron: 8,
};

const MAX_REORG_WALKBACK = 100;
const TIP_RETENTION = 200;

/**
 * Maintains the per-chain block cursor in chain_tips, detects
 * reorganizations by hash comparison, and feeds discovered transfers into the
 * payment engine. The payment engine gate (node synced) is enforced here.
 */
@Injectable()
export class ChainScanService {
  private readonly logger = new Logger(ChainScanService.name);

  constructor(
    private readonly db: DatabaseService,
    private readonly adapters: AdapterRegistry,
    private readonly wallets: WalletsService,
    private readonly nodes: NodesService,
    private readonly metrics: MetricsService,
    private readonly engine: PaymentEngineService,
    private readonly sync: SyncService,
  ) {}

  async scanChain(chain: Chain): Promise<void> {
    // per-chain live-sync switch (admin can pause detection for one chain)
    if (!(await this.sync.isChainEnabled(chain))) return;
    if (!(await this.nodes.isEngineActive(chain))) return;

    const adapter = this.adapters.forChain(chain);
    const tip = await adapter.getTip();
    if (!tip) return;
    // explorer-backed adapters poll each address over HTTP, so feed only the
    // bounded active-invoice set; node adapters filter node results by the full set
    const watch = adapter.usesAddressPolling
      ? await this.wallets.activeWatchSet(chain)
      : await this.wallets.watchSet(chain);

    const last = await this.db.queryOne<{ height: string; block_hash: string }>(
      `SELECT height::text, block_hash FROM chain_tips
        WHERE chain = $1 ORDER BY height DESC LIMIT 1`,
      [chain],
    );

    let scanFrom: number;
    if (!last) {
      // first activation: anchor at the current tip; history predates all invoices
      await this.storeTip(chain, tip.height, tip.hash, tip.parentHash);
      scanFrom = tip.height + 1;
    } else {
      let anchorHeight = Number(last.height);
      let anchorHash = last.block_hash;
      let walked = 0;
      while (walked < MAX_REORG_WALKBACK) {
        const nodeHash = await adapter.getBlockHashAtHeight(anchorHeight);
        if (nodeHash === anchorHash) break;
        walked += 1;
        const prev = await this.db.queryOne<{ height: string; block_hash: string }>(
          `SELECT height::text, block_hash FROM chain_tips
            WHERE chain = $1 AND height < $2 ORDER BY height DESC LIMIT 1`,
          [chain, anchorHeight],
        );
        if (!prev) break;
        anchorHeight = Number(prev.height);
        anchorHash = prev.block_hash;
      }
      if (walked > 0) {
        this.metrics.paymentAnomalies.labels(chain, 'chain_reorg').inc();
        this.logger.warn(`${chain}: reorg detected, rewound ${walked} block(s) to ${anchorHeight}`);
        await this.db.query(`DELETE FROM chain_tips WHERE chain = $1 AND height > $2`, [
          chain,
          anchorHeight,
        ]);
        // payments above the fork will be re-validated by the confirm cycle
      }
      scanFrom = anchorHeight + 1;
    }

    const scanTo = Math.min(tip.height, scanFrom + SCAN_BATCH[chain] - 1);
    if (scanTo >= scanFrom) {
      const transfers = await adapter.scanBlocks(scanFrom, scanTo, watch);
      for (const transfer of transfers) {
        await this.engine.registerTransfer(chain, transfer);
      }
      // carry the previous hash forward so each height is fetched once (halves
      // block lookups - important for rate-limited public RPCs like TronGrid)
      let parent = (await adapter.getBlockHashAtHeight(scanFrom - 1)) ?? '';
      for (let height = scanFrom; height <= scanTo; height++) {
        const hash = await adapter.getBlockHashAtHeight(height);
        if (!hash) break; // tip moved beneath us; next tick will continue
        await this.storeTip(chain, height, hash, parent);
        parent = hash;
      }
      await this.db.query(
        `DELETE FROM chain_tips WHERE chain = $1 AND height < $2`,
        [chain, scanTo - TIP_RETENTION],
      );
    }

    if (adapter.supportsMempool && watch.size > 0) {
      const unconfirmed = await adapter.scanMempool(watch);
      for (const transfer of unconfirmed) {
        await this.engine.registerTransfer(chain, transfer);
      }
    }
  }

  private async storeTip(
    chain: Chain,
    height: number,
    hash: string,
    parentHash: string,
  ): Promise<void> {
    await this.db.query(
      `INSERT INTO chain_tips (chain, height, block_hash, parent_hash)
       VALUES ($1, $2, $3, $4)
       ON CONFLICT (chain, height)
       DO UPDATE SET block_hash = EXCLUDED.block_hash, parent_hash = EXCLUDED.parent_hash,
                     scanned_at = now()`,
      [chain, height, hash, parentHash],
    );
  }
}
