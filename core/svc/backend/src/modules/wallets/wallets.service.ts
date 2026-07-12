import { Injectable, Logger, ServiceUnavailableException } from '@nestjs/common';
import { PoolClient } from 'pg';
import { DatabaseService } from '../../database/database.service';
import { AuditService } from '../audit/audit.service';
import { AdapterRegistry } from '../blockchain/adapter.registry';
import { AssetCode, Chain } from '../../common/types';
import { SignerClientService } from './signer-client.service';
import {
  deriveBtcAddress,
  deriveEthAddress,
  deriveLtcAddress,
  deriveTronAddress,
} from './hd.util';

/** Chain -> asset code that owns the chain's wallet rows. */
export const CHAIN_WALLET_OWNER: Record<Chain, AssetCode> = {
  bitcoin: 'BTC',
  litecoin: 'LTC',
  ethereum: 'ETH',
  xrp: 'XRP',
  tron: 'USDT_TRC20',
};

/** XRP destination tags start here (small tags are error-prone for users). */
const XRP_TAG_OFFSET = 1000;

export interface WalletRow {
  id: string;
  asset_code: AssetCode;
  type: string;
  name: string;
  xpub: string | null;
  address: string | null;
  next_index: string;
  metadata: { walletRef?: string };
  is_active: boolean;
}

export interface IssuedAddress {
  addressId: string;
  address: string;
  destinationTag: number | null;
  derivationIndex: number | null;
}

@Injectable()
export class WalletsService {
  private readonly logger = new Logger(WalletsService.name);

  constructor(
    private readonly db: DatabaseService,
    private readonly signer: SignerClientService,
    private readonly adapters: AdapterRegistry,
    private readonly audit: AuditService,
  ) {}

  /**
   * Creates deposit + treasury wallets per chain via the signer if missing.
   * Runs from the WORKER role only - the api container is deliberately not
   * attached to the signing network.
   */
  async ensureBootstrapped(): Promise<void> {
    for (const chain of Object.keys(CHAIN_WALLET_OWNER) as Chain[]) {
      const owner = CHAIN_WALLET_OWNER[chain];
      await this.ensureWallet(chain, owner, 'DEPOSIT_HD', 'deposit');
      const treasury = await this.ensureWallet(chain, owner, 'TREASURY', 'treasury');
      if (treasury.address) {
        await this.signer.trustDestination(chain, treasury.address);
        // UTXO chains: withdrawals spend treasury UTXOs, so the node's
        // watch-only wallet must track the treasury address too. Best-effort:
        // the node may still be syncing or (with external nodes) not yet
        // reachable - don't block bootstrap on it. It is re-attempted on the
        // next worker start, and deposit addresses are (re)registered at issuance.
        if (chain === 'bitcoin' || chain === 'litecoin') {
          try {
            await this.adapters.forChain(chain).watchAddress(treasury.address, 'treasury');
          } catch (err) {
            this.logger.warn(
              `treasury watch registration deferred for ${chain} (node unreachable): ${(err as Error).message}`,
            );
          }
        }
      }
    }
  }

  private async ensureWallet(
    chain: Chain,
    owner: AssetCode,
    type: 'DEPOSIT_HD' | 'TREASURY',
    purpose: 'deposit' | 'treasury',
  ): Promise<WalletRow> {
    const existing = await this.db.queryOne<WalletRow>(
      `SELECT id, asset_code, type, name, xpub, address, next_index::text, metadata, is_active
         FROM wallets WHERE asset_code = $1 AND type = $2 AND is_active`,
      [owner, type],
    );
    if (existing) return existing;

    const created = await this.signer.createWallet(chain, purpose);
    const name = `${chain} ${purpose}`;
    const row = await this.db.queryOne<WalletRow>(
      `INSERT INTO wallets (asset_code, type, name, xpub, address, derivation_path, metadata)
       VALUES ($1, $2, $3, $4, $5, $6, $7)
       RETURNING id, asset_code, type, name, xpub, address, next_index::text, metadata, is_active`,
      [
        owner,
        type,
        name,
        created.xpub,
        created.address,
        this.accountPath(chain),
        JSON.stringify({ walletRef: created.walletRef }),
      ],
    );
    await this.audit.log({
      actorType: 'SYSTEM',
      action: 'wallet.created',
      resourceType: 'wallet',
      resourceId: row?.id,
      metadata: { chain, type, xpub: created.xpub, address: created.address },
    });
    this.logger.log(`created ${type} wallet for ${chain}`);
    return row as WalletRow;
  }

  private accountPath(chain: Chain): string {
    switch (chain) {
      case 'bitcoin':
        return "m/84'/0'/0'";
      case 'litecoin':
        return "m/84'/2'/0'";
      case 'ethereum':
        return "m/44'/60'/0'";
      case 'tron':
        return "m/44'/195'/0'";
      case 'xrp':
        return "m/44'/144'/0'/0/0";
    }
  }

  async depositWallet(chain: Chain): Promise<WalletRow | null> {
    return this.db.queryOne<WalletRow>(
      `SELECT id, asset_code, type, name, xpub, address, next_index::text, metadata, is_active
         FROM wallets WHERE asset_code = $1 AND type = 'DEPOSIT_HD' AND is_active`,
      [CHAIN_WALLET_OWNER[chain]],
    );
  }

