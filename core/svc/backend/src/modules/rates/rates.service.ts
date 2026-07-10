import { BadRequestException, Injectable, Logger, ServiceUnavailableException } from '@nestjs/common';
import { AssetCode } from '../../common/types';
import { decimalToBaseUnits } from '../../common/utils/base-units.util';
import { DatabaseService } from '../../database/database.service';

const COINGECKO_URL = 'https://api.coingecko.com/api/v3/simple/price';
const CACHE_TTL_MS = 60_000;
const SUPPORTED_FIAT = ['USD', 'EUR', 'GBP'];

/**
 * Max decimal places a payer is asked to send. Wallets and exchanges
 * (Binance & co.) reject sends with more precision than they support, so a
 * fiat conversion must never produce an amount like 0.008398985402563369 ETH.
 * BTC/LTC keep the 8-decimal satoshi standard; everything else uses 6.
 */
const PAYMENT_PRECISION: Record<AssetCode, number> = {
  BTC: 8,
  LTC: 8,
  ETH: 6,
  XRP: 6,
  USDT_TRC20: 6,
  USDC_ERC20: 6,
};

const ASSET_TO_ID: Record<AssetCode, string> = {
  BTC: 'bitcoin',
  LTC: 'litecoin',
  ETH: 'ethereum',
  XRP: 'ripple',
  USDT_TRC20: 'tether',
  USDC_ERC20: 'usd-coin',
};

/**
 * Live crypto↔fiat rates for fiat-priced invoices. Fetches from CoinGecko
 * (no key required) and caches for 60s. If rates are unavailable the caller
 * gets a clear error and can fall back to crypto-denominated amounts - the
 * gateway's core payment flow never depends on external rates.
 */
@Injectable()
export class RatesService {
  private readonly logger = new Logger(RatesService.name);
  private cache: { at: number; data: Record<string, Record<string, number>> } | null = null;
  private inflight: Promise<Record<string, Record<string, number>>> | null = null;

  constructor(private readonly db: DatabaseService) {}

  supportedFiat(): string[] {
    return SUPPORTED_FIAT;
  }

  private async fetchRates(): Promise<Record<string, Record<string, number>>> {
    if (this.cache && Date.now() - this.cache.at < CACHE_TTL_MS) return this.cache.data;
    if (this.inflight) return this.inflight;
    const ids = Object.values(ASSET_TO_ID).join(',');
    const vs = SUPPORTED_FIAT.map((c) => c.toLowerCase()).join(',');
    const url = `${COINGECKO_URL}?ids=${ids}&vs_currencies=${vs}`;
    this.inflight = (async () => {
      const controller = new AbortController();
      const timer = setTimeout(() => controller.abort(), 8000);
      try {
        const response = await fetch(url, { signal: controller.signal, headers: { accept: 'application/json' } });
        if (!response.ok) throw new Error(`rates provider HTTP ${response.status}`);
        const data = (await response.json()) as Record<string, Record<string, number>>;
        this.cache = { at: Date.now(), data };
        return data;
      } finally {
        clearTimeout(timer);
        this.inflight = null;
      }
    })();
    return this.inflight;
  }

  /** Price of 1 unit of the asset in the given fiat currency. */
  async rate(assetCode: AssetCode, fiat: string): Promise<number> {
    const currency = fiat.toUpperCase();
    if (!SUPPORTED_FIAT.includes(currency)) {
      throw new BadRequestException(`unsupported fiat currency ${fiat}`);
    }
    let data: Record<string, Record<string, number>>;
    try {
      data = await this.fetchRates();
    } catch (err) {
      this.logger.warn(`rates fetch failed: ${(err as Error).message}`);
      throw new ServiceUnavailableException('exchange rates unavailable - price in crypto instead');
    }
    const id = ASSET_TO_ID[assetCode];
    const price = data[id]?.[currency.toLowerCase()];
    if (!price || price <= 0) throw new ServiceUnavailableException(`no rate for ${assetCode}/${currency}`);
    return price;
  }

  /**
   * Convert a fiat amount to a crypto amount (decimal string) at the current
   * rate, returning the crypto amount, its base-unit string and the rate used.
   */
  async fiatToCrypto(
    fiatAmount: string,
    assetCode: AssetCode,
    fiat: string,
    decimals: number,
  ): Promise<{ cryptoDecimal: string; amountBaseUnits: string; rate: number }> {
    const fiatValue = Number(fiatAmount);
    if (!Number.isFinite(fiatValue) || fiatValue <= 0) throw new BadRequestException('invalid fiat amount');
    const rate = await this.rate(assetCode, fiat);
    // round the crypto amount UP (merchant never under-collects) to a
    // precision wallets/exchanges actually accept - not the chain's full
    // precision (18 decimals of ETH would be unsendable from Binance)
    const places = Math.min(decimals, PAYMENT_PRECISION[assetCode] ?? 6);
    const raw = fiatValue / rate;
    const factor = 10 ** places;
    const rounded = Math.ceil(raw * factor) / factor;
    const cryptoDecimal = rounded.toFixed(places);
    return {
      cryptoDecimal,
      amountBaseUnits: decimalToBaseUnits(cryptoDecimal, decimals),
      rate,
    };
  }

  async allRates(): Promise<Record<string, Record<string, number>>> {
    try {
      return await this.fetchRates();
    } catch {
      return {};
    }
  }
}
