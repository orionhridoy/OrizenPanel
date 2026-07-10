import { Inject, Injectable } from '@nestjs/common';
import { AssetCode, Chain } from '../../common/types';
import { ChainAdapter } from './chain-adapter.interface';

export const CHAIN_ADAPTERS = 'CHAIN_ADAPTERS' as const;

@Injectable()
export class AdapterRegistry {
  private readonly byChain = new Map<Chain, ChainAdapter>();
  private readonly byAsset = new Map<AssetCode, ChainAdapter>();

  constructor(@Inject(CHAIN_ADAPTERS) adapters: ChainAdapter[]) {
    for (const adapter of adapters) {
      this.byChain.set(adapter.chain, adapter);
      for (const asset of adapter.assets) this.byAsset.set(asset, adapter);
    }
  }

  forChain(chain: Chain): ChainAdapter {
    const adapter = this.byChain.get(chain);
    if (!adapter) throw new Error(`no adapter for chain ${chain}`);
    return adapter;
  }

  forAsset(asset: AssetCode): ChainAdapter {
    const adapter = this.byAsset.get(asset);
    if (!adapter) throw new Error(`no adapter for asset ${asset}`);
    return adapter;
  }

  chains(): Chain[] {
    return [...this.byChain.keys()];
  }

  all(): ChainAdapter[] {
    return [...this.byChain.values()];
  }
}
