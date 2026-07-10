import { Controller, Get, ServiceUnavailableException } from '@nestjs/common';
import { DatabaseService } from '../../database/database.service';

interface ChainStatusRow {
  chain: string;
  height: string;
  peers: number;
  synced: boolean;
  progress: string;
  engine_active: boolean;
  version: string | null;
  updated_at: string;
}

/**
 * Public, unauthenticated endpoints consumed by install.sh, the compose
 * healthcheck, scripts/sync-status.sh and the checkout page footer.
 * They expose liveness and chain sync state only - no business data.
 */
@Controller('public')
export class HealthController {
  constructor(private readonly db: DatabaseService) {}

  @Get('health')
  async health(): Promise<{ status: 'ok'; time: string }> {
    try {
      await this.db.query('SELECT 1');
    } catch {
      throw new ServiceUnavailableException('database unreachable');
    }
    return { status: 'ok', time: new Date().toISOString() };
  }

  @Get('status')
  async status(): Promise<{
    chains: Array<{
      chain: string;
      synced: boolean;
      height: number;
      peers: number;
      progress: number;
      engineActive: boolean;
    }>;
  }> {
    const rows = await this.db.query<ChainStatusRow>(
      `SELECT chain, height, peers, synced, progress, engine_active, version, updated_at
         FROM node_status ORDER BY chain`,
    );
    return {
      chains: rows.map((row) => ({
        chain: row.chain,
        synced: row.synced,
        height: Number(row.height),
        peers: row.peers,
        progress: Number(row.progress),
        engineActive: row.engine_active,
      })),
    };
  }
}
