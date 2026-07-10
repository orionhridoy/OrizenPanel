import { Controller, Get, ServiceUnavailableException } from '@nestjs/common';
import { DatabaseService } from '../database/database.service';

/** Worker liveness for the docker healthcheck (internal port only). */
@Controller('health')
export class WorkerHealthController {
  constructor(private readonly db: DatabaseService) {}

  @Get()
  async health(): Promise<{ status: 'ok'; role: 'worker'; time: string }> {
    try {
      await this.db.query('SELECT 1');
    } catch {
      throw new ServiceUnavailableException('database unreachable');
    }
    return { status: 'ok', role: 'worker', time: new Date().toISOString() };
  }
}
