import { Logger } from '@nestjs/common';
import * as bitcoin from 'bitcoinjs-lib';
import { AssetCode, Chain } from '../../../../common/types';
import {
  ChainAdapter,
  ChainTip,
  IncomingTransfer,
  SyncStatus,
  TxStatus,
  Utxo,
  UtxoChainAdapter,
  WatchSet,
} from '../../chain-adapter.interface';
import { JsonRpcClient, JsonRpcError } from '../../rpc/json-rpc.client';

const WATCH_WALLET = 'navixo-watch';
const RPC_WALLET_NOT_FOUND = -18;
const RPC_WALLET_ALREADY_LOADED = -35;
const RPC_TX_NOT_FOUND = -5;

/** Core returns BTC amounts as JSON floats; exact for < 2^53 base units. */
function coinsToBaseUnits(amount: number): string {
  return BigInt(Math.round(amount * 1e8)).toString();
}

interface ListSinceBlockTx {
  category: string;
  address?: string;
  amount: number;
  vout: number;
  txid: string;
  blockheight?: number;
  blockhash?: string;
  confirmations: number;
  'bip125-replaceable'?: string;
}

interface WalletTx {
  confirmations: number;
  blockhash?: string;
  blockheight?: number;
  walletconflicts: string[];
  hex?: string;
  'bip125-replaceable'?: string;
}

/**
 * Bitcoin Core-compatible adapter (Bitcoin, Litecoin).
 * Detection strategy: addresses are imported into a watch-only descriptor
 * wallet at issuance; mempool + confirmed receives come from wallet RPCs,
 * which is O(our transactions) instead of O(chain).
 */
export class BitcoinLikeAdapter implements UtxoChainAdapter, ChainAdapter {
  private readonly logger: Logger;
  private walletReady: Promise<void> | null = null;
  readonly supportsMempool = true;

  constructor(
    readonly chain: Chain,
    readonly assets: readonly AssetCode[],
    private readonly rpc: JsonRpcClient,
    private readonly network: bitcoin.networks.Network,
    private readonly fallbackFeeRate: number,
  ) {
    this.logger = new Logger(`${BitcoinLikeAdapter.name}:${chain}`);
  }

  private wallet<T>(method: string, params: unknown[] = []): Promise<T> {
    return this.rpc.call<T>(method, params, `/wallet/${WATCH_WALLET}`);
  }

  private async ensureWatchWallet(): Promise<void> {
    if (!this.walletReady) {
      this.walletReady = (async () => {
        try {
          await this.rpc.call('loadwallet', [WATCH_WALLET, true]);
        } catch (err) {
          if (err instanceof JsonRpcError && err.code === RPC_WALLET_NOT_FOUND) {
            await this.rpc.call('createwallet', [WATCH_WALLET, true, true, '', false, true, true]);
            this.logger.log(`created watch-only wallet ${WATCH_WALLET}`);
          } else if (!(err instanceof JsonRpcError && err.code === RPC_WALLET_ALREADY_LOADED)) {
            this.walletReady = null;
            throw err;
          }
        }
      })();
    }
    return this.walletReady;
  }

  async getSyncStatus(): Promise<SyncStatus> {
    const info = await this.rpc.call<{
      blocks: number;
      initialblockdownload: boolean;
      verificationprogress: number;
    }>('getblockchaininfo');
    const net = await this.rpc.call<{ connections: number; subversion: string }>('getnetworkinfo');
    return {
      synced: !info.initialblockdownload && info.verificationprogress > 0.9999,
      height: info.blocks,
      peers: net.connections,
      progress: Math.min(info.verificationprogress, 1),
      version: net.subversion,
    };
  }

  async getTip(): Promise<ChainTip | null> {
    const hash = await this.rpc.call<string>('getbestblockhash');
    const header = await this.rpc.call<{ height: number; previousblockhash?: string }>(
      'getblockheader',
      [hash],
    );
    return { height: header.height, hash, parentHash: header.previousblockhash ?? '' };
  }

  async getBlockHashAtHeight(height: number): Promise<string | null> {
    try {
      return await this.rpc.call<string>('getblockhash', [height]);
    } catch {
      return null;
    }
  }

  async scanBlocks(
    fromHeight: number,
    toHeight: number,
    watch: WatchSet,
  ): Promise<IncomingTransfer[]> {
    await this.ensureWatchWallet();
    const anchorHash =
      fromHeight > 1 ? await this.rpc.call<string>('getblockhash', [fromHeight - 1]) : undefined;
    const result = await this.wallet<{ transactions: ListSinceBlockTx[] }>('listsinceblock', [
      ...(anchorHash ? [anchorHash] : []),
      1,
      true,
    ]);
    return result.transactions
      .filter(
        (tx) =>
          tx.category === 'receive' &&
          tx.address !== undefined &&
          watch.has(tx.address) &&
          tx.blockheight !== undefined &&
          tx.blockheight >= fromHeight &&
          tx.blockheight <= toHeight,
      )
      .map((tx) => this.toTransfer(tx));
  }

