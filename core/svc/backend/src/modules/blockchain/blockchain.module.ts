import { Global, Module } from '@nestjs/common';
import { JsonRpcProvider } from 'ethers';
import { Client as XrplClient } from 'xrpl';
import { networks } from 'bitcoinjs-lib';
import { APP_CONFIG, AppConfig } from '../../config/config';
import { DatabaseService } from '../../database/database.service';
import { AdapterRegistry, CHAIN_ADAPTERS } from './adapter.registry';
import { ChainAdapter } from './chain-adapter.interface';
import { JsonRpcClient } from './rpc/json-rpc.client';
import { BitcoinLikeAdapter } from './adapters/bitcoin/bitcoin-like.adapter';
import { EsploraAdapter } from './adapters/bitcoin/esplora.adapter';
import { LITECOIN_NETWORK } from './adapters/litecoin/litecoin.network';
import { EthereumAdapter } from './adapters/ethereum/ethereum.adapter';
import { XrpAdapter } from './adapters/xrp/xrp.adapter';
import { TronAdapter } from './adapters/tron/tron.adapter';
import { AssetRow, AssetCode } from '../../common/types';

/**
 * Addresses whose UTXOs the explorer adapter must list for sweeps/withdrawals:
 * the treasury plus any deposit address holding a confirmed, not-yet-swept
 * payment. Bounded (swept addresses drop out) so we never scan every address.
 */
function utxoAddressProvider(db: DatabaseService, owner: AssetCode) {
  return async (): Promise<string[]> => {
    const rows = await db.query<{ address: string }>(
      `SELECT w.address AS address
         FROM wallets w
        WHERE w.asset_code = $1 AND w.type = 'TREASURY' AND w.is_active AND w.address IS NOT NULL
        UNION
       SELECT DISTINCT wa.address
         FROM payments p
         JOIN wallet_addresses wa ON wa.id = p.address_id
         JOIN wallets w ON w.id = wa.wallet_id
        WHERE p.asset_code = $1 AND p.status = 'CONFIRMED' AND p.credited
          AND w.type = 'DEPOSIT_HD'
          AND NOT EXISTS (SELECT 1 FROM sweep_inputs si WHERE si.payment_id = p.id)`,
      [owner],
    );
    return rows.map((r) => r.address);
  };
}

@Global()
@Module({
  providers: [
    {
      provide: CHAIN_ADAPTERS,
      useFactory: async (config: AppConfig, db: DatabaseService): Promise<ChainAdapter[]> => {
        // token contracts are configuration data owned by the assets table
        const assets = await db.query<AssetRow>(
          `SELECT code, chain, display_name, contract_address, decimals,
                  min_confirmations, dust_threshold, invoice_ttl_seconds, enabled
             FROM assets`,
        );
        const usdc = assets.find((a) => a.code === 'USDC_ERC20');
        const usdt = assets.find((a) => a.code === 'USDT_TRC20');
        if (!usdc?.contract_address || !usdt?.contract_address) {
          throw new Error('token contract addresses missing from assets table');
        }

        // BTC/LTC: an Esplora explorer URL (mempool.space / litecoinspace) means
        // zero local storage - detection runs over HTTP against watched
        // addresses. Otherwise fall back to a self-hosted node's watch-only wallet.
        const bitcoin: ChainAdapter = config.bitcoinEsploraUrl
          ? new EsploraAdapter(
              'bitcoin',
              ['BTC'],
              config.bitcoinEsploraUrl,
              networks.bitcoin,
              utxoAddressProvider(db, 'BTC'),
            )
          : new BitcoinLikeAdapter(
              'bitcoin',
              ['BTC'],
              new JsonRpcClient(config.bitcoinRpcUrl, config.bitcoinRpcUser, config.bitcoinRpcPassword),
              networks.bitcoin,
              10,
            );
        const litecoin: ChainAdapter = config.litecoinEsploraUrl
          ? new EsploraAdapter(
              'litecoin',
              ['LTC'],
              config.litecoinEsploraUrl,
              LITECOIN_NETWORK,
              utxoAddressProvider(db, 'LTC'),
            )
          : new BitcoinLikeAdapter(
              'litecoin',
              ['LTC'],
              new JsonRpcClient(config.litecoinRpcUrl, config.litecoinRpcUser, config.litecoinRpcPassword),
              LITECOIN_NETWORK,
              2,
            );

        return [
          bitcoin,
          litecoin,
          new EthereumAdapter(new JsonRpcProvider(config.ethRpcUrl, undefined, { polling: true }), [
            { assetCode: 'USDC_ERC20', contract: usdc.contract_address },
          ]),
          new XrpAdapter(new XrplClient(config.xrplWsUrl, { timeout: 20_000 })),
          new TronAdapter(config.tronHttpUrl, usdt.contract_address, config.tronApiKey),
        ];
      },
      inject: [APP_CONFIG, DatabaseService],
    },
    AdapterRegistry,
  ],
  exports: [AdapterRegistry],
})
export class BlockchainModule {}
