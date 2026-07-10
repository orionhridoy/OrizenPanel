import { Injectable, Logger } from '@nestjs/common';
import * as bitcoin from 'bitcoinjs-lib';
import { DatabaseService } from '../../database/database.service';
import { AdapterRegistry } from '../blockchain/adapter.registry';
import {
  EvmChainAdapter,
  TronChainAdapter,
  UtxoChainAdapter,
  XrpChainAdapter,
} from '../blockchain/chain-adapter.interface';
import { WalletsService, WalletRow } from '../wallets/wallets.service';
import { SignerClientService } from '../wallets/signer-client.service';
import { LedgerService } from '../ledger/ledger.service';
import { MetricsService } from '../metrics/metrics.service';
import { NodesService } from '../nodes/nodes.service';
import { derivationPathFor } from '../wallets/hd.util';
import { LITECOIN_NETWORK } from '../blockchain/adapters/litecoin/litecoin.network';
import { AssetCode, Chain } from '../../common/types';

const ERC20_TRANSFER_SELECTOR = '0xa9059cbb';
const ERC20_GAS_LIMIT = 90_000n;
const XRP_RESERVE_DROPS = 10_000_000n; // 10 XRP base reserve stays behind
const XRP_FEE_BUFFER_DROPS = 5_000n;
const TRX_SWEEP_GAS_SUN = 30_000_000n; // 30 TRX per TRC20 sweep budget

interface SweepablePayment {
  id: string;
  address_id: string;
  address: string;
  derivation_index: number | null;
  txid: string;
  output_index: number | null;
  amount: string;
}

@Injectable()
export class SweepsService {
  private readonly logger = new Logger(SweepsService.name);

  constructor(
    private readonly db: DatabaseService,
    private readonly adapters: AdapterRegistry,
    private readonly wallets: WalletsService,
    private readonly signer: SignerClientService,
    private readonly ledger: LedgerService,
    private readonly metrics: MetricsService,
    private readonly nodes: NodesService,
  ) {}

  /**
   * Gather confirmed deposit funds into the treasury.
   * `force` skips the min-batch economy gate - used when a withdrawal/auto-payout
   * needs the funds in the treasury now, even if the amount is below the batch size.
   */
  async gatherToTreasury(assetCode: AssetCode): Promise<void> {
    await this.runForAsset(assetCode, true);
  }

  async runForAsset(assetCode: AssetCode, force = false): Promise<void> {
    const asset = await this.db.queryOne<{ chain: Chain; min_confirmations: number; contract_address: string | null }>(
      `SELECT chain, min_confirmations, contract_address FROM assets WHERE code = $1 AND enabled`,
      [assetCode],
    );
    if (!asset || !(await this.nodes.isEngineActive(asset.chain))) return;

    await this.trackBroadcastSweeps(assetCode, asset.chain);

    const deposit = await this.wallets.depositWallet(asset.chain);
    const treasury = await this.wallets.treasuryWallet(asset.chain);
    if (!deposit || !treasury?.address) return;

    const minBatch = await this.minBatch(assetCode);
    const sweepable = await this.sweepablePayments(assetCode);
    if (sweepable.length === 0) return;
    const total = sweepable.reduce((sum, p) => sum + BigInt(p.amount), 0n);
    if (!force && total < minBatch) return;

    try {
      switch (asset.chain) {
        case 'bitcoin':
        case 'litecoin':
          await this.sweepUtxo(asset.chain, assetCode, deposit, treasury, sweepable, asset.min_confirmations);
          break;
        case 'ethereum':
          if (assetCode === 'ETH') {
            await this.sweepEthNative(deposit, treasury, sweepable);
          } else {
            await this.sweepErc20(assetCode, asset.contract_address as string, deposit, treasury, sweepable);
          }
          break;
        case 'xrp':
          await this.sweepXrp(deposit, treasury, sweepable);
          break;
        case 'tron':
          await this.sweepTrc20(assetCode, asset.contract_address as string, deposit, treasury, sweepable);
          break;
      }
    } catch (err) {
      this.metrics.sweepsTotal.labels(assetCode, 'failed').inc();
      this.logger.error(`sweep ${assetCode} failed: ${(err as Error).message}`);
    }
  }

