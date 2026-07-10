import { Logger } from '@nestjs/common';
import { JsonRpcProvider, getAddress, isAddress } from 'ethers';
import { AssetCode, Chain } from '../../../../common/types';
import {
  ChainAdapter,
  ChainTip,
  EvmChainAdapter,
  IncomingTransfer,
  SyncStatus,
  TxStatus,
  WatchSet,
} from '../../chain-adapter.interface';

const TRANSFER_TOPIC = '0xddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef';

export interface Erc20Token {
  assetCode: AssetCode;
  contract: string; // checksummed
}

/**
 * Ethereum adapter: native ETH by block-transaction scanning, ERC20 tokens by
 * Transfer-log scanning. Mempool detection is intentionally not supported -
 * ETH finality policy (12 confirmations) makes 0-conf display of little value
 * and txpool scanning is unreliable over HTTP.
 */
export class EthereumAdapter implements EvmChainAdapter, ChainAdapter {
  private readonly logger = new Logger(EthereumAdapter.name);
  readonly chain: Chain = 'ethereum';
  readonly assets: readonly AssetCode[];
  readonly supportsMempool = false;
  private chainIdCache: number | null = null;

  constructor(
    private readonly provider: JsonRpcProvider,
    private readonly tokens: Erc20Token[],
  ) {
    this.assets = ['ETH', ...tokens.map((t) => t.assetCode)];
  }

  async getSyncStatus(): Promise<SyncStatus> {
    const [syncing, peersHex, version] = await Promise.all([
      this.provider.send('eth_syncing', []) as Promise<
        false | { currentBlock: string; highestBlock: string }
      >,
      // public/managed RPC endpoints often don't expose net_peerCount - treat as
      // reachable rather than failing the whole status check
      (this.provider.send('net_peerCount', []) as Promise<string>).catch(() => '0x1'),
      this.provider.send('web3_clientVersion', []).catch(() => 'unknown') as Promise<string>,
    ]);
    const peers = Number.parseInt(peersHex, 16) || 1;
    if (syncing === false) {
      const block = await this.provider.getBlock('latest');
      const height = block?.number ?? 0;
      // a "synced" node with an old head is actually stalled/waiting for CL
      const fresh = block !== null && Date.now() / 1000 - block.timestamp < 120;
      return { synced: fresh, height, peers, progress: fresh ? 1 : 0, version };
    }
    const current = Number.parseInt(syncing.currentBlock, 16);
    const highest = Number.parseInt(syncing.highestBlock, 16);
    return {
      synced: false,
      height: current,
      peers,
      progress: highest > 0 ? Math.min(current / highest, 1) : 0,
      version,
    };
  }

  async getTip(): Promise<ChainTip | null> {
    const block = await this.provider.getBlock('latest');
    if (!block?.hash) return null;
    return { height: block.number, hash: block.hash, parentHash: block.parentHash };
  }

  async getBlockHashAtHeight(height: number): Promise<string | null> {
    const block = await this.provider.getBlock(height);
    return block?.hash ?? null;
  }

  async scanBlocks(
    fromHeight: number,
    toHeight: number,
    watch: WatchSet,
  ): Promise<IncomingTransfer[]> {
    const transfers: IncomingTransfer[] = [];

    // native ETH
    for (let height = fromHeight; height <= toHeight; height++) {
      const block = await this.provider.getBlock(height, true);
      if (!block) continue;
      for (const tx of block.prefetchedTransactions) {
        if (!tx.to || tx.value === 0n) continue;
        const to = tx.to.toLowerCase();
        if (!watch.has(to)) continue;
        transfers.push({
          assetCode: 'ETH',
          txid: tx.hash,
          outputIndex: null,
          logIndex: null,
          address: to,
          destinationTag: null,
          amountBaseUnits: tx.value.toString(),
          fromAddress: tx.from.toLowerCase(),
          isRbf: false,
          blockHeight: height,
          blockHash: block.hash,
        });
      }
    }

    // ERC20 Transfer events
    for (const token of this.tokens) {
      const logs = await this.provider.getLogs({
        address: token.contract,
        topics: [TRANSFER_TOPIC],
        fromBlock: fromHeight,
        toBlock: toHeight,
      });
      for (const log of logs) {
        if (log.topics.length < 3) continue;
        const to = `0x${log.topics[2].slice(26)}`.toLowerCase();
        if (!watch.has(to)) continue;
        const amount = BigInt(log.data === '0x' ? 0 : log.data);
        if (amount === 0n) continue;
        transfers.push({
          assetCode: token.assetCode,
          txid: log.transactionHash,
          outputIndex: null,
          logIndex: log.index,
          address: to,
          destinationTag: null,
          amountBaseUnits: amount.toString(),
          fromAddress: `0x${log.topics[1].slice(26)}`.toLowerCase(),
          isRbf: false,
          blockHeight: log.blockNumber,
          blockHash: log.blockHash,
        });
      }
    }
    return transfers;
  }

