import { Logger } from '@nestjs/common';
import bs58check from 'bs58check';
import { AssetCode, Chain } from '../../../../common/types';
import {
  ChainAdapter,
  ChainTip,
  IncomingTransfer,
  SyncStatus,
  TronChainAdapter,
  TxStatus,
  WatchSet,
} from '../../chain-adapter.interface';

const TRANSFER_SELECTOR = 'a9059cbb';

export function tronHexToBase58(hex41: string): string {
  return bs58check.encode(Buffer.from(hex41, 'hex'));
}

export function tronBase58ToHex(address: string): string {
  return Buffer.from(bs58check.decode(address)).toString('hex');
}

interface TronBlock {
  blockID: string;
  block_header: {
    raw_data: { number: number; parentHash: string; timestamp: number };
  };
  transactions?: TronTx[];
}

interface TronTx {
  txID: string;
  ret?: Array<{ contractRet?: string }>;
  raw_data: {
    contract: Array<{
      type: string;
      parameter: {
        value: {
          owner_address?: string;
          contract_address?: string;
          to_address?: string;
          amount?: number;
          data?: string;
        };
      };
    }>;
  };
}

/**
 * Tron adapter over the java-tron HTTP API.
 * USDT TRC20 deposits are detected by decoding transfer(address,uint256) calls
 * to the token contract inside each block. Confirmation policy (19 blocks)
 * approximates solidity finality.
 */
export class TronAdapter implements TronChainAdapter, ChainAdapter {
  private readonly logger = new Logger(TronAdapter.name);
  readonly chain: Chain = 'tron';
  readonly assets: readonly AssetCode[];
  readonly supportsMempool = false;
  private readonly usdtContractHex: string;

  constructor(
    private readonly baseUrl: string,
    usdtContractBase58: string,
    private readonly apiKey: string | null = null,
  ) {
    this.assets = ['USDT_TRC20'];
    this.usdtContractHex = tronBase58ToHex(usdtContractBase58);
  }