  /** confirmed+credited payments not yet included in any sweep */
  private async sweepablePayments(assetCode: AssetCode): Promise<SweepablePayment[]> {
    return this.db.query<SweepablePayment>(
      `SELECT p.id, p.address_id, wa.address, wa.derivation_index, p.txid, p.output_index, p.amount
         FROM payments p
         JOIN wallet_addresses wa ON wa.id = p.address_id
         JOIN wallets w ON w.id = wa.wallet_id
        WHERE p.asset_code = $1 AND p.status = 'CONFIRMED' AND p.credited
          AND w.type = 'DEPOSIT_HD'
          AND NOT EXISTS (SELECT 1 FROM sweep_inputs si WHERE si.payment_id = p.id)
        ORDER BY p.confirmed_at
        LIMIT 200`,
      [assetCode],
    );
  }

  private async minBatch(assetCode: AssetCode): Promise<bigint> {
    const row = await this.db.queryOne<{ value: Record<string, string> }>(
      `SELECT value FROM settings WHERE key = 'sweep.min_batch_base_units'`,
    );
    return BigInt(row?.value?.[assetCode] ?? '0');
  }

  private async createSweepRow(
    assetCode: AssetCode,
    fromWalletId: string,
    treasuryWalletId: string,
    totalAmount: bigint,
    payments: SweepablePayment[],
  ): Promise<string> {
    return this.db.tx(async (client) => {
      const sweep = await client.query<{ id: string }>(
        `INSERT INTO sweeps (asset_code, from_wallet_id, treasury_wallet_id, status, total_amount)
         VALUES ($1, $2, $3, 'SIGNING', $4) RETURNING id`,
        [assetCode, fromWalletId, treasuryWalletId, totalAmount.toString()],
      );
      for (const payment of payments) {
        await client.query(
          `INSERT INTO sweep_inputs (sweep_id, payment_id, address_id, amount)
           VALUES ($1, $2, $3, $4)`,
          [sweep.rows[0].id, payment.id, payment.address_id, payment.amount],
        );
      }
      return sweep.rows[0].id;
    });
  }

  private async markBroadcast(sweepId: string, txid: string, fee: bigint | null): Promise<void> {
    await this.db.query(
      `UPDATE sweeps SET status = 'BROADCAST', txid = $2, network_fee = $3 WHERE id = $1`,
      [sweepId, txid, fee?.toString() ?? null],
    );
  }

  private async markFailed(sweepId: string, error: string): Promise<void> {
    await this.db.query(`UPDATE sweeps SET status = 'FAILED', error = $2 WHERE id = $1`, [
      sweepId,
      error.slice(0, 500),
    ]);
  }

  // -- UTXO chains -------------------------------------------------------------
  private async sweepUtxo(
    chain: 'bitcoin' | 'litecoin',
    assetCode: AssetCode,
    deposit: WalletRow,
    treasury: WalletRow,
    payments: SweepablePayment[],
    minConf: number,
  ): Promise<void> {
    const adapter = this.adapters.forChain(chain) as UtxoChainAdapter;
    const unspent = await adapter.listUnspent(minConf);
    const byOutpoint = new Map(unspent.map((u) => [`${u.txid}:${u.vout}`, u]));

    const inputs: Array<{ payment: SweepablePayment; utxo: (typeof unspent)[number] }> = [];
    for (const payment of payments) {
      const utxo = byOutpoint.get(`${payment.txid}:${payment.output_index}`);
      if (utxo && payment.derivation_index !== null) inputs.push({ payment, utxo });
    }
    if (inputs.length === 0) return;

    const feeRate = await adapter.estimateFeeRate();
    const vsize = Math.ceil(10.5 + inputs.length * 68 + 31);
    const fee = BigInt(feeRate * vsize);
    const total = inputs.reduce((sum, i) => sum + BigInt(i.utxo.amountBaseUnits), 0n);
    if (total <= fee * 2n) return; // not economical

    const network = chain === 'bitcoin' ? bitcoin.networks.bitcoin : LITECOIN_NETWORK;
    const psbt = new bitcoin.Psbt({ network });
    for (const { utxo } of inputs) {
      psbt.addInput({
        hash: utxo.txid,
        index: utxo.vout,
        witnessUtxo: {
          script: Buffer.from(utxo.scriptPubKeyHex, 'hex'),
          value: Number(BigInt(utxo.amountBaseUnits)),
        },
      });
    }
    psbt.addOutput({
      address: treasury.address as string,
      value: Number(total - fee),
    });
    const inputPaths = inputs.map(({ payment }) =>
      derivationPathFor(chain, payment.derivation_index as number),
    );

    const sweepId = await this.createSweepRow(
      assetCode,
      deposit.id,
      treasury.id,
      total - fee,
      inputs.map((i) => i.payment),
    );
    try {
      const signed = await this.signer.sign({
        kind: 'psbt',
        chain,
        walletRef: deposit.metadata.walletRef as string,
        psbtBase64: psbt.toBase64(),
        inputPaths,
        purpose: 'sweep',
        destination: treasury.address as string,
        amountBaseUnits: (total - fee).toString(),
        assetCode,
      });
      const txid = await adapter.broadcast(signed);
      await this.markBroadcast(sweepId, txid, fee);
      this.metrics.sweepsTotal.labels(assetCode, 'broadcast').inc();
    } catch (err) {
      await this.markFailed(sweepId, (err as Error).message);
      throw err;
    }
  }

