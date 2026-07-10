import { Chain } from './keystore';

export type Purpose = 'sweep' | 'withdrawal' | 'gas-topup';

/**
 * Signing policy enforced INSIDE the signer, independent of anything the
 * API or database claims. Defense in depth: even a fully compromised backend
 * cannot sign beyond these rules.
 */
const ASSET_DECIMALS: Record<string, number> = {
  BTC: 8,
  LTC: 8,
  ETH: 18,
  XRP: 6,
  USDT_TRC20: 6,
  USDC_ERC20: 6,
};

const CEILING_ENV: Record<string, string> = {
  BTC: 'SIGNER_MAX_WITHDRAWAL_BTC',
  LTC: 'SIGNER_MAX_WITHDRAWAL_LTC',
  ETH: 'SIGNER_MAX_WITHDRAWAL_ETH',
  XRP: 'SIGNER_MAX_WITHDRAWAL_XRP',
  USDT_TRC20: 'SIGNER_MAX_WITHDRAWAL_TRX_USDT',
  USDC_ERC20: 'SIGNER_MAX_WITHDRAWAL_ERC20_USDC',
};

/** gas top-ups move native coin to OUR OWN deposit addresses; keep them tiny */
const GAS_TOPUP_CEILING_BASE_UNITS: Partial<Record<Chain, bigint>> = {
  ethereum: 50_000_000_000_000_000n, // 0.05 ETH
  tron: 100_000_000n, // 100 TRX
};

const MAX_SIGNS_PER_HOUR = 120;

function wholeToBase(whole: string, decimals: number): bigint {
  const [intPart, fracPart = ''] = whole.split('.');
  const frac = fracPart.padEnd(decimals, '0').slice(0, decimals);
  return BigInt(intPart) * 10n ** BigInt(decimals) + BigInt(frac === '' ? 0 : frac);
}

export class Policy {
  private readonly ceilings = new Map<string, bigint>();
  private signTimestamps: number[] = [];

  constructor(env: NodeJS.ProcessEnv) {
    for (const [asset, envName] of Object.entries(CEILING_ENV)) {
      const raw = env[envName];
      if (raw === undefined || raw.trim() === '') {
        throw new Error(`missing signing ceiling env: ${envName}`);
      }
      this.ceilings.set(asset, wholeToBase(raw.trim(), ASSET_DECIMALS[asset]));
    }
  }

  /** throws with a reason when the request violates policy */
  check(input: {
    purpose: Purpose;
    chain: Chain;
    assetCode: string;
    amountBaseUnits: string;
    destination: string;
    isTrustedDestination: boolean;
  }): void {
    const now = Date.now();
    this.signTimestamps = this.signTimestamps.filter((t) => now - t < 3_600_000);
    if (this.signTimestamps.length >= MAX_SIGNS_PER_HOUR) {
      throw new Error(`policy: signing rate limit exceeded (${MAX_SIGNS_PER_HOUR}/h)`);
    }

    const amount = BigInt(input.amountBaseUnits);
    if (amount <= 0n) throw new Error('policy: amount must be positive');

    switch (input.purpose) {
      case 'sweep': {
        if (!input.isTrustedDestination) {
          throw new Error(
            `policy: sweep destination ${input.destination} is not a trusted treasury address`,
          );
        }
        break;
      }
      case 'withdrawal': {
        const ceiling = this.ceilings.get(input.assetCode);
        if (ceiling === undefined) {
          throw new Error(`policy: no ceiling configured for asset ${input.assetCode}`);
        }
        if (amount > ceiling) {
          throw new Error(
            `policy: withdrawal of ${amount} exceeds per-request ceiling ${ceiling} for ${input.assetCode}`,
          );
        }
        break;
      }
      case 'gas-topup': {
        const ceiling = GAS_TOPUP_CEILING_BASE_UNITS[input.chain];
        if (ceiling === undefined) {
          throw new Error(`policy: gas top-ups not allowed on ${input.chain}`);
        }
        if (amount > ceiling) {
          throw new Error(`policy: gas top-up ${amount} exceeds ceiling ${ceiling}`);
        }
        break;
      }
      default:
        throw new Error(`policy: unknown purpose`);
    }
    this.signTimestamps.push(now);
  }
}
