import {
  BadRequestException,
  ConflictException,
  Injectable,
  Logger,
  NotFoundException,
} from '@nestjs/common';
import * as bitcoin from 'bitcoinjs-lib';
import { DatabaseService } from '../../database/database.service';
import { LedgerService } from '../ledger/ledger.service';
import { AdapterRegistry } from '../blockchain/adapter.registry';
import {
  EvmChainAdapter,
  TronChainAdapter,
  UtxoChainAdapter,
  XrpChainAdapter,
} from '../blockchain/chain-adapter.interface';
import { WalletsService } from '../wallets/wallets.service';
import { SignerClientService } from '../wallets/signer-client.service';
import { WebhooksService } from '../webhooks/webhooks.service';
import { AuditService } from '../audit/audit.service';
import { MetricsService } from '../metrics/metrics.service';
import { derivationPathFor } from '../wallets/hd.util';
import { LITECOIN_NETWORK } from '../blockchain/adapters/litecoin/litecoin.network';
import { AssetCode, Chain } from '../../common/types';
import { decimalToBaseUnits } from '../../common/utils/base-units.util';

const ERC20_TRANSFER_SELECTOR = '0xa9059cbb';
const ERC20_GAS_LIMIT = 90_000n;
const UTXO_DUST = 546n;
// How many times a withdrawal may be put back to APPROVED while it waits for an
// on-demand sweep to move deposit funds into the treasury. At ~60s/drive tick this
// is a few hours - long enough for the slowest sweep to confirm, then it fails safe.
const MAX_WITHDRAWAL_DEFERS = 180;

export interface WithdrawalRow {
  id: string;
  merchant_id: string;
  asset_code: AssetCode;
  amount: string;
  destination_address: string;
  destination_tag: string | null;
  status: string;
  requires_admin_approval: boolean;
  txid: string | null;
}

@Injectable()
export class WithdrawalsService {
  private readonly logger = new Logger(WithdrawalsService.name);

  constructor(
    private readonly db: DatabaseService,
    private readonly ledger: LedgerService,
    private readonly adapters: AdapterRegistry,
    private readonly wallets: WalletsService,
    private readonly signer: SignerClientService,
    private readonly webhooks: WebhooksService,
    private readonly audit: AuditService,
    private readonly metrics: MetricsService,
  ) {}

