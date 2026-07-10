import {
  IsIn,
  IsInt,
  IsObject,
  IsOptional,
  IsString,
  Matches,
  Max,
  MaxLength,
  Min,
} from 'class-validator';
import { Type } from 'class-transformer';
import { AssetCode } from '../../common/types';

export const ASSET_CODES: AssetCode[] = ['BTC', 'LTC', 'ETH', 'XRP', 'USDT_TRC20', 'USDC_ERC20'];

export const INVOICE_STATUSES = [
  'NEW',
  'SEEN',
  'CONFIRMING',
  'PAID',
  'UNDERPAID',
  'OVERPAID',
  'EXPIRED',
  'INVALID',
] as const;

export const FIAT_CURRENCIES = ['USD', 'EUR', 'GBP'] as const;

export class CreateInvoiceDto {
  @IsIn(ASSET_CODES)
  assetCode!: AssetCode;

  // Provide EITHER amount (crypto) OR fiatAmount+fiatCurrency (auto-converted).
  @IsOptional()
  @IsString()
  @Matches(/^\d+(\.\d+)?$/, { message: 'amount must be a positive decimal string' })
  @MaxLength(40)
  amount?: string;

  @IsOptional()
  @IsString()
  @Matches(/^\d+(\.\d{1,2})?$/, { message: 'fiatAmount must be a positive amount' })
  @MaxLength(20)
  fiatAmount?: string;

  @IsOptional()
  @IsIn(FIAT_CURRENCIES as unknown as string[])
  fiatCurrency?: string;

  @IsOptional()
  @IsString()
  @MaxLength(120)
  orderId?: string;

  @IsOptional()
  @IsString()
  @MaxLength(500)
  description?: string;

  @IsOptional()
  @IsString()
  @Matches(/^https:\/\//, { message: 'redirectUrl must be https' })
  @MaxLength(500)
  redirectUrl?: string;

  @IsOptional()
  @IsObject()
  metadata?: Record<string, unknown>;

  // custom expiry window: 5 minutes to 30 days (defaults to the asset TTL)
  @IsOptional()
  @Type(() => Number)
  @IsInt()
  @Min(5)
  @Max(43200)
  expiresInMinutes?: number;
}

export class ListInvoicesDto {
  @IsOptional()
  @IsIn(INVOICE_STATUSES as unknown as string[])
  status?: string;

  @IsOptional()
  @Type(() => Number)
  @IsInt()
  @Min(1)
  @Max(100)
  limit = 25;

  @IsOptional()
  @Type(() => Number)
  @IsInt()
  @Min(0)
  offset = 0;
}
