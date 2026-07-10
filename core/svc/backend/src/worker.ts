import 'reflect-metadata';
import { Logger } from '@nestjs/common';
import { NestFactory } from '@nestjs/core';
import { WorkerModule } from './worker.module';
import { loadConfig } from './config/config';
import { runMigrations } from './database/migrator';
import { WalletsService } from './modules/wallets/wallets.service';
import { WorkerRunnerService } from './workers/worker-runner.service';

const BOOTSTRAP_RETRIES = 30;
const BOOTSTRAP_RETRY_DELAY_MS = 5_000;

async function bootstrap(): Promise<void> {
  const logger = new Logger('WorkerBootstrap');
  const config = loadConfig();

  // idempotent + advisory-locked: safe even if the api migrated already
  await runMigrations(config.databaseUrl);

  const app = await NestFactory.create(WorkerModule, { logger: ['log', 'warn', 'error'] });
  app.enableShutdownHooks();
  await app.listen(config.httpPort, '0.0.0.0');
  logger.log(`worker health/metrics listening on :${config.httpPort}`);

  // wallet bootstrap needs the signer, which may still be starting
  const wallets = app.get(WalletsService);
  for (let attempt = 1; ; attempt++) {
    try {
      await wallets.ensureBootstrapped();
      break;
    } catch (err) {
      if (attempt >= BOOTSTRAP_RETRIES) throw err;
      logger.warn(
        `wallet bootstrap attempt ${attempt}/${BOOTSTRAP_RETRIES} failed: ${(err as Error).message} - retrying`,
      );
      await new Promise((resolve) => setTimeout(resolve, BOOTSTRAP_RETRY_DELAY_MS));
    }
  }

  await app.get(WorkerRunnerService).start();
  logger.log('Orizen Pay worker fully operational');
}

bootstrap().catch((err) => {
  // eslint-disable-next-line no-console
  console.error('fatal worker bootstrap error:', err);
  process.exit(1);
});