  /** Merchant-facing: lock funds and queue the withdrawal. */
  async request(input: {
    merchantId: string;
    assetCode: AssetCode;
    amountDecimal: string;
    destinationAddress: string;
    destinationTag?: number | null;
    idempotencyKey?: string | null;
    ip?: string | null;
    actorType: 'MERCHANT' | 'API_KEY';
    actorId: string;
    batchId?: string | null;
    refundInvoiceId?: string | null;
  }): Promise<WithdrawalRow> {
    const asset = await this.db.queryOne<{ chain: Chain; enabled: boolean; decimals: number }>(
      `SELECT chain, enabled, decimals FROM assets WHERE code = $1`,
      [input.assetCode],
    );
    if (!asset?.enabled) throw new BadRequestException(`asset ${input.assetCode} not available`);
    const adapter = this.adapters.forChain(asset.chain);
    if (!adapter.validateAddress(input.destinationAddress)) {
      throw new BadRequestException('invalid destination address');
    }
    let amount: bigint;
    try {
      amount = BigInt(decimalToBaseUnits(input.amountDecimal, asset.decimals));
    } catch (err) {
      throw new BadRequestException((err as Error).message);
    }
    if (amount <= 0n) throw new BadRequestException('amount must be positive');

    // refuse withdrawals to our own deposit addresses (circular flows)
    const own = await this.db.queryOne(
      `SELECT 1 AS x FROM wallet_addresses WHERE address = $1`,
      [input.destinationAddress],
    );
    if (own) throw new BadRequestException('destination is a gateway-owned address');

    const threshold = await this.adminThreshold(input.assetCode);
    const requiresApproval = amount >= threshold;

    const riskFlags: string[] = [];
    if (requiresApproval) riskFlags.push('over_admin_threshold');
    const recent = await this.db.queryOne<{ n: string }>(
      `SELECT COUNT(*)::text AS n FROM withdrawals
        WHERE merchant_id = $1 AND created_at > now() - interval '1 hour'`,
      [input.merchantId],
    );
    if (Number(recent?.n ?? 0) >= 10) riskFlags.push('velocity_10_per_hour');

    const withdrawal = await this.db.tx(async (client) => {
      if (input.idempotencyKey) {
        const existing = await client.query<WithdrawalRow>(
          `SELECT id, merchant_id, asset_code, amount::text, destination_address,
                  destination_tag::text, status, requires_admin_approval, txid
             FROM withdrawals WHERE merchant_id = $1 AND idempotency_key = $2`,
          [input.merchantId, input.idempotencyKey],
        );
        if (existing.rows[0]) return existing.rows[0];
      }

      const available = BigInt(
        await this.ledger.balanceOf(client, input.merchantId, input.assetCode, 'MERCHANT_AVAILABLE'),
      );
      if (available < amount) {
        throw new ConflictException(
          `insufficient available balance: have ${available}, need ${amount}`,
        );
      }

      const inserted = await client.query<WithdrawalRow>(
        `INSERT INTO withdrawals
           (merchant_id, asset_code, amount, destination_address, destination_tag,
            status, risk_flags, requires_admin_approval, idempotency_key, requested_ip,
            batch_id, refund_invoice_id)
         VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, $10, $11, $12)
         RETURNING id, merchant_id, asset_code, amount::text, destination_address,
                   destination_tag::text, status, requires_admin_approval, txid`,
        [
          input.merchantId,
          input.assetCode,
          amount.toString(),
          input.destinationAddress,
          input.destinationTag ?? null,
          requiresApproval ? 'PENDING' : 'APPROVED',
          JSON.stringify(riskFlags),
          requiresApproval,
          input.idempotencyKey ?? null,
          input.ip ?? null,
          input.batchId ?? null,
          input.refundInvoiceId ?? null,
        ],
      );
      const row = inserted.rows[0];

      const availableAcc = await this.ledger.ensureAccount(
        client, input.merchantId, input.assetCode, 'MERCHANT_AVAILABLE');
      const lockedAcc = await this.ledger.ensureAccount(
        client, input.merchantId, input.assetCode, 'MERCHANT_LOCKED');
      await this.ledger.postJournal(client, {
        journalType: 'WITHDRAWAL_LOCK',
        referenceType: 'withdrawal',
        referenceId: row.id,
        description: `lock for withdrawal to ${input.destinationAddress}`,
        entries: [
          { accountId: availableAcc, direction: 'DEBIT', amount: amount.toString(), assetCode: input.assetCode },
          { accountId: lockedAcc, direction: 'CREDIT', amount: amount.toString(), assetCode: input.assetCode },
        ],
      });
      await this.audit.log(
        {
          actorType: input.actorType,
          actorId: input.actorId,
          action: 'withdrawal.requested',
          resourceType: 'withdrawal',
          resourceId: row.id,
          ip: input.ip,
          metadata: { asset: input.assetCode, amount: amount.toString(), riskFlags },
        },
        client,
      );
      return row;
    });
    this.metrics.withdrawalsTotal.labels(input.assetCode, withdrawal.status.toLowerCase()).inc();
    return withdrawal;
  }

  async decide(withdrawalId: string, adminId: string, approve: boolean, ip?: string): Promise<void> {
    await this.db.tx(async (client) => {
      const result = await client.query<WithdrawalRow>(
        `SELECT id, merchant_id, asset_code, amount::text, destination_address,
                destination_tag::text, status, requires_admin_approval, txid
           FROM withdrawals WHERE id = $1 FOR UPDATE`,
        [withdrawalId],
      );
      const withdrawal = result.rows[0];
      if (!withdrawal) throw new NotFoundException('withdrawal not found');
      if (withdrawal.status !== 'PENDING') {
        throw new ConflictException(`withdrawal is ${withdrawal.status}, not PENDING`);
      }
      if (approve) {
        await client.query(
          `UPDATE withdrawals SET status = 'APPROVED', approved_by = $2 WHERE id = $1`,
          [withdrawalId, adminId],
        );
      } else {
        await client.query(
          `UPDATE withdrawals SET status = 'REJECTED', approved_by = $2 WHERE id = $1`,
          [withdrawalId, adminId],
        );
        await this.release(client, withdrawal, 'WITHDRAWAL_RELEASE', 'admin rejection');
      }
      await this.audit.log(
        {
          actorType: 'ADMIN',
          actorId: adminId,
          action: approve ? 'withdrawal.approved' : 'withdrawal.rejected',
          resourceType: 'withdrawal',
          resourceId: withdrawalId,
          ip,
        },
        client,
      );
    });
  }

