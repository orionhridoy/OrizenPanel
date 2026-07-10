import { Logger } from '@nestjs/common';
import { readdirSync, readFileSync } from 'fs';
import { join } from 'path';
import { Client } from 'pg';

const MIGRATION_LOCK_KEY = 0x6e_61_76_78; // 'navx'

/**
 * Applies pending SQL migrations in filename order, one transaction each.
 * A pg advisory lock serializes migrators across replicas, so scaling the
 * api service never races schema changes.
 */
export async function runMigrations(databaseUrl: string): Promise<void> {
  const logger = new Logger('Migrator');
  const dir = join(__dirname, 'migrations');
  const files = readdirSync(dir)
    .filter((f) => f.endsWith('.sql'))
    .sort();

  const client = new Client({ connectionString: databaseUrl });
  await client.connect();
  try {
    await client.query('SELECT pg_advisory_lock($1)', [MIGRATION_LOCK_KEY]);
    await client.query(`
      CREATE TABLE IF NOT EXISTS schema_migrations (
        name       text PRIMARY KEY,
        applied_at timestamptz NOT NULL DEFAULT now()
      )`);

    const applied = new Set(
      (await client.query<{ name: string }>('SELECT name FROM schema_migrations')).rows.map(
        (r) => r.name,
      ),
    );

    for (const file of files) {
      if (applied.has(file)) continue;
      const sql = readFileSync(join(dir, file), 'utf8');
      logger.log(`applying ${file}`);
      try {
        await client.query('BEGIN');
        await client.query(sql);
        await client.query('INSERT INTO schema_migrations (name) VALUES ($1)', [file]);
        await client.query('COMMIT');
      } catch (err) {
        await client.query('ROLLBACK').catch(() => undefined);
        throw new Error(`migration ${file} failed: ${(err as Error).message}`);
      }
    }
    logger.log(`schema up to date (${files.length} migrations)`);
  } finally {
    await client.query('SELECT pg_advisory_unlock($1)', [MIGRATION_LOCK_KEY]).catch(() => undefined);
    await client.end();
  }
}
