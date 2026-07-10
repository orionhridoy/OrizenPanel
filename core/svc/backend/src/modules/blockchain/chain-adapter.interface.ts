import { AssetCode, Chain } from '../../common/types';

export interface SyncStatus {
  synced: boolean;
  height: number;
  peers: number;
  /** 0..1 */
  progress: number;
  version?: string;
}

export interface ChainTip {
  height: number;
  hash: string;
  parentHash: string;
}

/**
 * A value transfer to one of our watched addresses.
 * blockHeight === null -> still in mempool.
 */
export interface IncomingTransfer {
  assetCode: AssetCode;
  txid: string;
  outputIndex: number | null; // UTXO chains
  logIndex: number | null; // EVM token events
  address: string;
  destinationTag: number | null; // XRP
  amountBaseUnits: string;
  fromAddress: string | null;
  isRbf: boolean;
  blockHeight: number | null;
  blockHash: string | null;
}

export interface TxStatus {
  exists: boolean;
  blockHeight: number | null;
  blockHash: string | null;
  confirmations: number;
  replacedByTxid?: string | null;
}

export interface Utxo {
  txid: string;
  vout: number;
  address: string;
  amountBaseUnits: string;
  scriptPubKeyHex: string;
  confirmations: number;
}

/**
 * Canonical watch keys:
 *   bitcoin/litecoin/tron : address as issued
 *   ethereum              : lowercase 0x address
 *   xrp                   : `${account}:${destinationTag}`
 */
export type WatchSet = ReadonlySet<string>;

export interface ChainAdapter {
  readonly chain: Chain;
  readonly assets: readonly AssetCode[];
  /** whether unconfirmed (mempool) detection is supported */
  readonly supportsMempool: boolean;

  getSyncStatus(): Promise<SyncStatus>;
  getTip(): Promise<ChainTip | null>;
  getBlockHashAtHeight(height: number): Promise<string | null>;

  /** Transfers to watched keys in [fromHeight, toHeight] (confirmed). */
  scanBlocks(fromHeight: number, toHeight: number, watch: WatchSet): Promise<IncomingTransfer[]>;
  /** Transfers to watched keys currently unconfirmed. Empty when unsupported. */
  scanMempool(watch: WatchSet): Promise<IncomingTransfer[]>;

  getTransactionStatus(txid: string): Promise<TxStatus>;
  broadcast(signedTx: string): Promise<string>;
  validateAddress(address: string): boolean;

  /** Register an address for node-side watching (UTXO watch-only wallets). No-op elsewhere. */
  watchAddress(address: string, label: string): Promise<void>;

  /**
   * When true, the scanner queries the chain per-address over an explorer API
   * (no node wallet). It therefore feeds only the bounded ACTIVE-invoice watch
   * set instead of every address ever issued.
   */
  readonly usesAddressPolling?: boolean;
}

/** UTXO-chain extension (bitcoin, litecoin) used by the sweep planner. */
export interface UtxoChainAdapter extends ChainAdapter {
  listUnspent(minConfirmations: number): Promise<Utxo[]>;
  getRawTransactionHex(txid: string): Promise<string>;
  /** sat(or litoshi)/vByte */
  estimateFeeRate(): Promise<number>;
}

/** Account-chain helpers used by sweep/withdrawal builders. */
export interface EvmChainAdapter extends ChainAdapter {
  getNativeBalance(address: string): Promise<string>;
  getTokenBalance(address: string, contract: string): Promise<string>;
  getTransactionCount(address: string): Promise<number>;
  getChainId(): Promise<number>;
  getFeeData(): Promise<{ maxFeePerGas: string; maxPriorityFeePerGas: string }>;
  estimateGas(tx: { from: string; to: string; value?: string; data?: string }): Promise<string>;
}

export interface XrpChainAdapter extends ChainAdapter {
  getXrpBalance(account: string): Promise<string>;
  /** Fully prepared (sequence/fee/LastLedgerSequence) unsigned Payment JSON. */
  buildPayment(
    from: string,
    to: string,
    amountDrops: string,
    destinationTag: number | null,
  ): Promise<Record<string, unknown>>;
}

export interface TronChainAdapter extends ChainAdapter {
  getTrxBalance(address: string): Promise<string>;
  getTrc20Balance(address: string, contract: string): Promise<string>;
  /** Unsigned transaction JSON from the node (to be signed by the signer). */
  buildTrxTransfer(from: string, to: string, amountSun: string): Promise<Record<string, unknown>>;
  buildTrc20Transfer(
    from: string,
    to: string,
    amount: string,
    contract: string,
  ): Promise<Record<string, unknown>>;
}
