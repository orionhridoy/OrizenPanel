export interface InvoiceView {
  id: string;
  orderId: string | null;
  status: string;
  asset: string;
  chain: string;
  amountDue: string;
  amountDueDecimal: string;
  fiatCurrency: string | null;
  fiatAmount: string | null;
  exchangeRate: string | null;
  amountPaidPending: string;
  amountPaidConfirmed: string;
  address: string;
  destinationTag: number | null;
  paymentUri: string;
  requiredConfirmations: number;
  description: string | null;
  redirectUrl: string | null;
  checkoutUrl: string;
  expiresAt: string;
  paidAt: string | null;
  createdAt: string;
  merchantName?: string;
}

export interface BalanceRow {
  asset_code: string;
  type: string;
  balance: string;
}

export interface ChainStatus {
  chain: string;
  synced: boolean;
  height: number;
  peers: number;
  progress: number;
  engineActive: boolean;
}

export interface ApiKeyRow {
  id: string;
  label: string;
  key_prefix: string;
  permissions: string[];
  last_used_at: string | null;
  revoked_at: string | null;
  created_at: string;
}

export interface WebhookEndpoint {
  id: string;
  url: string;
  events: string[];
  is_active: boolean;
  created_at: string;
  secret?: string;
}

export interface WithdrawalRow {
  id: string;
  asset_code: string;
  amount: string;
  network_fee: string | null;
  destination_address: string;
  destination_tag: string | null;
  status: string;
  requires_admin_approval?: boolean;
  txid: string | null;
  error?: string | null;
  created_at: string;
  merchant_email?: string;
  risk_flags?: string[];
}

export interface MerchantProfile {
  id: string;
  email: string;
  name: string;
  role: string;
  status: string;
  totp_enabled: boolean;
  settlement_mode: string;
  settlement_schedule_cron: string | null;
  underpayment_tolerance_bps: number;
  default_invoice_ttl_seconds: number | null;
  created_at: string;
}

export interface StoreConfig {
  storeEnabled: boolean;
  storeName: string | null;
  storeId: string;
}

export interface StoreBalance {
  asset: string;
  balanceBaseUnits: string;
  balanceDecimal: string;
}

export interface StoreSessionView {
  storeName: string;
  merchantName: string;
  externalRef: string;
  assets: Array<{ code: string; displayName: string; decimals: number; minTopupDecimal: string }>;
  balances: StoreBalance[];
  publicUrl: string;
}

export interface AssetRow {
  code: string;
  chain: string;
  display_name: string;
  enabled: boolean;
  min_confirmations: number;
  invoice_ttl_seconds: number;
  decimals: number;
}

export interface NodeStatus {
  chain: string;
  height: string;
  peers: number;
  synced: boolean;
  progress: string;
  engine_active: boolean;
  last_error: string | null;
  updated_at: string;
}

export const ASSET_DECIMALS: Record<string, number> = {
  BTC: 8,
  LTC: 8,
  ETH: 18,
  XRP: 6,
  USDT_TRC20: 6,
  USDC_ERC20: 6,
};

/** Public block-explorer URL for a transaction, per asset. */
export function explorerTxUrl(asset: string, txid: string): string | null {
  switch (asset) {
    case 'BTC':
      return `https://www.blockchain.com/en/explorer/transactions/btc/${txid}`;
    case 'LTC':
      return `https://litecoinspace.org/tx/${txid}`;
    case 'ETH':
    case 'USDC_ERC20':
      return `https://etherscan.io/tx/${txid}`;
    case 'USDT_TRC20':
      return `https://tronscan.org/#/transaction/${txid}`;
    case 'XRP':
      return `https://xrpscan.com/tx/${txid}`;
    default:
      return null;
  }
}

export function fromBase(amount: string, asset: string): string {
  const decimals = ASSET_DECIMALS[asset] ?? 8;
  const padded = amount.padStart(decimals + 1, '0');
  const whole = padded.slice(0, -decimals) || '0';
  const fraction = padded.slice(-decimals).replace(/0+$/, '');
  return fraction ? `${whole}.${fraction}` : whole;
}
