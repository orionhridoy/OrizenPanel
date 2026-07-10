import { Global, Injectable, Module } from '@nestjs/common';
import { DatabaseService } from '../../database/database.service';
import { Chain } from '../../common/types';

const KEY = 'engine.sync_chains';
const CHAINS: Chain[] = ['bitcoin', 'litecoin', 'ethereum', 'xrp', 'tron'];

/**
 * Per-chain live-sync switches. Each chain can be paused/resumed independently
 * (settings.engine.sync_chains = { bitcoin: true, ethereum: false, ... }).
 * The chain-scan worker checks its chain's flag every tick. Pausing is a clean
 * DB flag - it never touches containers. Default for any chain: enabled.
 */
@Injectable()
export class SyncService {
  constructor(private readonly db: DatabaseService) {}

  async getStates(): Promise<Record<Chain, boolean>> {
    const row = await this.db.queryOne<{ value: Record<string, boolean> }>(
      `SELECT value FROM settings WHERE key = $1`,
      [KEY],
    );
    const stored = row?.value ?? {};
    const out = {} as Record<Chain, boolean>;
    for (const c of CHAINS) out[c] = stored[c] ?? true;
    return out;
  }

  async isChainEnabled(chain: Chain): Promise<boolean> {
    const states = await this.getStates();
    return states[chain] ?? true;
  }

  async setChain(chain: Chain, enabled: boolean): Promise<void> {
    const states = await this.getStates();
    states[chain] = enabled;
    await this.persist(states);
  }

  async setAll(enabled: boolean): Promise<void> {
    const states = {} as Record<Chain, boolean>;
    for (const c of CHAINS) states[c] = enabled;
    await this.persist(states);
  }

  private async persist(states: Record<Chain, boolean>): Promise<void> {
    await this.db.query(
      `INSERT INTO settings (key, value) VALUES ($1, $2::jsonb)
       ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value, updated_at = now()`,
      [KEY, JSON.stringify(states)],
    );
  }
}

@Global()
@Module({
  providers: [SyncService],
  exports: [SyncService],
})
export class SyncModule {}
