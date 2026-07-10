import { BadRequestException, Injectable, NotFoundException } from '@nestjs/common';
import { DatabaseService } from '../../database/database.service';
import { QueueService } from '../../redis/queue.module';
import { AuditService } from '../audit/audit.service';
import { WithdrawalsService } from './withdrawals.service';
import { AssetCode } from '../../common/types';

export interface PayoutItemInput {
  assetCode: AssetCode;
  amount: string;
  destinationAddress: string;
  destinationTag?: number;
  reference?: string;
}

export interface PayoutItemResult {
  index: number;
  reference?: string;
  status: 'ACCEPTED' | 'REJECTED';
  withdrawalId?: string;
  withdrawalStatus?: string;
  error?: string;
}

const MAX_BATCH_ITEMS = 100;

/**
 * Mass payouts: one call -> many withdrawals (payroll, affiliates, suppliers).
 * Each item runs through the normal withdrawal pipeline (balance lock, risk
 * checks, admin-approval threshold, signer policy) - batching adds convenience,
 * never a security bypass. Item failures don't abort the batch.
 */
@Injectable()
export class PayoutsService {
  constructor(
    private readonly db: DatabaseService,
    private readonly withdrawals: WithdrawalsService,
    private readonly queues: QueueService,
    private readonly audit: AuditService,
  ) {}

  async createBatch(input: {
    merchantId: string;
    items: PayoutItemInput[];
    label?: string;
    idempotencyKey?: string;
    actorType: 'MERCHANT' | 'API_KEY';
    actorId: string;
    ip?: string | null;
  }): Promise<{ batchId: string; accepted: number; rejected: number; items: PayoutItemResult[] }> {
    if (input.items.length === 0) throw new BadRequestException('batch has no items');
    if (input.items.length > MAX_BATCH_ITEMS) {
      throw new BadRequestException(`batch exceeds ${MAX_BATCH_ITEMS} items`);
    }

    if (input.idempotencyKey) {
      const existing = await this.db.queryOne<{ id: string }>(
        `SELECT id FROM payout_batches WHERE merchant_id = $1 AND idempotency_key = $2`,
        [input.merchantId, input.idempotencyKey],
      );
      if (existing) return this.getBatch(input.merchantId, existing.id);
    }

    const batch = await this.db.queryOne<{ id: string }>(
      `INSERT INTO payout_batches (merchant_id, label, idempotency_key, total_items)
       VALUES ($1, $2, $3, $4) RETURNING id`,
      [input.merchantId, input.label ?? null, input.idempotencyKey ?? null, input.items.length],
    );
    const batchId = (batch as { id: string }).id;

    const results: PayoutItemResult[] = [];
    for (let index = 0; index < input.items.length; index++) {
      const item = input.items[index];
      try {
        const row = await this.withdrawals.request({
          merchantId: input.merchantId,
          assetCode: item.assetCode,
          amountDecimal: item.amount,
          destinationAddress: item.destinationAddress,
          destinationTag: item.destinationTag ?? null,
          idempotencyKey: input.idempotencyKey ? `${input.idempotencyKey}:${index}` : null,
          ip: input.ip,
          actorType: input.actorType,
          actorId: input.actorId,
          batchId,
        });
        if (row.status === 'APPROVED') {
          await this.queues.add('withdrawals-process', 'process', { withdrawalId: row.id });
        }
        results.push({
          index,
          reference: item.reference,
          status: 'ACCEPTED',
          withdrawalId: row.id,
          withdrawalStatus: row.status,
        });
      } catch (err) {
        results.push({
          index,
          reference: item.reference,
          status: 'REJECTED',
          error: (err as Error).message,
        });
      }
    }

    await this.audit.log({
      actorType: input.actorType,
      actorId: input.actorId,
      action: 'payout_batch.created',
      resourceType: 'payout_batch',
      resourceId: batchId,
      ip: input.ip,
      metadata: {
        items: input.items.length,
        accepted: results.filter((r) => r.status === 'ACCEPTED').length,
      },
    });

    return {
      batchId,
      accepted: results.filter((r) => r.status === 'ACCEPTED').length,
      rejected: results.filter((r) => r.status === 'REJECTED').length,
      items: results,
    };
  }

  async getBatch(
    merchantId: string,
    batchId: string,
  ): Promise<{ batchId: string; accepted: number; rejected: number; items: PayoutItemResult[] }> {
    const batch = await this.db.queryOne<{ id: string; total_items: number }>(
      `SELECT id, total_items FROM payout_batches WHERE id = $1 AND merchant_id = $2`,
      [batchId, merchantId],
    );
    if (!batch) throw new NotFoundException('payout batch not found');
    const rows = await this.db.query<{ id: string; status: string }>(
      `SELECT id, status FROM withdrawals WHERE batch_id = $1 ORDER BY created_at`,
      [batchId],
    );
    return {
      batchId,
      accepted: rows.length,
      rejected: batch.total_items - rows.length,
      items: rows.map((r, index) => ({
        index,
        status: 'ACCEPTED' as const,
        withdrawalId: r.id,
        withdrawalStatus: r.status,
      })),
    };
  }
}
