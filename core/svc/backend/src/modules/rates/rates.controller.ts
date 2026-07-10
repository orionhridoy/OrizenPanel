import { Controller, Get } from '@nestjs/common';
import { RatesService } from './rates.service';

/** Public: current rates so checkout/store pages can show fiat equivalents. */
@Controller('public/rates')
export class RatesController {
  constructor(private readonly rates: RatesService) {}

  @Get()
  async current(): Promise<{ fiat: string[]; rates: Record<string, Record<string, number>> }> {
    return { fiat: this.rates.supportedFiat(), rates: await this.rates.allRates() };
  }
}
