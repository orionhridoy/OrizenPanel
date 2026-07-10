import { Logger } from '@nestjs/common';
import * as bitcoin from 'bitcoinjs-lib';
import * as ecc from '@bitcoinerlab/secp256k1';
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

bitcoin.initEccLib(ecc);

/** Provides the addresses whose UTXOs must be listed (treasury + unswept deposits). */
export type UtxoAddressProvider = () => Promise<string[]>;

interface EsploraTxStatus {
  confirmed: boolean;
  block_height?: number;
  block_hash?: string;
}
interface EsploraVout {
  scriptpubkey: string;
  scriptpubkey_address?: string;
  value: number; // satoshis (base units)
}
interface EsploraVin {
  sequence: number;
  prevout?: { scriptpubkey_address?: string };
}
interface EsploraTx {
  txid: string;
  vin: EsploraVin[];
  vout: EsploraVout[];
  status: EsploraTxStatus;
}
interface EsploraUtxo {
  txid: string;
  vout: number;
  value: number;
  status: EsploraTxStatus;
}

const RBF_SEQUENCE_CEILING = 0xfffffffe;

/**
 * Block-explorer (Esplora API - mempool.space / litecoinspace / Blockstream)
 * adapter for Bitcoin & Litecoin. Detects payments by querying watched
 * addresses over HTTP instead of running a node + watch-only wallet - so a
 * chain works with ZERO local storage. Amounts come back already in base units
 * (satoshi/litoshi). scriptPubKeys are derived locally from the address.
 *
 * usesAddressPolling=true tells the scanner to feed only ACTIVE invoice
 * addresses (bounded), so we don't hammer the explorer with every address ever.
 */
export class EsploraAdapter implements UtxoChainAdapter, ChainAdapter {
  private readonly logger: Logger;
  readonly supportsMempool = true;
  readonly usesAddressPolling = true;
  private tipCache: { at: number; height: number } | null = null;

  constructor(
    readonly chain: Chain,
    readonly assets: readonly AssetCode[],
    private readonly baseUrl: string,
    private readonly network: bitcoin.networks.Network,
    private readonly utxoAddresses: UtxoAddressProvider,
    private readonly fallbackFeeRate = 5,
  ) {
    this.logger = new Logger(`${EsploraAdapter.name}:${chain}`);
    this.baseUrl = baseUrl.replace(/\/+$/, '');
  }