  private async post<T>(path: string, body: Record<string, unknown> = {}): Promise<T> {
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), 30_000);
    const headers: Record<string, string> = { 'content-type': 'application/json' };
    // TronGrid honours a per-key quota far above the shared anonymous limit
    if (this.apiKey) headers['TRON-PRO-API-KEY'] = this.apiKey;
    try {
      const response = await fetch(`${this.baseUrl}${path}`, {
        method: 'POST',
        headers,
        body: JSON.stringify(body),
        signal: controller.signal,
      });
      if (!response.ok) throw new Error(`tron ${path} HTTP ${response.status}`);
      return (await response.json()) as T;
    } finally {
      clearTimeout(timer);
    }
  }

  async getSyncStatus(): Promise<SyncStatus> {
    const now = await this.post<TronBlock>('/wallet/getnowblock');
    // getnodeinfo is a node-admin call not exposed by managed endpoints
    // (e.g. TronGrid) - degrade gracefully instead of failing the whole status
    const info = await this.post<{
      activeConnectCount?: number;
      passiveConnectCount?: number;
      configNodeInfo?: { codeVersion?: string };
    }>('/wallet/getnodeinfo').catch(() => ({}) as Record<string, never>);
    const height = now.block_header.raw_data.number;
    // Managed endpoints (TronGrid) are always-synced hosted nodes; the only true
    // "not synced" is an unreachable/stale endpoint (a failed call throws and is
    // handled upstream). Use an ABSOLUTE, generous freshness window so ordinary
    // block jitter and container clock skew never flip synced<->not-synced
    // (that oscillation showed up as "Waiting for sync" <-> "ACTIVE").
    const ageMs = Math.abs(Date.now() - now.block_header.raw_data.timestamp);
    const synced = height > 0 && ageMs < 180_000;
    return {
      synced,
      height,
      peers: (info.activeConnectCount ?? 0) + (info.passiveConnectCount ?? 0),
      progress: synced ? 1 : 0,
      version: info.configNodeInfo?.codeVersion,
    };
  }

  async getTip(): Promise<ChainTip | null> {
    const block = await this.post<TronBlock>('/wallet/getnowblock');
    return {
      height: block.block_header.raw_data.number,
      hash: block.blockID,
      parentHash: block.block_header.raw_data.parentHash,
    };
  }

  async getBlockHashAtHeight(height: number): Promise<string | null> {
    const block = await this.post<TronBlock>('/wallet/getblockbynum', { num: height });
    return block?.blockID ?? null;
  }

  async scanBlocks(
    fromHeight: number,
    toHeight: number,
    watch: WatchSet,
  ): Promise<IncomingTransfer[]> {
    const transfers: IncomingTransfer[] = [];
    for (let height = fromHeight; height <= toHeight; height++) {
      const block = await this.post<TronBlock>('/wallet/getblockbynum', { num: height });
      if (!block?.transactions) continue;
      for (const tx of block.transactions) {
        if (tx.ret?.[0]?.contractRet !== 'SUCCESS') continue;
        const contract = tx.raw_data.contract[0];
        if (contract?.type !== 'TriggerSmartContract') continue;
        const value = contract.parameter.value;
        if (value.contract_address !== this.usdtContractHex || !value.data) continue;
        const data = value.data.toLowerCase();
        if (!data.startsWith(TRANSFER_SELECTOR) || data.length < 8 + 64 + 64) continue;
        const toHex = `41${data.slice(8 + 24, 8 + 64)}`;
        const toBase58 = tronHexToBase58(toHex);
        if (!watch.has(toBase58)) continue;
        const amount = BigInt(`0x${data.slice(8 + 64, 8 + 128)}`);
        if (amount === 0n) continue;
        transfers.push({
          assetCode: 'USDT_TRC20',
          txid: tx.txID,
          outputIndex: null,
          logIndex: 0,
          address: toBase58,
          destinationTag: null,
          amountBaseUnits: amount.toString(),
          fromAddress: value.owner_address ? tronHexToBase58(value.owner_address) : null,
          isRbf: false,
          blockHeight: height,
          blockHash: block.blockID,
        });
      }
    }
    return transfers;
  }

  async scanMempool(_watch: WatchSet): Promise<IncomingTransfer[]> {
    return [];
  }

  async getTransactionStatus(txid: string): Promise<TxStatus> {
    const tx = await this.post<TronTx | Record<string, never>>('/wallet/gettransactionbyid', {
      value: txid,
    });
    if (!('txID' in tx)) {
      return { exists: false, blockHeight: null, blockHash: null, confirmations: 0 };
    }
    if (tx.ret?.[0]?.contractRet && tx.ret[0].contractRet !== 'SUCCESS') {
      return { exists: false, blockHeight: null, blockHash: null, confirmations: 0 };
    }
    const info = await this.post<{ blockNumber?: number }>('/wallet/gettransactioninfobyid', {
      value: txid,
    });
    if (info.blockNumber === undefined) {
      return { exists: true, blockHeight: null, blockHash: null, confirmations: 0 };
    }
    const tip = await this.getTip();
    return {
      exists: true,
      blockHeight: info.blockNumber,
      blockHash: null,
      confirmations: tip ? Math.max(0, tip.height - info.blockNumber + 1) : 0,
    };
  }

  /** signedTx is the full signed transaction JSON produced by the signer. */
  async broadcast(signedTx: string): Promise<string> {
    const tx = JSON.parse(signedTx) as { txID: string };
    const result = await this.post<{ result?: boolean; code?: string; message?: string }>(
      '/wallet/broadcasttransaction',
      tx as unknown as Record<string, unknown>,
    );
    if (result.result !== true) {
      const message = result.message
        ? Buffer.from(result.message, 'hex').toString('utf8')
        : (result.code ?? 'unknown');
      throw new Error(`tron broadcast failed: ${message}`);
    }
    return tx.txID;
  }

  validateAddress(address: string): boolean {
    try {
      const decoded = Buffer.from(bs58check.decode(address));
      return decoded.length === 21 && decoded[0] === 0x41;
    } catch {
      return false;
    }
  }

  async watchAddress(_address: string, _label: string): Promise<void> {
    // scan-set based
  }

  async getTrxBalance(address: string): Promise<string> {
    const account = await this.post<{ balance?: number }>('/wallet/getaccount', {
      address: tronBase58ToHex(address),
    });
    return (account.balance ?? 0).toString();
  }

  async getTrc20Balance(address: string, contract: string): Promise<string> {
    const result = await this.post<{ constant_result?: string[] }>(
      '/wallet/triggerconstantcontract',
      {
        owner_address: tronBase58ToHex(address),
        contract_address: tronBase58ToHex(contract),
        function_selector: 'balanceOf(address)',
        parameter: tronBase58ToHex(address).slice(2).padStart(64, '0'),
      },
    );
    const hex = result.constant_result?.[0];
    return hex ? BigInt(`0x${hex}`).toString() : '0';
  }

  async buildTrxTransfer(
    from: string,
    to: string,
    amountSun: string,
  ): Promise<Record<string, unknown>> {
    const tx = await this.post<Record<string, unknown> & { Error?: string }>(
      '/wallet/createtransaction',
      {
        owner_address: tronBase58ToHex(from),
        to_address: tronBase58ToHex(to),
        amount: Number(amountSun),
      },
    );
    if (tx.Error) throw new Error(`tron createtransaction: ${tx.Error}`);
    return tx;
  }

  async buildTrc20Transfer(
    from: string,
    to: string,
    amount: string,
    contract: string,
  ): Promise<Record<string, unknown>> {
    const parameter =
      tronBase58ToHex(to).slice(2).padStart(64, '0') + BigInt(amount).toString(16).padStart(64, '0');
    const result = await this.post<{
      result?: { result?: boolean; message?: string };
      transaction?: Record<string, unknown>;
    }>('/wallet/triggersmartcontract', {
      owner_address: tronBase58ToHex(from),
      contract_address: tronBase58ToHex(contract),
      function_selector: 'transfer(address,uint256)',
      parameter,
      fee_limit: 100_000_000, // 100 TRX energy ceiling
      call_value: 0,
    });
    if (!result.result?.result || !result.transaction) {
      const message = result.result?.message
        ? Buffer.from(result.result.message, 'hex').toString('utf8')
        : 'unknown';
      throw new Error(`tron triggersmartcontract failed: ${message}`);
    }
    return result.transaction;
  }
}
