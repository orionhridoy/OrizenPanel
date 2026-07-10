import { Injectable, Logger } from '@nestjs/common';
import { DatabaseService } from '../../database/database.service';
import { AdapterRegistry } from '../blockchain/adapter.registry';
import { MetricsService } from '../metrics/metrics.service';
import { Chain } from '../../common/types';

export interface NodeStatusRow {
  chain: Chain;
  height: string;
  best_hash: string | null;
  peers: number;
  synced: boolean;
  progress: string;
  engine_active: boolean;
  version: string | null;
  last_error: string | null;
  updated_at: string;
}

@Injectable()
export class NodesService {
  private readonly logger = new Logger(NodesService.name);

  constructor(
    private readonly db: DatabaseService,
    private readonly adapters: AdapterRegistry,
    private readonly metrics: MetricsService,
  ) {}

  /** Polls every chain node and persists sync state; gates the payment engine. */
  async pollAll(): Promise<void> {
    for (const adapter of this.adapters.all()) {
      const chain = adapter.chain;
      try {
        const status = await adapter.getSyncStatus();
        const tip = status.synced ? await adapter.getTip() : null;
        await this.db.query(
          `UPDATE node_status
              SET height = $2, best_hash = $3, peers = $4, synced = $5, progress = $6,
                  engine_active = $5, version = $7, last_error = NULL, updated_at = now()
            WHERE chain = $1`,
          [
            chain,
            status.height,
            tip?.hash ?? null,
            status.peers,
            status.synced,
            status.progress.toFixed(5),
            status.version ?? null,
          ],
        );
        this.metrics.nodeSyncProgress.labels(chain).set(status.progress);
        this.metrics.nodeHeight.labels(chain).set(status.height);
        this.metrics.nodePeers.labels(chain).set(status.peers);
        this.metrics.engineActive.labels(chain).set(status.synced ? 1 : 0);
      } catch (err) {
        const message = (err as Error).message.slice(0, 300);
        this.logger.warn(`${chain} node poll failed: ${message}`);
        // Debounce transient failures: record the error, but only drop synced/active
        // if there has been NO successful poll for 3 minutes. A single blip (rate limit,
        // network hiccup) must not flip a healthy chain "ACTIVE" -> "Waiting for sync"
        // -> "ACTIVE". We intentionally do NOT bump updated_at here, so the grace window
        // is measured from the last SUCCESSFUL update.
        await this.db.query(
          `UPDATE node_status
              SET last_error = $2,
                  synced        = CASE WHEN updated_at < now() - interval '3 minutes' THEN false ELSE synced END,
                  engine_active = CASE WHEN updated_at < now() - interval '3 minutes' THEN false ELSE engine_active END
            WHERE chain = $1`,
          [chain, message],
        );
        this.metrics.engineActive.labels(chain).set(0);
      }
    }
  }

  async isEngineActive(chain: Chain): Promise<boolean> {
    const row = await this.db.queryOne<{ engine_active: boolean }>(
      `SELECT engine_active FROM node_status WHERE chain = $1`,
      [chain],
    );
    return row?.engine_active ?? false;
  }

  async list(): Promise<NodeStatusRow[]> {
    return this.db.query<NodeStatusRow>(
      `SELECT chain, height::text, best_hash, peers, synced, progress::text,
              engine_active, version, last_error, updated_at
         FROM node_status ORDER BY chain`,
    );
  }
}
