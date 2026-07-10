import { Inject, Injectable, Logger, OnModuleDestroy } from '@nestjs/common';
import { Pool, PoolClient, QueryResultRow } from 'pg';
import { APP_CONFIG, AppConfig } from '../config/config';

@Injectable()
export class DatabaseService implements OnModuleDestroy {
  private readonly logger = new Logger(DatabaseService.name);
  private readonly pool: Pool;

  constructor(@Inject(APP_CONFIG) config: AppConfig) {
    this.pool = new Pool({
      connectionString: config.databaseUrl,
      max: 20,
      idleTimeoutMillis: 30_000,
      connectionTimeoutMillis: 10_000,
    });
    this.pool.on('error', (err) => this.logger.error(`idle client error: ${err.message}`));
  }

  async query<T extends QueryResultRow>(text: string, params: unknown[] = []): Promise<T[]> {
    const result = await this.pool.query<T>(text, params);
    return result.rows;
  }

  async queryOne<T extends QueryResultRow>(text: string, params: unknown[] = []): Promise<T | null> {
    const rows = await this.query<T>(text, params);
    return rows[0] ?? null;
  }

  /**
   * Runs fn inside a transaction. Rolls back on any throw.
   * All ledger postings and multi-table state changes MUST use this.
   */
  async tx<T>(fn: (client: PoolClient) => Promise<T>): Promise<T> {
    const client = await this.pool.connect();
    try {
      await client.query('BEGIN');
      const result = await fn(client);
      await client.query('COMMIT');
      return result;
    } catch (err) {
      await client.query('ROLLBACK').catch(() => undefined);
      throw err;
    } finally {
      client.release();
    }
  }

  async onModuleDestroy(): Promise<void> {
    await this.pool.end();
  }
}
