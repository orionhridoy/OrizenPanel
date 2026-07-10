import { Controller, Get, UseGuards } from '@nestjs/common';
import { WalletsService } from './wallets.service';
import { DatabaseService } from '../../database/database.service';
import { JwtAuthGuard } from '../../common/guards/jwt-auth.guard';
import { AdminGuard } from '../../common/guards/admin.guard';

@Controller('admin/wallets')
@UseGuards(JwtAuthGuard, AdminGuard)
export class WalletsController {
  constructor(
    private readonly wallets: WalletsService,
    private readonly db: DatabaseService,
  ) {}

  @Get()
  async list(): Promise<Array<Record<string, unknown>>> {
    // xpubs are public keys but still not exposed wholesale; show fingerprints
    const rows = await this.wallets.listWallets();
    return rows.map((w) => ({
      id: w.id,
      assetCode: w.asset_code,
      type: w.type,
      name: w.name,
      address: w.address,
      xpubFingerprint: w.xpub ? `${w.xpub.slice(0, 12)}...${w.xpub.slice(-6)}` : null,
      issuedAddresses: Number(w.next_index),
      isActive: w.is_active,
    }));
  }

  @Get('sweeps')
  async sweeps(): Promise<Array<Record<string, unknown>>> {
    return this.db.query(
      `SELECT s.id, s.asset_code, s.status, s.total_amount::text, s.network_fee::text,
              s.txid, s.error, s.created_at, s.confirmed_at,
              COUNT(si.id)::int AS input_count
         FROM sweeps s LEFT JOIN sweep_inputs si ON si.sweep_id = s.id
        GROUP BY s.id ORDER BY s.created_at DESC LIMIT 100`,
    );
  }

  @Get('treasury-balances')
  async treasuryBalances(): Promise<Array<Record<string, unknown>>> {
    return this.db.query(
      `SELECT a.asset_code, a.type, COALESCE(b.balance, '0')::text AS balance
         FROM ledger_accounts a
         LEFT JOIN account_balances b ON b.account_id = a.id
        WHERE a.merchant_id IS NULL
        ORDER BY a.asset_code, a.type`,
    );
  }
}