  // -- Ethereum native ---------------------------------------------------------
  private async sweepEthNative(
    deposit: WalletRow,
    treasury: WalletRow,
    payments: SweepablePayment[],
  ): Promise<void> {
    const adapter = this.adapters.forChain('ethereum') as EvmChainAdapter;
    const byAddress = groupByAddress(payments);
    for (const [address, group] of byAddress) {
      const index = group[0].derivation_index;
      if (index === null) continue;
      const balance = BigInt(await adapter.getNativeBalance(address));
      const fees = await adapter.getFeeData();
      const gasCost = 21_000n * BigInt(fees.maxFeePerGas);
      if (balance <= gasCost * 2n) continue;
      const value = balance - gasCost;

      const sweepId = await this.createSweepRow('ETH', deposit.id, treasury.id, value, group);
      try {
        const signed = await this.signer.sign({
          kind: 'eth-tx',
          chain: 'ethereum',
          walletRef: deposit.metadata.walletRef as string,
          path: derivationPathFor('ethereum', index),
          tx: {
            to: treasury.address as string,
            value: value.toString(),
            data: '0x',
            nonce: await adapter.getTransactionCount(address),
            gasLimit: '21000',
            maxFeePerGas: fees.maxFeePerGas,
            maxPriorityFeePerGas: fees.maxPriorityFeePerGas,
            chainId: await adapter.getChainId(),
          },
          purpose: 'sweep',
          destination: treasury.address as string,
          amountBaseUnits: value.toString(),
          assetCode: 'ETH',
        });
        const txid = await adapter.broadcast(signed);
        await this.markBroadcast(sweepId, txid, gasCost);
        this.metrics.sweepsTotal.labels('ETH', 'broadcast').inc();
      } catch (err) {
        await this.markFailed(sweepId, (err as Error).message);
        throw err;
      }
    }
  }

  // -- ERC20 (gas top-up from treasury when needed) ----------------------------
  private async sweepErc20(
    assetCode: AssetCode,
    contract: string,
    deposit: WalletRow,
    treasury: WalletRow,
    payments: SweepablePayment[],
  ): Promise<void> {
    const adapter = this.adapters.forChain('ethereum') as EvmChainAdapter;
    const byAddress = groupByAddress(payments);
    for (const [address, group] of byAddress) {
      const index = group[0].derivation_index;
      if (index === null) continue;
      const tokenBalance = BigInt(await adapter.getTokenBalance(address, contract));
      if (tokenBalance === 0n) continue;

      const fees = await adapter.getFeeData();
      const gasNeeded = ERC20_GAS_LIMIT * BigInt(fees.maxFeePerGas);
      const nativeBalance = BigInt(await adapter.getNativeBalance(address));

      if (nativeBalance < gasNeeded) {
        await this.topUpEthGas(adapter, treasury, address, gasNeeded - nativeBalance + gasNeeded / 5n);
        continue; // sweep on a later tick once gas lands
      }

      const data =
        ERC20_TRANSFER_SELECTOR +
        (treasury.address as string).slice(2).padStart(64, '0') +
        tokenBalance.toString(16).padStart(64, '0');

      const sweepId = await this.createSweepRow(assetCode, deposit.id, treasury.id, tokenBalance, group);
      try {
        const signed = await this.signer.sign({
          kind: 'eth-tx',
          chain: 'ethereum',
          walletRef: deposit.metadata.walletRef as string,
          path: derivationPathFor('ethereum', index),
          tx: {
            to: contract,
            value: '0',
            data,
            nonce: await adapter.getTransactionCount(address),
            gasLimit: ERC20_GAS_LIMIT.toString(),
            maxFeePerGas: fees.maxFeePerGas,
            maxPriorityFeePerGas: fees.maxPriorityFeePerGas,
            chainId: await adapter.getChainId(),
          },
          purpose: 'sweep',
          destination: treasury.address as string,
          amountBaseUnits: tokenBalance.toString(),
          assetCode,
        });
        const txid = await adapter.broadcast(signed);
        await this.markBroadcast(sweepId, txid, null);
        this.metrics.sweepsTotal.labels(assetCode, 'broadcast').inc();
      } catch (err) {
        await this.markFailed(sweepId, (err as Error).message);
        throw err;
      }
    }
  }