  /** APPROVED withdrawals waiting to be signed/broadcast (drives the worker retry loop). */
  async approvedIds(): Promise<Array<{ id: string; asset_code: AssetCode }>> {
    return this.db.query<{ id: string; asset_code: AssetCode }>(
      `SELECT id, asset_code FROM withdrawals WHERE status = 'APPROVED' ORDER BY created_at LIMIT 200`,
    );
  }

  /** Worker: sign + broadcast an APPROVED withdrawal. */
  async processApproved(withdrawalId: string): Promise<void> {
    const claimed = await this.db.queryOne<WithdrawalRow & { attempts: number }>(
      `UPDATE withdrawals SET status = 'SIGNING'
        WHERE id = $1 AND status = 'APPROVED'
        RETURNING id, merchant_id, asset_code, amount::text, destination_address,
                  destination_tag::text, status, requires_admin_approval, txid, attempts`,
      [withdrawalId],
    );
    if (!claimed) return;

    try {
      const asset = await this.db.queryOne<{ chain: Chain }>(
        `SELECT chain FROM assets WHERE code = $1`,
        [claimed.asset_code],
      );
      const chain = (asset as { chain: Chain }).chain;
      const { txid, fee } = await this.buildSignBroadcast(chain, claimed);
      await this.db.query(
        `UPDATE withdrawals SET status = 'BROADCAST', txid = $2, network_fee = $3,
                broadcast_at = now() WHERE id = $1`,
        [withdrawalId, txid, fee?.toString() ?? null],
      );
      this.metrics.withdrawalsTotal.labels(claimed.asset_code, 'broadcast').inc();
      await this.webhooks.emit(claimed.merchant_id, 'withdrawal.broadcast', {
        withdrawalId,
        asset: claimed.asset_code,
        amount: claimed.amount,
        txid,
      });
    } catch (err) {
      const message = (err as Error).message.slice(0, 500);
      // The funds exist (the ledger locked them) but haven't been swept from the
      // deposit addresses into the treasury yet. Don't fail - put the withdrawal
      // back to APPROVED so the drive job gathers funds and retries. The locked
      // balance is untouched. Only give up after MAX_WITHDRAWAL_DEFERS.
      const waitingForFunds = /treasury has insufficient/i.test(message);
      if (waitingForFunds && claimed.attempts < MAX_WITHDRAWAL_DEFERS) {
        this.logger.log(
          `withdrawal ${withdrawalId} awaiting swept funds (attempt ${claimed.attempts + 1}): ${message}`,
        );
        await this.db.query(
          `UPDATE withdrawals SET status = 'APPROVED', attempts = attempts + 1, error = $2 WHERE id = $1`,
          [withdrawalId, message],
        );
        return;
      }
      this.logger.error(`withdrawal ${withdrawalId} failed: ${message}`);
      await this.db.tx(async (client) => {
        await client.query(
          `UPDATE withdrawals SET status = 'FAILED', error = $2 WHERE id = $1`,
          [withdrawalId, message],
        );
        await this.release(client, claimed, 'WITHDRAWAL_RELEASE', `failure: ${message}`);
      });
      this.metrics.withdrawalsTotal.labels(claimed.asset_code, 'failed').inc();
      await this.webhooks.emit(claimed.merchant_id, 'withdrawal.failed', {
        withdrawalId,
        asset: claimed.asset_code,
        amount: claimed.amount,
        error: message,
      });
    }
  }

  /** Available (withdrawable) balance for a merchant+asset, from the ledger cache. */
  async availableBalance(merchantId: string, assetCode: AssetCode): Promise<string> {
    const row = await this.db.queryOne<{ balance: string }>(
      `SELECT COALESCE(b.balance, '0') AS balance
         FROM ledger_accounts a
         LEFT JOIN account_balances b ON b.account_id = a.id
        WHERE a.merchant_id = $1 AND a.asset_code = $2 AND a.type = 'MERCHANT_AVAILABLE'`,
      [merchantId, assetCode],
    );
    return row?.balance ?? '0';
  }