  async scanMempool(watch: WatchSet): Promise<IncomingTransfer[]> {
    await this.ensureWatchWallet();
    const unspent = await this.wallet<
      Array<{ txid: string; vout: number; address: string; amount: number; confirmations: number }>
    >('listunspent', [0, 0, [], true]);
    const transfers: IncomingTransfer[] = [];
    for (const utxo of unspent) {
      if (!watch.has(utxo.address)) continue;
      let isRbf = false;
      try {
        const tx = await this.wallet<WalletTx>('gettransaction', [utxo.txid, true]);
        isRbf = tx['bip125-replaceable'] === 'yes';
      } catch {
        // tx may have just been evicted; skip flag
      }
      transfers.push({
        assetCode: this.assets[0],
        txid: utxo.txid,
        outputIndex: utxo.vout,
        logIndex: null,
        address: utxo.address,
        destinationTag: null,
        amountBaseUnits: coinsToBaseUnits(utxo.amount),
        fromAddress: null,
        isRbf,
        blockHeight: null,
        blockHash: null,
      });
    }
    return transfers;
  }

  async getTransactionStatus(txid: string): Promise<TxStatus> {
    await this.ensureWatchWallet();
    try {
      const tx = await this.wallet<WalletTx>('gettransaction', [txid, true]);
      // Core reports negative confirmations for conflicted (double-spent/RBF-replaced) txs
      if (tx.confirmations < 0) {
        return {
          exists: false,
          blockHeight: null,
          blockHash: null,
          confirmations: 0,
          replacedByTxid: tx.walletconflicts[0] ?? null,
        };
      }
      return {
        exists: true,
        blockHeight: tx.blockheight ?? null,
        blockHash: tx.blockhash ?? null,
        confirmations: tx.confirmations,
        replacedByTxid: null,
      };
    } catch (err) {
      if (err instanceof JsonRpcError && err.code === RPC_TX_NOT_FOUND) {
        return { exists: false, blockHeight: null, blockHash: null, confirmations: 0 };
      }
      throw err;
    }
  }

  async broadcast(signedTxHex: string): Promise<string> {
    return this.rpc.call<string>('sendrawtransaction', [signedTxHex]);
  }

  validateAddress(address: string): boolean {
    try {
      bitcoin.address.toOutputScript(address, this.network);
      return true;
    } catch {
      return false;
    }
  }

  async watchAddress(address: string, label: string): Promise<void> {
    await this.ensureWatchWallet();
    const info = await this.rpc.call<{ descriptor: string }>('getdescriptorinfo', [
      `addr(${address})`,
    ]);
    const results = await this.wallet<Array<{ success: boolean; error?: { message: string } }>>(
      'importdescriptors',
      [[{ desc: info.descriptor, timestamp: 'now', label }]],
    );
    if (!results[0]?.success) {
      throw new Error(
        `importdescriptors failed for ${address}: ${results[0]?.error?.message ?? 'unknown'}`,
      );
    }
  }

  async listUnspent(minConfirmations: number): Promise<Utxo[]> {
    await this.ensureWatchWallet();
    const unspent = await this.wallet<
      Array<{
        txid: string;
        vout: number;
        address: string;
        amount: number;
        confirmations: number;
        scriptPubKey: string;
      }>
    >('listunspent', [minConfirmations, 9_999_999, [], true]);
    return unspent.map((u) => ({
      txid: u.txid,
      vout: u.vout,
      address: u.address,
      amountBaseUnits: coinsToBaseUnits(u.amount),
      scriptPubKeyHex: u.scriptPubKey,
      confirmations: u.confirmations,
    }));
  }

  async getRawTransactionHex(txid: string): Promise<string> {
    await this.ensureWatchWallet();
    const tx = await this.wallet<WalletTx>('gettransaction', [txid, true]);
    if (tx.hex) return tx.hex;
    return this.rpc.call<string>('getrawtransaction', [txid]);
  }

  async estimateFeeRate(): Promise<number> {
    try {
      const estimate = await this.rpc.call<{ feerate?: number }>('estimatesmartfee', [6]);
      if (estimate.feerate && estimate.feerate > 0) {
        return Math.max(1, Math.ceil((estimate.feerate * 1e8) / 1000));
      }
    } catch {
      // fall through to default
    }
    return this.fallbackFeeRate;
  }

  private toTransfer(tx: ListSinceBlockTx): IncomingTransfer {
    return {
      assetCode: this.assets[0],
      txid: tx.txid,
      outputIndex: tx.vout,
      logIndex: null,
      address: tx.address as string,
      destinationTag: null,
      amountBaseUnits: coinsToBaseUnits(tx.amount),
      fromAddress: null,
      isRbf: tx['bip125-replaceable'] === 'yes',
      blockHeight: tx.blockheight ?? null,
      blockHash: tx.blockhash ?? null,
    };
  }
}
