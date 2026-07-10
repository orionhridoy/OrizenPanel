import { Logger } from '@nestjs/common';
import { Client, isValidClassicAddress } from 'xrpl';
import { AssetCode, Chain } from '../../../../common/types';
import {
  ChainAdapter,
  ChainTip,
  IncomingTransfer,
  SyncStatus,
  TxStatus,
  WatchSet,
  XrpChainAdapter,
} from '../../chain-adapter.interface';

/**
 * XRP Ledger adapter.
 * Invoicing uses ONE funded receiving account per gateway and a UNIQUE
 * DESTINATION TAG per invoice - the XRPL-native equivalent of address-per-
 * invoice (each fresh XRPL account would burn the 10 XRP reserve).
 * Watch keys: `${account}:${destinationTag}`.
 */
export class XrpAdapter implements XrpChainAdapter, ChainAdapter {
  private readonly logger = new Logger(XrpAdapter.name);
  readonly chain: Chain = 'xrp';
  readonly assets: readonly AssetCode[] = ['XRP'];
  readonly supportsMempool = false;
  private connecting: Promise<void> | null = null;

  constructor(private readonly client: Client) {}

  private async ensureConnected(): Promise<void> {
    if (this.client.isConnected()) return;
    if (!this.connecting) {
      this.connecting = this.client
        .connect()
        .catch((err) => {
          this.connecting = null;
          throw err;
        })
        .then(() => {
          this.connecting = null;
        });
    }
    await this.connecting;
  }

  async getSyncStatus(): Promise<SyncStatus> {
    await this.ensureConnected();
    const response = await this.client.request({ command: 'server_info' });
    const info = response.result.info;
    const state = info.server_state ?? 'disconnected';
    const synced = state === 'full' || state === 'proposing' || state === 'validating';
    return {
      synced,
      height: info.validated_ledger?.seq ?? 0,
      peers: info.peers ?? 0,
      progress: synced ? 1 : 0,
      version: info.build_version,
    };
  }

  async getTip(): Promise<ChainTip | null> {
    await this.ensureConnected();
    const response = await this.client.request({
      command: 'ledger',
      ledger_index: 'validated',
    });
    const ledger = response.result.ledger;
    return {
      height: Number(response.result.ledger_index),
      hash: ledger.ledger_hash,
      parentHash: ledger.parent_hash,
    };
  }

  async getBlockHashAtHeight(height: number): Promise<string | null> {
    await this.ensureConnected();
    try {
      const response = await this.client.request({ command: 'ledger', ledger_index: height });
      return response.result.ledger.ledger_hash ?? null;
    } catch {
      return null;
    }
  }

  async scanBlocks(
    fromHeight: number,
    toHeight: number,
    watch: WatchSet,
  ): Promise<IncomingTransfer[]> {
    await this.ensureConnected();
    const accounts = new Set<string>();
    for (const key of watch) accounts.add(key.split(':')[0]);

    const transfers: IncomingTransfer[] = [];
    for (const account of accounts) {
      let marker: unknown = undefined;
      do {
        const response = await this.client.request({
          command: 'account_tx',
          account,
          ledger_index_min: fromHeight,
          ledger_index_max: toHeight,
          forward: true,
          limit: 200,
          ...(marker !== undefined ? { marker } : {}),
        });
        for (const entry of response.result.transactions) {
          const tx = entry.tx_json as
            | {
                TransactionType?: string;
                Destination?: string;
                DestinationTag?: number;
                Account?: string;
                hash?: string;
              }
            | undefined;
          const meta = entry.meta;
          const hash = entry.hash ?? tx?.hash;
          if (
            !tx ||
            !hash ||
            typeof meta !== 'object' ||
            meta === null ||
            tx.TransactionType !== 'Payment' ||
            tx.Destination !== account ||
            tx.DestinationTag === undefined ||
            (meta as { TransactionResult?: string }).TransactionResult !== 'tesSUCCESS'
          ) {
            continue;
          }
          const delivered = (meta as { delivered_amount?: unknown }).delivered_amount;
          if (typeof delivered !== 'string') continue; // IOU payment, not XRP
          const key = `${account}:${tx.DestinationTag}`;
          if (!watch.has(key)) continue;
          const ledgerIndex = entry.ledger_index ?? null;
          transfers.push({
            assetCode: 'XRP',
            txid: hash,
            outputIndex: null,
            logIndex: null,
            address: account,
            destinationTag: tx.DestinationTag,
            amountBaseUnits: delivered,
            fromAddress: tx.Account ?? null,
            isRbf: false,
            blockHeight: ledgerIndex,
            // XRPL reorg handling keys off validated ledger index, not a hash
            blockHash: null,
          });
        }
        marker = response.result.marker;
      } while (marker !== undefined);
    }
    return transfers;
  }

  async scanMempool(_watch: WatchSet): Promise<IncomingTransfer[]> {
    return [];
  }

  async getTransactionStatus(txid: string): Promise<TxStatus> {
    await this.ensureConnected();
    try {
      const response = await this.client.request({ command: 'tx', transaction: txid });
      const result = response.result;
      if (!result.validated || result.ledger_index === undefined) {
        return { exists: true, blockHeight: null, blockHash: null, confirmations: 0 };
      }
      const tip = await this.getTip();
      return {
        exists: true,
        blockHeight: result.ledger_index,
        blockHash: null,
        confirmations: tip ? Math.max(1, tip.height - result.ledger_index + 1) : 1,
      };
    } catch (err) {
      if ((err as { data?: { error?: string } }).data?.error === 'txnNotFound') {
        return { exists: false, blockHeight: null, blockHash: null, confirmations: 0 };
      }
      throw err;
    }
  }

  async broadcast(signedTxBlob: string): Promise<string> {
    await this.ensureConnected();
    const response = await this.client.request({ command: 'submit', tx_blob: signedTxBlob });
    const engineResult = response.result.engine_result;
    if (engineResult !== 'tesSUCCESS' && !engineResult.startsWith('terQUEUED')) {
      throw new Error(`XRPL submit failed: ${engineResult} - ${response.result.engine_result_message}`);
    }
    return response.result.tx_json.hash as string;
  }

  validateAddress(address: string): boolean {
    return isValidClassicAddress(address);
  }

  async watchAddress(_address: string, _label: string): Promise<void> {
    // account_tx scanning needs no registration
  }

  async getXrpBalance(account: string): Promise<string> {
    await this.ensureConnected();
    const response = await this.client.request({
      command: 'account_info',
      account,
      ledger_index: 'validated',
    });
    return response.result.account_data.Balance;
  }

  async buildPayment(
    from: string,
    to: string,
    amountDrops: string,
    destinationTag: number | null,
  ): Promise<Record<string, unknown>> {
    await this.ensureConnected();
    const payment = {
      TransactionType: 'Payment' as const,
      Account: from,
      Destination: to,
      Amount: amountDrops,
      ...(destinationTag !== null ? { DestinationTag: destinationTag } : {}),
    };
    return (await this.client.autofill(payment)) as unknown as Record<string, unknown>;
  }
}