  private async topUpEthGas(
    adapter: EvmChainAdapter,
    treasury: WalletRow,
    to: string,
    amount: bigint,
  ): Promise<void> {
    const fees = await adapter.getFeeData();
    const signed = await this.signer.sign({
      kind: 'eth-tx',
      chain: 'ethereum',
      walletRef: treasury.metadata.walletRef as string,
      path: derivationPathFor('ethereum', 0),
      tx: {
        to,
        value: amount.toString(),
        data: '0x',
        nonce: await adapter.getTransactionCount(treasury.address as string),
        gasLimit: '21000',
        maxFeePerGas: fees.maxFeePerGas,
        maxPriorityFeePerGas: fees.maxPriorityFeePerGas,
        chainId: await adapter.getChainId(),
      },
      purpose: 'gas-topup',
      destination: to,
      amountBaseUnits: amount.toString(),
      assetCode: 'ETH',
    });
    await adapter.broadcast(signed);
    this.logger.log(`gas top-up ${amount} wei -> ${to}`);
  }

  // -- XRP ---------------------------------------------------------------------
  private async sweepXrp(
    deposit: WalletRow,
    treasury: WalletRow,
    payments: SweepablePayment[],
  ): Promise<void> {
    const adapter = this.adapters.forChain('xrp') as XrpChainAdapter;
    const from = deposit.address as string;
    const balance = BigInt(await adapter.getXrpBalance(from));
    const spendable = balance - XRP_RESERVE_DROPS - XRP_FEE_BUFFER_DROPS;
    if (spendable <= 0n) return;

    const txJson = await adapter.buildPayment(from, treasury.address as string, spendable.toString(), null);
    const sweepId = await this.createSweepRow('XRP', deposit.id, treasury.id, spendable, payments);
    try {
      const signed = await this.signer.sign({
        kind: 'xrp-tx',
        chain: 'xrp',
        walletRef: deposit.metadata.walletRef as string,
        txJson,
        purpose: 'sweep',
        destination: treasury.address as string,
        amountBaseUnits: spendable.toString(),
        assetCode: 'XRP',
      });
      const txid = await adapter.broadcast(signed);
      await this.markBroadcast(sweepId, txid, BigInt(String(txJson.Fee ?? '12')));
      this.metrics.sweepsTotal.labels('XRP', 'broadcast').inc();
    } catch (err) {
      await this.markFailed(sweepId, (err as Error).message);
      throw err;
    }
  }

