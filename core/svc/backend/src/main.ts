import 'reflect-metadata';
import { Logger, ValidationPipe } from '@nestjs/common';
import { NestFactory } from '@nestjs/core';
import { NestExpressApplication } from '@nestjs/platform-express';
import helmet from 'helmet';
import { json } from 'express';
import { AppModule } from './app.module';
import { loadConfig } from './config/config';
import { runMigrations } from './database/migrator';

async function bootstrap(): Promise<void> {
  const logger = new Logger('Bootstrap');
  const config = loadConfig();

  await runMigrations(config.databaseUrl);

  const app = await NestFactory.create<NestExpressApplication>(AppModule, {
    logger: ['log', 'warn', 'error'],
  });

  app.set('trust proxy', 1); // nginx terminates TLS in front of us
  app.use(helmet({ contentSecurityPolicy: false })); // CSP is nginx's job for the SPA
  app.use(
    json({
      limit: '256kb',
      // keep the exact bytes for API-key HMAC verification
      verify: (req, _res, buf) => {
        (req as { rawBody?: Buffer }).rawBody = buf;
      },
    }),
  );
  app.setGlobalPrefix('api/v1', { exclude: ['metrics'] });
  app.useGlobalPipes(
    new ValidationPipe({
      whitelist: true,
      forbidNonWhitelisted: true,
      transform: true,
      transformOptions: { enableImplicitConversion: false },
    }),
  );
  app.enableShutdownHooks();

  await app.listen(config.httpPort, '0.0.0.0');
  logger.log(`Orizen Pay API listening on :${config.httpPort} (${config.nodeEnv})`);
}

bootstrap().catch((err) => {
  // eslint-disable-next-line no-console
  console.error('fatal bootstrap error:', err);
  process.exit(1);
});
