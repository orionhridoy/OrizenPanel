import { Injectable } from '@nestjs/common';
import {
  Counter,
  Gauge,
  Histogram,
  Registry,
  collectDefaultMetrics,
} from 'prom-client';

@Injectable()
export class MetricsService {
  readonly registry = new Registry();

  readonly httpRequestDuration = new Histogram({
    name: 'orizen_http_request_duration_seconds',
    help: 'HTTP request duration',
    labelNames: ['method', 'route', 'status'] as const,
    buckets: [0.01, 0.05, 0.1, 0.25, 0.5, 1, 2.5, 5],
    registers: [this.registry],
  });

  readonly invoicesCreated = new Counter({
    name: 'orizen_invoices_created_total',
    help: 'Invoices created',
    labelNames: ['asset'] as const,
    registers: [this.registry],
  });

  readonly paymentsDetected = new Counter({
    name: 'orizen_payments_detected_total',
    help: 'Payments first seen (mempool or block)',
    labelNames: ['chain'] as const,
    registers: [this.registry],
  });

  readonly paymentsConfirmed = new Counter({
    name: 'orizen_payments_confirmed_total',
    help: 'Payments fully confirmed',
    labelNames: ['chain'] as const,
    registers: [this.registry],
  });

  readonly paymentAnomalies = new Counter({
    name: 'orizen_payment_anomalies_total',
    help: 'RBF replacements, reorged payments, double-spends, dust',
    labelNames: ['chain', 'kind'] as const,
    registers: [this.registry],
  });

  readonly webhookDeliveries = new Counter({
    name: 'orizen_webhook_deliveries_total',
    help: 'Webhook delivery attempts',
    labelNames: ['status'] as const,
    registers: [this.registry],
  });

  readonly nodeSyncProgress = new Gauge({
    name: 'orizen_node_sync_progress',
    help: 'Chain node sync progress 0..1',
    labelNames: ['chain'] as const,
    registers: [this.registry],
  });

  readonly nodeHeight = new Gauge({
    name: 'orizen_node_height',
    help: 'Chain node best height',
    labelNames: ['chain'] as const,
    registers: [this.registry],
  });

  readonly nodePeers = new Gauge({
    name: 'orizen_node_peers',
    help: 'Chain node peer count',
    labelNames: ['chain'] as const,
    registers: [this.registry],
  });

  readonly engineActive = new Gauge({
    name: 'orizen_payment_engine_active',
    help: '1 when the payment engine is active for a chain',
    labelNames: ['chain'] as const,
    registers: [this.registry],
  });

  readonly reconciliationDrift = new Gauge({
    name: 'orizen_ledger_reconciliation_drift',
    help: 'Accounts whose cached balance differs from SUM(entries) - must be 0',
    registers: [this.registry],
  });

  readonly queueDepth = new Gauge({
    name: 'orizen_queue_depth',
    help: 'Waiting + delayed jobs per queue',
    labelNames: ['queue'] as const,
    registers: [this.registry],
  });

  readonly sweepsTotal = new Counter({
    name: 'orizen_sweeps_total',
    help: 'Treasury sweeps',
    labelNames: ['asset', 'status'] as const,
    registers: [this.registry],
  });

  readonly withdrawalsTotal = new Counter({
    name: 'orizen_withdrawals_total',
    help: 'Withdrawal lifecycle transitions',
    labelNames: ['asset', 'status'] as const,
    registers: [this.registry],
  });

  constructor() {
    collectDefaultMetrics({ register: this.registry, prefix: 'orizen_' });
  }

  async render(): Promise<string> {
    return this.registry.metrics();
  }
}
