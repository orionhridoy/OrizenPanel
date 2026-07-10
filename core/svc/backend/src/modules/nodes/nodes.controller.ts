import { Controller, Get, UseGuards } from '@nestjs/common';
import { NodesService, NodeStatusRow } from './nodes.service';
import { JwtAuthGuard } from '../../common/guards/jwt-auth.guard';
import { AdminGuard } from '../../common/guards/admin.guard';

@Controller('admin/nodes')
@UseGuards(JwtAuthGuard, AdminGuard)
export class NodesController {
  constructor(private readonly nodes: NodesService) {}

  @Get()
  async list(): Promise<NodeStatusRow[]> {
    return this.nodes.list();
  }
}
