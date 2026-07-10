import { Injectable, NotFoundException } from '@nestjs/common';
import { DatabaseService } from '../../database/database.service';

export interface AssetRow {
  code: string;
  chain: string;
  display_name: string;
  enabled: boolean;
  min_confirmations: number;
  invoice_ttl_seconds: number;
  decimals: number;
}

/**
 * Enable/disable which cryptocurrencies merchants can use. This is a pure
 * database flag (assets.enabled): disabled assets can't be selected for new
 * invoices / links / top-ups and are skipped by the payment engine's active
 * set. It does NOT start/stop node containers - the API is internet-facing and
 * never controls Docker. Run/stop nodes with `docker compose --profile ...`.
 */
@Injectable()
export class AssetsService {
  constructor(private readonly db: DatabaseService) {}

  async listAll(): Promise<AssetRow[]> {
    return this.db.query<AssetRow>(
      `SELECT code, chain, display_name, enabled, min_confirmations, invoice_ttl_seconds, decimals
         FROM assets ORDER BY code`,
    );
  }

  async toggle(code: string, enabled: boolean): Promise<AssetRow> {
    const row = await this.db.queryOne<AssetRow>(
      `UPDATE assets SET enabled = $2, updated_at = now()
        WHERE code = $1
        RETURNING code, chain, display_name, enabled, min_confirmations, invoice_ttl_seconds, decimals`,
      [code, enabled],
    );
    if (!row) throw new NotFoundException(`asset ${code} not found`);
    return row;
  }
}