  /**
   * Conservative network-fee estimate in the asset's base units, used to compute a
   * "withdraw everything" amount. Tokens (USDT/USDC) return 0 - their network fee is
   * paid in the chain's native coin from the treasury, never out of the token amount.
   */
  async estimateWithdrawalFeeBaseUnits(assetCode: AssetCode): Promise<bigint> {
    const asset = await this.db.queryOne<{ chain: Chain }>(
      `SELECT chain FROM assets WHERE code = $1`,
      [assetCode],
    );
    if (!asset) return 0n;
    switch (asset.chain) {
      case 'bitcoin':
      case 'litecoin': {
        const adapter = this.adapters.forChain(asset.chain) as UtxoChainAdapter;
        const feeRate = BigInt(await adapter.estimateFeeRate());
        return feeRate * 300n; // ~3 inputs + 2 outputs, rounded up
      }
      case 'ethereum': {
        if (assetCode !== 'ETH') return 0n;
        const adapter = this.adapters.forChain('ethereum') as EvmChainAdapter;
        const fees = await adapter.getFeeData();
        return 21_000n * BigInt(fees.maxFeePerGas);
      }
      case 'xrp':
        return 100n; // ~100 drops, well above the 12-drop base fee
      case 'tron':
        return 0n;
      default:
        return 0n;
    }
  }

  /** Largest amount the merchant can withdraw of an asset (available minus est. fee). */
  async maxWithdrawable(merchantId: string, assetCode: AssetCode): Promise<string> {
    const available = BigInt(await this.availableBalance(merchantId, assetCode));
    const fee = await this.estimateWithdrawalFeeBaseUnits(assetCode);
    const max = available > fee ? available - fee : 0n;
    return max.toString();
  }

  /** Worker: settle BROADCAST withdrawals that reached confirmation depth. */
  async trackBroadcast(): Promise<void> {
    const pending = await this.db.query<WithdrawalRow & { min_confirmations: number; chain: Chain; network_fee: string | null }>(
      `SELECT w.id, w.merchant_id, w.asset_code, w.amount::text, w.destination_address,
              w.destination_tag::text, w.status, w.requires_admin_approval, w.txid,
              w.network_fee::text, a.min_confirmations, a.chain
         FROM withdrawals w JOIN assets a ON a.code = w.asset_code
        WHERE w.status = 'BROADCAST' AND w.txid IS NOT NULL`,
    );
    for (const withdrawal of pending) {
      const adapter = this.adapters.forChain(withdrawal.chain);
      const status = await adapter.getTransactionStatus(withdrawal.txid as string);
      if (!status.exists) {
        await this.db.tx(async (client) => {
          await client.query(
            `UPDATE withdrawals SET status = 'FAILED', error = 'transaction vanished' WHERE id = $1`,
            [withdrawal.id],
          );
          await this.release(client, withdrawal, 'WITHDRAWAL_RELEASE', 'tx vanished after broadcast');
        });
        await this.webhooks.emit(withdrawal.merchant_id, 'withdrawal.failed', {
          withdrawalId: withdrawal.id,
          asset: withdrawal.asset_code,
          error: 'transaction vanished',
        });
        continue;
      }
      if (status.confirmations < withdrawal.min_confirmations) continue;

      await this.db.tx(async (client) => {
        await client.query(
          `UPDATE withdrawals SET status = 'CONFIRMED', confirmed_at = now() WHERE id = $1`,
          [withdrawal.id],
        );
        const lockedAcc = await this.ledger.ensureAccount(
          client, withdrawal.merchant_id, withdrawal.asset_code, 'MERCHANT_LOCKED');
        const externalAcc = await this.ledger.ensureAccount(
          client, null, withdrawal.asset_code, 'EXTERNAL_WITHDRAWALS');
        const feesAcc = await this.ledger.ensureAccount(
          client, null, withdrawal.asset_code, 'GATEWAY_FEES');
        const fee = withdrawal.network_fee ? BigInt(withdrawal.network_fee) : 0n;
        await this.ledger.postJournal(client, {
          journalType: 'WITHDRAWAL_EXECUTE',
          referenceType: 'withdrawal',
          referenceId: withdrawal.id,
          description: `withdrawal ${withdrawal.txid} confirmed`,
          entries: [
            { accountId: lockedAcc, direction: 'DEBIT', amount: withdrawal.amount, assetCode: withdrawal.asset_code },
            ...(fee > 0n
              ? [{ accountId: feesAcc, direction: 'DEBIT' as const, amount: fee.toString(), assetCode: withdrawal.asset_code }]
              : []),
            { accountId: externalAcc, direction: 'CREDIT', amount: (BigInt(withdrawal.amount) + fee).toString(), assetCode: withdrawal.asset_code },
          ],
        });
      });
      this.metrics.withdrawalsTotal.labels(withdrawal.asset_code, 'confirmed').inc();
      await this.webhooks.emit(withdrawal.merchant_id, 'withdrawal.confirmed', {
        withdrawalId: withdrawal.id,
        asset: withdrawal.asset_code,
        amount: withdrawal.amount,
        txid: withdrawal.txid,
      });
    }
  }

