import { Inject, Injectable, Logger } from '@nestjs/common';
import { APP_CONFIG, AppConfig } from '../../config/config';
import { DatabaseService } from '../../database/database.service';
import { QueueService } from '../../redis/queue.module';
import { MetricsService } from '../metrics/metrics.service';
import { decryptSecret, hmacSha256Hex } from '../../common/utils/crypto.util';
import { assertSafeWebhookUrl } from '../../common/utils/ssrf.util';

export type WebhookEvent =
  | 'invoice.seen'
  | 'invoice.confirming'
  | 'invoice.paid'
  | 'invoice.underpaid'
  | 'invoice.overpaid'
  | 'invoice.expired'
  | 'invoice.invalid'
  | 'withdrawal.broadcast'
  | 'withdrawal.confirmed'
  | 'withdrawal.failed';

const MAX_ATTEMPTS_DEFAULT = 10;
const DELIVERY_TIMEOUT_MS = 10_000;

interface DeliveryRow {
  id: string;
  endpoint_id: string;
  event_type: string;
  payload: Record<string, unknown>;
  attempt_count: number;
  url: string;
  secret_encrypted: string;
  is_active: boolean;
}

@Injectable()
export class WebhooksService {
  private readonly logger = new Logger(WebhooksService.name);

  constructor(
    private readonly db: DatabaseService,
    private readonly queues: QueueService,
    private readonly metrics: MetricsService,
    @Inject(APP_CONFIG) private readonly config: AppConfig,
  ) {}

  /** Fan out an event to every active endpoint subscribed to it. */
  async emit(
    merchantId: string,
    event: WebhookEvent,
    payload: Record<string, unknown>,
  ): Promise<void> {
    const endpoints = await this.db.query<{ id: string }>(
      `SELECT id FROM webhook_endpoints
        WHERE merchant_id = $1 AND is_active AND $2 = ANY(events)`,
      [merchantId, event],
    );
    for (const endpoint of endpoints) {
      const delivery = await this.db.queryOne<{ id: string }>(
        `INSERT INTO webhook_deliveries (endpoint_id, event_type, payload)
         VALUES ($1, $2, $3) RETURNING id`,
        [endpoint.id, event, JSON.stringify({ event, createdAt: new Date().toISOString(), data: payload })],
      );
      if (delivery) {
        await this.queues.add('webhooks-deliver', 'deliver', { deliveryId: delivery.id });
      }
    }
  }

  /** Executes one delivery attempt. Called by the queue processor. */
  async deliver(deliveryId: string): Promise<void> {
    const row = await this.db.queryOne<DeliveryRow>(
      `SELECT d.id, d.endpoint_id, d.event_type, d.payload, d.attempt_count,
              e.url, e.secret_encrypted, e.is_active
         FROM webhook_deliveries d
         JOIN webhook_endpoints e ON e.id = d.endpoint_id
        WHERE d.id = $1 AND d.status IN ('PENDING', 'FAILED')`,
      [deliveryId],
    );
    if (!row) return;
    if (!row.is_active) {
      await this.db.query(
        `UPDATE webhook_deliveries SET status = 'DEAD', last_error = 'endpoint deactivated' WHERE id = $1`,
        [deliveryId],
      );
      return;
    }

    const maxAttempts = await this.maxAttempts();
    const body = JSON.stringify(row.payload);
    const timestamp = Date.now().toString();
    const secret = decryptSecret(row.secret_encrypted, this.config.appEncryptionKey);
    const signature = hmacSha256Hex(secret, `${timestamp}.${body}`);

    let responseCode: number | null = null;
    let error: string | null = null;
    try {
      await assertSafeWebhookUrl(row.url);
      const controller = new AbortController();
      const timer = setTimeout(() => controller.abort(), DELIVERY_TIMEOUT_MS);
      try {
        const response = await fetch(row.url, {
          method: 'POST',
          headers: {
            'content-type': 'application/json',
            'user-agent': 'orizen-gateway/1.0',
            'x-orizen-event': row.event_type,
            'x-orizen-timestamp': timestamp,
            'x-orizen-signature': `v1=${signature}`,
          },
          body,
          signal: controller.signal,
          redirect: 'error',
        });
        responseCode = response.status;
        if (response.status < 200 || response.status >= 300) {
          error = `HTTP ${response.status}`;
        }
      } finally {
        clearTimeout(timer);
      }
    } catch (err) {
      error = (err as Error).message.slice(0, 500);
    }

    if (error === null) {
      await this.db.query(
        `UPDATE webhook_deliveries
            SET status = 'DELIVERED', attempt_count = attempt_count + 1,
                last_response_code = $2, delivered_at = now()
          WHERE id = $1`,
        [deliveryId, responseCode],
      );
      this.metrics.webhookDeliveries.labels('delivered').inc();
      return;
    }

    const attempts = row.attempt_count + 1;
    if (attempts >= maxAttempts) {
      await this.db.query(
        `UPDATE webhook_deliveries
            SET status = 'DEAD', attempt_count = $2, last_response_code = $3, last_error = $4
          WHERE id = $1`,
        [deliveryId, attempts, responseCode, error],
      );
      this.metrics.webhookDeliveries.labels('dead').inc();
      this.logger.warn(`delivery ${deliveryId} dead after ${attempts} attempts: ${error}`);
      return;
    }
    // exponential backoff: 30s, 60s, 2m, 4m ... capped at 1h
    const delaySeconds = Math.min(30 * 2 ** (attempts - 1), 3600);
    await this.db.query(
      `UPDATE webhook_deliveries
          SET status = 'FAILED', attempt_count = $2, last_response_code = $3, last_error = $4,
              next_attempt_at = now() + make_interval(secs => $5)
        WHERE id = $1`,
      [deliveryId, attempts, responseCode, error, delaySeconds],
    );
    this.metrics.webhookDeliveries.labels('failed').inc();
    await this.queues.add(
      'webhooks-deliver',
      'deliver',
      { deliveryId },
      { delay: delaySeconds * 1000 },
    );
  }

  /** Requeue anything due that lost its queue job (crash recovery). */
  async requeueDue(): Promise<void> {
    const due = await this.db.query<{ id: string }>(
      `SELECT id FROM webhook_deliveries
        WHERE status IN ('PENDING', 'FAILED') AND next_attempt_at <= now()
        LIMIT 200`,
    );
    for (const row of due) {
      await this.queues.add('webhooks-deliver', 'deliver', { deliveryId: row.id });
    }
  }

  private async maxAttempts(): Promise<number> {
    const row = await this.db.queryOne<{ value: number }>(
      `SELECT (value)::int AS value FROM settings WHERE key = 'webhook.max_attempts'`,
    );
    return row?.value ?? MAX_ATTEMPTS_DEFAULT;
  }
}
