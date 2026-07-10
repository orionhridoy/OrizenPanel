import {
  BadRequestException,
  Body,
  Controller,
  Get,
  Ip,
  Param,
  Patch,
  PipeTransform,
  UseGuards,
} from '@nestjs/common';
import { IsBoolean } from 'class-validator';
import { SyncService } from './sync.service';
import { NodesService } from '../nodes/nodes.service';
import { AuditService } from '../audit/audit.service';
import { JwtAuthGuard } from '../../common/guards/jwt-auth.guard';
import { AdminGuard } from '../../common/guards/admin.guard';
import { CurrentPrincipal } from '../../common/decorators/current-principal.decorator';
import { AuthPrincipal, Chain } from '../../common/types';

const CHAINS = ['bitcoin', 'litecoin', 'ethereum', 'xrp', 'tron'] as const;

class ChainValidationPipe implements PipeTransform {
  transform(value: string): Chain {
    if (!(CHAINS as readonly string[]).includes(value)) {
      throw new BadRequestException(`unknown chain "${value}"`);
    }
    return value as Chain;
  }
}

class SyncToggleDto {
  @IsBoolean()
  enabled!: boolean;
}

@Controller('admin/sync')
@UseGuards(JwtAuthGuard, AdminGuard)
export class SyncController {
  constructor(
    private readonly sync: SyncService,
    private readonly nodes: NodesService,
    private readonly audit: AuditService,
  ) {}

  /** Per-chain node status merged with each chain's live-sync switch. */
  @Get()
  async status(): Promise<{ chains: Array<Record<string, unknown>> }> {
    const [states, nodeRows] = await Promise.all([this.sync.getStates(), this.nodes.list()]);
    const byChain = new Map(nodeRows.map((n) => [n.chain, n]));
    const chains = CHAINS.map((chain) => ({
      ...(byChain.get(chain) ?? {
        chain, height: '0', peers: 0, synced: false, progress: '0', engine_active: false, last_error: null,
      }),
      syncEnabled: states[chain] ?? true,
    }));
    return { chains };
  }

  /** Pause/resume ALL chains at once. */
  @Patch()
  async setAll(
    @CurrentPrincipal() principal: AuthPrincipal,
    @Body() dto: SyncToggleDto,
    @Ip() ip: string,
  ): Promise<{ ok: true }> {
    await this.sync.setAll(dto.enabled);
    await this.audit.log({
      actorType: 'ADMIN',
      actorId: principal.merchantId,
      action: dto.enabled ? 'sync.enabled_all' : 'sync.disabled_all',
      ip,
    });
    return { ok: true };
  }

  /** Pause/resume one chain. */
  @Patch(':chain')
  async setChain(
    @CurrentPrincipal() principal: AuthPrincipal,
    @Param('chain', ChainValidationPipe) chain: Chain,
    @Body() dto: SyncToggleDto,
    @Ip() ip: string,
  ): Promise<{ chain: Chain; syncEnabled: boolean }> {
    await this.sync.setChain(chain, dto.enabled);
    await this.audit.log({
      actorType: 'ADMIN',
      actorId: principal.merchantId,
      action: dto.enabled ? 'sync.enabled' : 'sync.disabled',
      resourceType: 'chain',
      resourceId: chain,
      ip,
    });
    return { chain, syncEnabled: dto.enabled };
  }
}