  private async release(
    client: Parameters<LedgerService['ensureAccount']>[0],
    withdrawal: WithdrawalRow,
    journalType: 'WITHDRAWAL_RELEASE',
    reason: string,
  ): Promise<void> {
    const lockedAcc = await this.ledger.ensureAccount(
      client, withdrawal.merchant_id, withdrawal.asset_code, 'MERCHANT_LOCKED');
    const availableAcc = await this.ledger.ensureAccount(
      client, withdrawal.merchant_id, withdrawal.asset_code, 'MERCHANT_AVAILABLE');
    await this.ledger.postJournal(client, {
      journalType,
      referenceType: 'withdrawal',
      referenceId: withdrawal.id,
      description: `release locked funds: ${reason}`,
      entries: [
        { accountId: lockedAcc, direction: 'DEBIT', amount: withdrawal.amount, assetCode: withdrawal.asset_code },
        { accountId: availableAcc, direction: 'CREDIT', amount: withdrawal.amount, assetCode: withdrawal.asset_code },
      ],
    });
  }

  private async adminThreshold(assetCode: AssetCode): Promise<bigint> {
    const row = await this.db.queryOne<{ value: Record<string, string> }>(
      `SELECT value FROM settings WHERE key = 'withdrawal.admin_approval_threshold_base_units'`,
    );
    return BigInt(row?.value?.[assetCode] ?? '0');
  }