  // -- TRON TRC20 (gas top-up in TRX when needed) -----------------------------
  private async sweepTrc20(
    assetCode: AssetCode,
    contract: string,
    deposit: WalletRow,
    treasury: WalletRow,
    payments: SweepablePayment[],
  ): Promise<void> {
    const adapter = this.adapters.forChain('tron') as TronChainAdapter;
    const byAddress = groupByAddress(payments);
    for (const [address, group] of byAddress) {
      const index = group[0].derivation_index;
      if (index === null) continue;
      const tokenBalance = BigInt(await adapter.getTrc20Balance(address, contract));
      if (tokenBalance === 0n) continue;

      const trxBalance = BigInt(await adapter.getTrxBalance(address));
      if (trxBalance < TRX_SWEEP_GAS_SUN) {
        const topUp = TRX_SWEEP_GAS_SUN - trxBalance;
        const unsigned = await adapter.buildTrxTransfer(treasury.address as string, address, topUp.toString());
        const signed = await this.signer.sign({
          kind: 'tron-tx',
          chain: 'tron',
          walletRef: treasury.metadata.walletRef as string,
          path: derivationPathFor('tron', 0),
          tx: unsigned,
          purpose: 'gas-topup',
          destination: address,
          amountBaseUnits: topUp.toString(),
          assetCode: 'USDT_TRC20',
        });
        await adapter.broadcast(signed);
        this.logger.log(`TRX top-up ${topUp} sun -> ${address}`);
        continue; // sweep next tick
      }

      const unsigned = await adapter.buildTrc20Transfer(
        address,
        treasury.address as string,
        tokenBalance.toString(),
        contract,
      );
      const sweepId = await this.createSweepRow(assetCode, deposit.id, treasury.id, tokenBalance, group);
      try {
        const signed = await this.signer.sign({
          kind: 'tron-tx',
          chain: 'tron',
          walletRef: deposit.metadata.walletRef as string,
          path: derivationPathFor('tron', index),
          tx: unsigned,
          purpose: 'sweep',
          destination: treasury.address as string,
          amountBaseUnits: tokenBalance.toString(),
          assetCode,
        });
        const txid = await adapter.broadcast(signed);
        await this.markBroadcast(sweepId, txid, null);
        this.metrics.sweepsTotal.labels(assetCode, 'broadcast').inc();
      } catch (err) {
        await this.markFailed(sweepId, (err as Error).message);
        throw err;
      }
    }
  }

  // -- confirmation tracking + custody ledger entries --------------------------
  private async trackBroadcastSweeps(assetCode: AssetCode, chain: Chain): Promise<void> {
    const adapter = this.adapters.forChain(chain);
    const asset = await this.db.queryOne<{ min_confirmations: number }>(
      `SELECT min_confirmations FROM assets WHERE code = $1`,
      [assetCode],
    );
    const pending = await this.db.query<{ id: string; txid: string; total_amount: string; network_fee: string | null }>(
      `SELECT id, txid, total_amount, network_fee FROM sweeps
        WHERE asset_code = $1 AND status = 'BROADCAST' AND txid IS NOT NULL`,
      [assetCode],
    );
    for (const sweep of pending) {
      const status = await adapter.getTransactionStatus(sweep.txid);
      if (!status.exists) {
        await this.markFailed(sweep.id, 'sweep transaction vanished (reorg/eviction)');
        this.metrics.sweepsTotal.labels(assetCode, 'failed').inc();
        continue;
      }
      if (status.confirmations < (asset?.min_confirmations ?? 6)) continue;
      await this.db.tx(async (client) => {
        await client.query(
          `UPDATE sweeps SET status = 'CONFIRMED', confirmed_at = now() WHERE id = $1`,
          [sweep.id],
        );
        const treasuryAcc = await this.ledger.ensureAccount(client, null, assetCode, 'GATEWAY_TREASURY');
        const externalAcc = await this.ledger.ensureAccount(client, null, assetCode, 'EXTERNAL_DEPOSITS');
        const feesAcc = await this.ledger.ensureAccount(client, null, assetCode, 'GATEWAY_FEES');
        const amount = BigInt(sweep.total_amount);
        const fee = sweep.network_fee ? BigInt(sweep.network_fee) : 0n;
        await this.ledger.postJournal(client, {
          journalType: 'SWEEP',
          referenceType: 'sweep',
          referenceId: sweep.id,
          description: `treasury sweep ${sweep.txid}`,
          entries: [
            { accountId: treasuryAcc, direction: 'DEBIT', amount: amount.toString(), assetCode },
            ...(fee > 0n
              ? [{ accountId: feesAcc, direction: 'DEBIT' as const, amount: fee.toString(), assetCode }]
              : []),
            { accountId: externalAcc, direction: 'CREDIT', amount: (amount + fee).toString(), assetCode },
          ],
        });
      });
      this.metrics.sweepsTotal.labels(assetCode, 'confirmed').inc();
    }
  }
}

function groupByAddress(payments: SweepablePayment[]): Map<string, SweepablePayment[]> {
  const map = new Map<string, SweepablePayment[]>();
  for (const payment of payments) {
    const list = map.get(payment.address) ?? [];
    list.push(payment);
    map.set(payment.address, list);
  }
  return map;
}