  async treasuryWallet(chain: Chain): Promise<WalletRow | null> {
    return this.db.queryOne<WalletRow>(
      `SELECT id, asset_code, type, name, xpub, address, next_index::text, metadata, is_active
         FROM wallets WHERE asset_code = $1 AND type = 'TREASURY' AND is_active`,
      [CHAIN_WALLET_OWNER[chain]],
    );
  }

  /**
   * Allocates a NEVER-REUSED address (or XRP destination tag) inside the
   * caller's transaction. Index allocation is atomic via UPDATE...RETURNING.
   */
  async issueAddress(
    client: PoolClient,
    chain: Chain,
    assetCode: AssetCode,
    label: string,
  ): Promise<IssuedAddress> {
    const owner = CHAIN_WALLET_OWNER[chain];
    const wallet = await client.query<WalletRow & { allocated: string }>(
      `UPDATE wallets SET next_index = next_index + 1
        WHERE asset_code = $1 AND type = 'DEPOSIT_HD' AND is_active
        RETURNING id, xpub, address, metadata, (next_index - 1)::text AS allocated`,
      [owner],
    );
    const row = wallet.rows[0];
    if (!row) {
      throw new ServiceUnavailableException(
        `deposit wallet for ${chain} not initialized yet - workers still bootstrapping`,
      );
    }
    const index = Number(row.allocated);

    let address: string;
    let destinationTag: number | null = null;
    let derivationIndex: number | null = index;

    switch (chain) {
      case 'bitcoin':
        address = deriveBtcAddress(row.xpub as string, index);
        break;
      case 'litecoin':
        address = deriveLtcAddress(row.xpub as string, index);
        break;
      case 'ethereum':
        address = deriveEthAddress(row.xpub as string, index);
        break;
      case 'tron':
        address = deriveTronAddress(row.xpub as string, index);
        break;
      case 'xrp':
        address = row.address as string;
        destinationTag = XRP_TAG_OFFSET + index;
        derivationIndex = null;
        break;
    }

    const inserted = await client.query<{ id: string }>(
      `INSERT INTO wallet_addresses
         (wallet_id, asset_code, derivation_index, address, destination_tag, is_used)
       VALUES ($1, $2, $3, $4, $5, true)
       RETURNING id`,
      [row.id, assetCode, derivationIndex, address, destinationTag],
    );

    // register with the node's watch-only wallet where applicable
    await this.adapters.forChain(chain).watchAddress(address, label);

    return {
      addressId: inserted.rows[0].id,
      address,
      destinationTag,
      derivationIndex,
    };
  }

  /** Canonical watch keys for a chain (see ChainAdapter watch-key contract). */
  async watchSet(chain: Chain): Promise<Set<string>> {
    const rows = await this.db.query<{ address: string; destination_tag: string | null }>(
      `SELECT wa.address, wa.destination_tag::text
         FROM wallet_addresses wa
         JOIN wallets w ON w.id = wa.wallet_id
        WHERE w.asset_code = $1 AND w.type = 'DEPOSIT_HD'`,
      [CHAIN_WALLET_OWNER[chain]],
    );
    return this.toWatchKeys(rows);
  }

  /**
   * Bounded watch keys for explorer-backed adapters (usesAddressPolling): only
   * addresses of invoices that can still receive a payment - open invoices plus
   * a grace window for late arrivals (settings: payments.late_grace_hours,
   * default 24). Keeps per-address HTTP polling small.
   */
  async activeWatchSet(chain: Chain): Promise<Set<string>> {
    const rows = await this.db.query<{ address: string; destination_tag: string | null }>(
      `SELECT wa.address, wa.destination_tag::text
         FROM wallet_addresses wa
         JOIN wallets w ON w.id = wa.wallet_id
         JOIN invoices i ON i.address_id = wa.id
        WHERE w.asset_code = $1 AND w.type = 'DEPOSIT_HD'
          AND (i.status IN ('NEW', 'SEEN', 'CONFIRMING', 'UNDERPAID')
               OR i.expires_at > now() - make_interval(hours => COALESCE(
                    (SELECT LEAST((value)::int, 720) FROM settings
                      WHERE key = 'payments.late_grace_hours'), 24)))`,
      [CHAIN_WALLET_OWNER[chain]],
    );
    return this.toWatchKeys(rows);
  }

  private toWatchKeys(rows: Array<{ address: string; destination_tag: string | null }>): Set<string> {
    const keys = new Set<string>();
    for (const row of rows) {
      keys.add(
        row.destination_tag !== null ? `${row.address}:${row.destination_tag}` : row.address,
      );
    }
    return keys;
  }

  /** Resolve a detected transfer back to the issued address row. */
  async resolveAddress(
    chain: Chain,
    address: string,
    destinationTag: number | null,
  ): Promise<{ id: string; invoice_id: string | null } | null> {
    return this.db.queryOne<{ id: string; invoice_id: string | null }>(
      `SELECT wa.id, i.id AS invoice_id
         FROM wallet_addresses wa
         LEFT JOIN invoices i ON i.address_id = wa.id
        WHERE wa.address = $1 AND wa.destination_tag IS NOT DISTINCT FROM $2`,
      [address, destinationTag],
    );
  }

  async listWallets(): Promise<WalletRow[]> {
    return this.db.query<WalletRow>(
      `SELECT id, asset_code, type, name, xpub, address, next_index::text, metadata, is_active
         FROM wallets ORDER BY asset_code, type`,
    );
  }
}