  async scanMempool(_watch: WatchSet): Promise<IncomingTransfer[]> {
    return [];
  }

  async getTransactionStatus(txid: string): Promise<TxStatus> {
    const tx = await this.provider.getTransaction(txid);
    if (!tx) return { exists: false, blockHeight: null, blockHash: null, confirmations: 0 };
    if (tx.blockNumber === null) {
      return { exists: true, blockHeight: null, blockHash: null, confirmations: 0 };
    }
    const receipt = await this.provider.getTransactionReceipt(txid);
    // a reverted transaction moved no value - treat as gone for payment purposes
    if (receipt && receipt.status === 0) {
      return { exists: false, blockHeight: null, blockHash: null, confirmations: 0 };
    }
    const tipHeight = await this.provider.getBlockNumber();
    return {
      exists: true,
      blockHeight: tx.blockNumber,
      blockHash: receipt?.blockHash ?? null,
      confirmations: Math.max(0, tipHeight - tx.blockNumber + 1),
    };
  }

  async broadcast(signedTxHex: string): Promise<string> {
    return (await this.provider.send('eth_sendRawTransaction', [signedTxHex])) as string;
  }

  validateAddress(address: string): boolean {
    return isAddress(address);
  }

  async watchAddress(_address: string, _label: string): Promise<void> {
    // watching is scan-set based on EVM; nothing to register node-side
  }

  async getNativeBalance(address: string): Promise<string> {
    return (await this.provider.getBalance(getAddress(address))).toString();
  }

  async getTokenBalance(address: string, contract: string): Promise<string> {
    const data = `0x70a08231${getAddress(address).slice(2).padStart(64, '0').toLowerCase()}`;
    const result = await this.provider.call({ to: contract, data });
    return BigInt(result === '0x' ? 0 : result).toString();
  }

  async getTransactionCount(address: string): Promise<number> {
    return this.provider.getTransactionCount(getAddress(address), 'pending');
  }

  async getChainId(): Promise<number> {
    if (this.chainIdCache === null) {
      const network = await this.provider.getNetwork();
      this.chainIdCache = Number(network.chainId);
    }
    return this.chainIdCache;
  }

  async getFeeData(): Promise<{ maxFeePerGas: string; maxPriorityFeePerGas: string }> {
    const fees = await this.provider.getFeeData();
    if (fees.maxFeePerGas === null || fees.maxPriorityFeePerGas === null) {
      const gasPrice = fees.gasPrice ?? 20_000_000_000n;
      return {
        maxFeePerGas: (gasPrice * 2n).toString(),
        maxPriorityFeePerGas: (gasPrice / 10n + 1n).toString(),
      };
    }
    return {
      maxFeePerGas: fees.maxFeePerGas.toString(),
      maxPriorityFeePerGas: fees.maxPriorityFeePerGas.toString(),
    };
  }

  async estimateGas(tx: {
    from: string;
    to: string;
    value?: string;
    data?: string;
  }): Promise<string> {
    const estimate = await this.provider.estimateGas({
      from: tx.from,
      to: tx.to,
      value: tx.value ? BigInt(tx.value) : undefined,
      data: tx.data,
    });
    // 20% headroom
    return ((estimate * 12n) / 10n).toString();
  }
}
