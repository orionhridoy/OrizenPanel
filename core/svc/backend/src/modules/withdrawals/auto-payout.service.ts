import { Injectable, Logger } from '@nestjs/common';
import { DatabaseService } from '../../database/database.service';
import { AuditService } from '../audit/audit.service';
import { WithdrawalsService } from './withdrawals.service';
import { AssetCode } from '../../common/types';
import { baseUnitsToDecimal } from '../../common/utils/base-units.util';

interface PayoutTarget {
  address?: string;
  minBaseUnits?: string;
}

interface AutoPayoutMerchant {
  id: string;
  auto_payout_targets: Record<string, PayoutTarget>;
}

/**
 * "Send my balance to my wallet automatically." When a merchant switches on
 * auto-payout and their AVAILABLE balance for an asset crosses the threshold they
 * set, this creates a withdrawal of the whole balance (minus the network fee) to
 * their saved external address. The normal withdrawal pipeline then gathers funds
 * from the deposit addresses and broadcasts - the fee is detected automatically.
 *
 * Safety: opt-in (off by default), the destination is the merchant's own saved
 * address, and it never stacks - if a payout for that asset is already in flight it
 * is skipped until it settles.
 */
@Injectable()
export class AutoPayoutService {
  private readonly logger = new Logger(AutoPayoutService.name);

  constructor(
    private readonly db: DatabaseService,
    private readonly withdrawals: WithdrawalsService,
    private readonly audit: AuditService,
  ) {}

  /** Worker tick: fire any due auto-payouts. */
  async runOnce(): Promise<void> {
    const merchants = await this.db.query<AutoPayoutMerchant>(
      `SELECT id, auto_payout_targets
         FROM merchants
        WHERE auto_payout_enabled = true AND status = 'ACTIVE'`,
    );
    for (const merchant of merchants) {
      const targets = merchant.auto_payout_targets ?? {};
      for (const [assetCode, target] of Object.entries(targets)) {
        try {
          await this.maybePayout(merchant.id, assetCode as AssetCode, target);
        } catch (err) {
          this.logger.warn(
            `auto-payout ${assetCode} for ${merchant.id} skipped: ${(err as Error).message}`,
          );
        }
      }
    }
  }

  private async maybePayout(merchantId: string, assetCode: AssetCode, target: PayoutTarget): Promise<void> {
    const address = (target.address ?? '').trim();
    if (address === '') return;
    const asset = await this.db.queryOne<{ decimals: number; enabled: boolean }>(
      `SELECT decimals, enabled FROM assets WHERE code = $1`,
      [assetCode],
    );
    if (!asset?.enabled) return;

    // Never stack payouts: if one is already in flight for this asset, wait for it.
    const inFlight = await this.db.queryOne(
      `SELECT 1 AS x FROM withdrawals
        WHERE merchant_id = $1 AND asset_code = $2
          AND status IN ('PENDING','APPROVED','SIGNING','BROADCAST')
        LIMIT 1`,
      [merchantId, assetCode],
    );
    if (inFlight) return;

    const available = BigInt(await this.withdrawals.availableBalance(merchantId, assetCode));
    const minBaseUnits = BigInt(target.minBaseUnits && /^\d+$/.test(target.minBaseUnits) ? target.minBaseUnits : '0');
    if (available <= 0n || available < minBaseUnits) return;

    const fee = await this.withdrawals.estimateWithdrawalFeeBaseUnits(assetCode);
    const amount = available > fee ? available - fee : 0n;
    if (amount <= 0n) return;

    const row = await this.withdrawals.request({
      merchantId,
      assetCode,
      amountDecimal: baseUnitsToDecimal(amount.toString(), asset.decimals),
      destinationAddress: address,
      idempotencyKey: `autopayout:${merchantId}:${assetCode}:${Date.now()}`,
      actorType: 'MERCHANT',
      actorId: merchantId,
    });
    await this.audit.log({
      actorType: 'SYSTEM',
      actorId: merchantId,
      action: 'auto_payout.created',
      resourceType: 'withdrawal',
      resourceId: row.id,
      metadata: { asset: assetCode, amount: amount.toString(), address },
    });
    this.logger.log(`auto-payout ${assetCode} ${amount} -> ${address} (withdrawal ${row.id})`);
  }
}
