import { Request } from 'express';

export interface AuthPrincipal {
  merchantId: string;
  role: 'MERCHANT' | 'ADMIN';
  /** present when authenticated via merchant API key */
  apiKeyId?: string;
  permissions?: string[];
}

export interface AuthenticatedRequest extends Request {
  principal: AuthPrincipal;
  /** raw body captured for HMAC verification */
  rawBody?: Buffer;
}

export type Chain = 'bitcoin' | 'litecoin' | 'ethereum' | 'xrp' | 'tron';

export type AssetCode = 'BTC' | 'LTC' | 'ETH' | 'XRP' | 'USDT_TRC20' | 'USDC_ERC20';

export interface AssetRow {
  code: AssetCode;
  chain: Chain;
  display_name: string;
  contract_address: string | null;
  decimals: number;
  min_confirmations: number;
  dust_threshold: string;
  invoice_ttl_seconds: number;
  enabled: boolean;
}