  // -- per-chain build + sign + broadcast --------------------------------------
  private async buildSignBroadcast(
    chain: Chain,
    withdrawal: WithdrawalRow,
  ): Promise<{ txid: string; fee: bigint | null }> {
    const treasury = await this.wallets.treasuryWallet(chain);
    if (!treasury?.address) throw new Error(`treasury wallet for ${chain} not initialized`);
    const amount = BigInt(withdrawal.amount);
    const walletRef = treasury.metadata.walletRef as string;

    switch (chain) {
      case 'bitcoin':
      case 'litecoin': {
        const adapter = this.adapters.forChain(chain) as UtxoChainAdapter;
        const utxos = (await adapter.listUnspent(1)).filter((u) => u.address === treasury.address);
        const feeRate = BigInt(await adapter.estimateFeeRate());
        const selected: typeof utxos = [];
        let inputSum = 0n;
        let fee = 0n;
        for (const utxo of utxos) {
          selected.push(utxo);
          inputSum += BigInt(utxo.amountBaseUnits);
          const vsize = BigInt(Math.ceil(10.5 + selected.length * 68 + 2 * 31));
          fee = feeRate * vsize;
          if (inputSum >= amount + fee) break;
        }
        if (inputSum < amount + fee) {
          throw new Error(`treasury has insufficient confirmed funds (${inputSum} < ${amount + fee})`);
        }
        const network = chain === 'bitcoin' ? bitcoin.networks.bitcoin : LITECOIN_NETWORK;
        const psbt = new bitcoin.Psbt({ network });
        for (const utxo of selected) {
          psbt.addInput({
            hash: utxo.txid,
            index: utxo.vout,
            witnessUtxo: {
              script: Buffer.from(utxo.scriptPubKeyHex, 'hex'),
              value: Number(BigInt(utxo.amountBaseUnits)),
            },
          });
        }
        psbt.addOutput({ address: withdrawal.destination_address, value: Number(amount) });
        const change = inputSum - amount - fee;
        if (change > UTXO_DUST) {
          psbt.addOutput({ address: treasury.address, value: Number(change) });
        }
        const signed = await this.signer.sign({
          kind: 'psbt',
          chain,
          walletRef,
          psbtBase64: psbt.toBase64(),
          inputPaths: selected.map(() => derivationPathFor(chain, 0)),
          purpose: 'withdrawal',
          destination: withdrawal.destination_address,
          amountBaseUnits: amount.toString(),
          assetCode: withdrawal.asset_code,
        });
        const txid = await adapter.broadcast(signed);
        return { txid, fee };
      }

      case 'ethereum': {
        const adapter = this.adapters.forChain('ethereum') as EvmChainAdapter;
        const fees = await adapter.getFeeData();
        const isToken = withdrawal.asset_code !== 'ETH';
        const contract = isToken
          ? (
              await this.db.queryOne<{ contract_address: string }>(
                `SELECT contract_address FROM assets WHERE code = $1`,
                [withdrawal.asset_code],
              )
            )?.contract_address
          : null;
        const gasLimit = isToken ? ERC20_GAS_LIMIT : 21_000n;
        const gasCost = gasLimit * BigInt(fees.maxFeePerGas);
        const nativeBalance = BigInt(await adapter.getNativeBalance(treasury.address));
        if (nativeBalance < (isToken ? gasCost : amount + gasCost)) {
          throw new Error('treasury has insufficient ETH for value+gas');
        }
        if (isToken) {
          const tokenBalance = BigInt(await adapter.getTokenBalance(treasury.address, contract as string));
          if (tokenBalance < amount) throw new Error('treasury has insufficient token balance');
        }
        const signed = await this.signer.sign({
          kind: 'eth-tx',
          chain: 'ethereum',
          walletRef,
          path: derivationPathFor('ethereum', 0),
          tx: {
            to: isToken ? (contract as string) : withdrawal.destination_address,
            value: isToken ? '0' : amount.toString(),
            data: isToken
              ? ERC20_TRANSFER_SELECTOR +
                withdrawal.destination_address.slice(2).padStart(64, '0') +
                amount.toString(16).padStart(64, '0')
              : '0x',
            nonce: await adapter.getTransactionCount(treasury.address),
            gasLimit: gasLimit.toString(),
            maxFeePerGas: fees.maxFeePerGas,
            maxPriorityFeePerGas: fees.maxPriorityFeePerGas,
            chainId: await adapter.getChainId(),
          },
          purpose: 'withdrawal',
          destination: withdrawal.destination_address,
          amountBaseUnits: amount.toString(),
          assetCode: withdrawal.asset_code,
        });
        const txid = await adapter.broadcast(signed);
        return { txid, fee: isToken ? null : gasCost };
      }

      case 'xrp': {
        const adapter = this.adapters.forChain('xrp') as XrpChainAdapter;
        const txJson = await adapter.buildPayment(
          treasury.address,
          withdrawal.destination_address,
          amount.toString(),
          withdrawal.destination_tag !== null ? Number(withdrawal.destination_tag) : null,
        );
        const signed = await this.signer.sign({
          kind: 'xrp-tx',
          chain: 'xrp',
          walletRef,
          txJson,
          purpose: 'withdrawal',
          destination: withdrawal.destination_address,
          amountBaseUnits: amount.toString(),
          assetCode: 'XRP',
        });
        const txid = await adapter.broadcast(signed);
        return { txid, fee: BigInt(String(txJson.Fee ?? '12')) };
      }

      case 'tron': {
        const adapter = this.adapters.forChain('tron') as TronChainAdapter;
        const contract = (
          await this.db.queryOne<{ contract_address: string }>(
            `SELECT contract_address FROM assets WHERE code = $1`,
            [withdrawal.asset_code],
          )
        )?.contract_address as string;
        const tokenBalance = BigInt(await adapter.getTrc20Balance(treasury.address, contract));
        if (tokenBalance < amount) throw new Error('treasury has insufficient USDT balance');
        const trxBalance = BigInt(await adapter.getTrxBalance(treasury.address));
        if (trxBalance < 30_000_000n) {
          throw new Error('treasury has insufficient TRX for energy - fund the treasury with TRX');
        }
        const unsigned = await adapter.buildTrc20Transfer(
          treasury.address,
          withdrawal.destination_address,
          amount.toString(),
          contract,
        );
        const signed = await this.signer.sign({
          kind: 'tron-tx',
          chain: 'tron',
          walletRef,
          path: derivationPathFor('tron', 0),
          tx: unsigned,
          purpose: 'withdrawal',
          destination: withdrawal.destination_address,
          amountBaseUnits: amount.toString(),
          assetCode: withdrawal.asset_code,
        });
        const txid = await adapter.broadcast(signed);
        return { txid, fee: null };
      }
    }
  }
}