  private async get<T>(path: string, asText = false): Promise<T> {
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), 20_000);
    try {
      const response = await fetch(`${this.baseUrl}${path}`, { signal: controller.signal });
      if (response.status === 404) return (asText ? '' : null) as T;
      if (!response.ok) throw new Error(`esplora ${path} HTTP ${response.status}`);
      return (asText ? await response.text() : await response.json()) as T;
    } finally {
      clearTimeout(timer);
    }
  }

  private scriptPubKeyHex(address: string): string {
    return bitcoin.address.toOutputScript(address, this.network).toString('hex');
  }

  private async tipHeight(): Promise<number> {
    if (this.tipCache && Date.now() - this.tipCache.at < 5000) return this.tipCache.height;
    const text = await this.get<string>('/blocks/tip/height', true);
    const height = Number.parseInt(text, 10) || 0;
    this.tipCache = { at: Date.now(), height };
    return height;
  }

  async getSyncStatus(): Promise<SyncStatus> {
    // an explorer API is, by definition, a synced view of the chain
    const height = await this.tipHeight();
    return { synced: height > 0, height, peers: 1, progress: height > 0 ? 1 : 0, version: 'esplora' };
  }

  async getTip(): Promise<ChainTip | null> {
    const hash = await this.get<string>('/blocks/tip/hash', true);
    if (!hash) return null;
    const block = await this.get<{ height: number; previousblockhash?: string } | null>(`/block/${hash}`);
    if (!block) return null;
    return { height: block.height, hash, parentHash: block.previousblockhash ?? '' };
  }

  async getBlockHashAtHeight(height: number): Promise<string | null> {
    const hash = await this.get<string>(`/block-height/${height}`, true);
    return hash || null;
  }

  private toTransfers(address: string, tx: EsploraTx, blockRange?: { from: number; to: number }): IncomingTransfer[] {
    if (blockRange) {
      if (!tx.status.confirmed || tx.status.block_height === undefined) return [];
      if (tx.status.block_height < blockRange.from || tx.status.block_height > blockRange.to) return [];
    }
    const isRbf = tx.vin.some((v) => v.sequence < RBF_SEQUENCE_CEILING);
    const from = tx.vin.find((v) => v.prevout?.scriptpubkey_address)?.prevout?.scriptpubkey_address ?? null;
    const out: IncomingTransfer[] = [];
    tx.vout.forEach((vout, index) => {
      if (vout.scriptpubkey_address !== address || vout.value <= 0) return;
      out.push({
        assetCode: this.assets[0],
        txid: tx.txid,
        outputIndex: index,
        logIndex: null,
        address,
        destinationTag: null,
        amountBaseUnits: String(vout.value),
        fromAddress: from,
        isRbf,
        blockHeight: tx.status.confirmed ? (tx.status.block_height ?? null) : null,
        blockHash: tx.status.confirmed ? (tx.status.block_hash ?? null) : null,
      });
    });
    return out;
  }

  async scanBlocks(fromHeight: number, toHeight: number, watch: WatchSet): Promise<IncomingTransfer[]> {
    const transfers: IncomingTransfer[] = [];
    for (const address of watch) {
      const txs = (await this.get<EsploraTx[] | null>(`/address/${address}/txs`)) ?? [];
      for (const tx of txs) {
        transfers.push(...this.toTransfers(address, tx, { from: fromHeight, to: toHeight }));
      }
    }
    return transfers;
  }

  async scanMempool(watch: WatchSet): Promise<IncomingTransfer[]> {
    const transfers: IncomingTransfer[] = [];
    for (const address of watch) {
      const txs = (await this.get<EsploraTx[] | null>(`/address/${address}/txs/mempool`)) ?? [];
      for (const tx of txs) transfers.push(...this.toTransfers(address, tx));
    }
    return transfers;
  }

  async getTransactionStatus(txid: string): Promise<TxStatus> {
    const status = await this.get<EsploraTxStatus | null>(`/tx/${txid}/status`);
    if (!status) return { exists: false, blockHeight: null, blockHash: null, confirmations: 0 };
    if (!status.confirmed || status.block_height === undefined) {
      // still unconfirmed if the mempool knows it, otherwise gone (dropped/replaced)
      const tx = await this.get<EsploraTx | null>(`/tx/${txid}`);
      if (!tx) return { exists: false, blockHeight: null, blockHash: null, confirmations: 0 };
      return { exists: true, blockHeight: null, blockHash: null, confirmations: 0 };
    }
    const tip = await this.tipHeight();
    return {
      exists: true,
      blockHeight: status.block_height,
      blockHash: status.block_hash ?? null,
      confirmations: Math.max(1, tip - status.block_height + 1),
    };
  }

  async broadcast(signedTxHex: string): Promise<string> {
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), 20_000);
    try {
      const response = await fetch(`${this.baseUrl}/tx`, {
        method: 'POST',
        headers: { 'content-type': 'text/plain' },
        body: signedTxHex,
        signal: controller.signal,
      });
      const text = await response.text();
      if (!response.ok) throw new Error(`esplora broadcast failed (${response.status}): ${text.slice(0, 200)}`);
      return text.trim();
    } finally {
      clearTimeout(timer);
    }
  }

  validateAddress(address: string): boolean {
    try {
      bitcoin.address.toOutputScript(address, this.network);
      return true;
    } catch {
      return false;
    }
  }

  async watchAddress(_address: string, _label: string): Promise<void> {
    // stateless: Esplora is queried per-address, no node-side registration
  }

  async listUnspent(minConfirmations: number): Promise<Utxo[]> {
    const addresses = await this.utxoAddresses();
    const tip = await this.tipHeight();
    const utxos: Utxo[] = [];
    for (const address of addresses) {
      const script = this.scriptPubKeyHex(address);
      const rows = (await this.get<EsploraUtxo[] | null>(`/address/${address}/utxo`)) ?? [];
      for (const u of rows) {
        const confirmations =
          u.status.confirmed && u.status.block_height !== undefined
            ? Math.max(1, tip - u.status.block_height + 1)
            : 0;
        if (confirmations < minConfirmations) continue;
        utxos.push({
          txid: u.txid,
          vout: u.vout,
          address,
          amountBaseUnits: String(u.value),
          scriptPubKeyHex: script,
          confirmations,
        });
      }
    }
    return utxos;
  }

  async getRawTransactionHex(txid: string): Promise<string> {
    const hex = await this.get<string>(`/tx/${txid}/hex`, true);
    if (!hex) throw new Error(`esplora: raw tx ${txid} not found`);
    return hex.trim();
  }

  async estimateFeeRate(): Promise<number> {
    try {
      const fees = await this.get<Record<string, number>>('/fee-estimates');
      const rate = fees?.['6'] ?? fees?.['3'] ?? fees?.['1'];
      if (rate && rate > 0) return Math.max(1, Math.ceil(rate));
    } catch {
      // fall through
    }
    return this.fallbackFeeRate;
  }
}
