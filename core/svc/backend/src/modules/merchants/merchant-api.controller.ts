import { Controller, Get, UseGuards } from '@nestjs/common';
import { ApiKeyGuard, RequirePermission } from '../../common/guards/api-key.guard';
import { CurrentPrincipal } from '../../common/decorators/current-principal.decorator';
import { AuthPrincipal } from '../../common/types';
import { BalanceRow, LedgerService } from '../ledger/ledger.service';

/** API-key surface for programmatic balance queries. */
@Controller('merchant/balances')
@UseGuards(ApiKeyGuard)
export class MerchantApiController {
  constructor(private readonly ledger: LedgerService) {}

  @Get()
  @RequirePermission('balances:read')
  async balances(@CurrentPrincipal() principal: AuthPrincipal): Promise<BalanceRow[]> {
    return this.ledger.merchantBalances(principal.merchantId);
  }
}
