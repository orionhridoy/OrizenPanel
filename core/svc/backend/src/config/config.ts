/**
 * Environment configuration, validated at process start.
 * The process refuses to boot on missing/weak/CHANGE_ME values - there is no
 * such thing as a partially configured payment gateway.
 */
export interface AppConfig {
  readonly nodeEnv: 'production' | 'development' | 'test';
  readonly appRole: 'api' | 'worker';
  readonly appDomain: string;
  readonly publicUrl: string;
  readonly httpPort: number;
  readonly databaseUrl: string;
  readonly redisUrl: string;
  readonly jwtAccessSecret: string;
  readonly jwtRefreshSecret: string;
  readonly appEncryptionKey: string;
  readonly adminEmail: string;
  readonly adminInitialPassword: string;
  readonly signerUrl: string;
  readonly signerHmacKey: string;
  readonly bitcoinRpcUrl: string;
  readonly bitcoinRpcUser: string;
  readonly bitcoinRpcPassword: string;
  readonly litecoinRpcUrl: string;
  readonly litecoinRpcUser: string;
  readonly litecoinRpcPassword: string;
  /** When set, BTC/LTC detection uses this Esplora explorer API (zero storage) instead of a node. */
  readonly bitcoinEsploraUrl: string | null;
  readonly litecoinEsploraUrl: string | null;
  readonly ethRpcUrl: string;
  readonly xrplWsUrl: string;
  readonly tronHttpUrl: string;
  /** Optional TronGrid API key - lifts the anonymous rate limit that causes 429s. */
  readonly tronApiKey: string | null;
  /** Shared secret with the Orizen control panel, for panel-driven SSO + admin password reset. */
  readonly panelSsoSecret: string | null;
  readonly rateLimitTtl: number;
  readonly rateLimitMax: number;
}

class ConfigError extends Error {}

function required(name: string): string {
  const value = process.env[name];
  if (value === undefined || value.trim() === '') {
    throw new ConfigError(`Missing required environment variable: ${name}`);
  }
  return value.trim();
}

function secret(name: string, minLength = 32): string {
  const value = required(name);
  if (value.includes('CHANGE_ME')) {
    throw new ConfigError(`${name} still contains a CHANGE_ME placeholder - run install.sh`);
  }
  if (value.length < minLength) {
    throw new ConfigError(`${name} must be at least ${minLength} characters`);
  }
  return value;
}

function integer(name: string, fallback: number): number {
  const raw = process.env[name];
  if (raw === undefined || raw.trim() === '') return fallback;
  const parsed = Number.parseInt(raw, 10);
  if (!Number.isFinite(parsed) || parsed <= 0) {
    throw new ConfigError(`${name} must be a positive integer, got "${raw}"`);
  }
  return parsed;
}

function oneOf<T extends string>(name: string, allowed: readonly T[], fallback: T): T {
  const raw = (process.env[name] ?? fallback).trim() as T;
  if (!allowed.includes(raw)) {
    throw new ConfigError(`${name} must be one of ${allowed.join(', ')}, got "${raw}"`);
  }
  return raw;
}

let cached: AppConfig | null = null;

export function loadConfig(): AppConfig {
  if (cached) return cached;
  const appEncryptionKey = secret('APP_ENCRYPTION_KEY', 64);
  if (!/^[0-9a-f]{64}$/i.test(appEncryptionKey)) {
    throw new ConfigError('APP_ENCRYPTION_KEY must be 64 hex characters (openssl rand -hex 32)');
  }
  const appDomain = required('APP_DOMAIN');
  cached = {
    nodeEnv: oneOf('NODE_ENV', ['production', 'development', 'test'] as const, 'production'),
    appRole: oneOf('APP_ROLE', ['api', 'worker'] as const, 'api'),
    appDomain,
    publicUrl: process.env.PUBLIC_URL?.trim() || `https://${appDomain}`,
    httpPort: integer('HTTP_PORT', 3000),
    databaseUrl: required('DATABASE_URL'),
    redisUrl: required('REDIS_URL'),
    jwtAccessSecret: secret('JWT_ACCESS_SECRET', 64),
    jwtRefreshSecret: secret('JWT_REFRESH_SECRET', 64),
    appEncryptionKey,
    adminEmail: required('ADMIN_EMAIL'),
    adminInitialPassword: secret('ADMIN_INITIAL_PASSWORD', 12),
    signerUrl: required('SIGNER_URL'),
    signerHmacKey: secret('SIGNER_HMAC_KEY', 64),
    bitcoinRpcUrl: required('BITCOIN_RPC_URL'),
    bitcoinRpcUser: required('BITCOIN_RPC_USER'),
    bitcoinRpcPassword: secret('BITCOIN_RPC_PASSWORD'),
    litecoinRpcUrl: required('LITECOIN_RPC_URL'),
    litecoinRpcUser: required('LITECOIN_RPC_USER'),
    litecoinRpcPassword: secret('LITECOIN_RPC_PASSWORD'),
    bitcoinEsploraUrl: process.env.BITCOIN_ESPLORA_URL?.trim() || null,
    litecoinEsploraUrl: process.env.LITECOIN_ESPLORA_URL?.trim() || null,
    ethRpcUrl: required('ETH_RPC_URL'),
    xrplWsUrl: required('XRPL_WS_URL'),
    tronHttpUrl: required('TRON_HTTP_URL'),
    tronApiKey: process.env.TRON_API_KEY?.trim() || null,
    panelSsoSecret: process.env.PANEL_SSO_SECRET?.trim() || null,
    rateLimitTtl: integer('RATE_LIMIT_TTL', 60),
    rateLimitMax: integer('RATE_LIMIT_MAX', 120),
  };
  return cached;
}

export const APP_CONFIG = 'APP_CONFIG' as const;
