import { Controller, Get, Header } from '@nestjs/common';
import { MetricsService } from './metrics.service';

/**
 * Served on the internal port only; nginx denies /metrics at the edge and the
 * metrics docker network is not routable from outside.
 */
@Controller('metrics')
export class MetricsController {
  constructor(private readonly metrics: MetricsService) {}

  @Get()
  @Header('Content-Type', 'text/plain; version=0.0.4; charset=utf-8')
  async metricsEndpoint(): Promise<string> {
    return this.metrics.render();
  }
}
