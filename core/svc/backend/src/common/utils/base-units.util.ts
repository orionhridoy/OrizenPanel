/**
 * All persisted amounts are integer strings in the asset's base unit
 * (NUMERIC(38,0) in PostgreSQL). BigInt everywhere - floating point never
 * touches money.
 */

const DECIMAL_RE = /^\d+(\.\d+)?$/;
const BASE_UNIT_RE = /^\d+$/;

export function isBaseUnitString(value: string): boolean {
  return BASE_UNIT_RE.test(value);
}

/** "1.23456789" + 8 decimals -> "123456789" */
export function decimalToBaseUnits(value: string, decimals: number): string {
  const trimmed = value.trim();
  if (!DECIMAL_RE.test(trimmed)) {
    throw new Error(`invalid decimal amount: "${value}"`);
  }
  const [whole, fraction = ''] = trimmed.split('.');
  if (fraction.length > decimals) {
    throw new Error(`amount "${value}" exceeds ${decimals} decimal places`);
  }
  const padded = fraction.padEnd(decimals, '0');
  const combined = `${whole}${padded}`.replace(/^0+(?=\d)/, '');
  return combined === '' ? '0' : combined;
}

/** "123456789" + 8 decimals -> "1.23456789" (trailing zeros trimmed) */
export function baseUnitsToDecimal(value: string, decimals: number): string {
  if (!isBaseUnitString(value)) {
    throw new Error(`invalid base-unit amount: "${value}"`);
  }
  if (decimals === 0) return BigInt(value).toString();
  const padded = value.padStart(decimals + 1, '0');
  const whole = padded.slice(0, -decimals).replace(/^0+(?=\d)/, '');
  const fraction = padded.slice(-decimals).replace(/0+$/, '');
  return fraction === '' ? whole : `${whole}.${fraction}`;
}

export function addBase(a: string, b: string): string {
  return (BigInt(a) + BigInt(b)).toString();
}

export function subBase(a: string, b: string): string {
  const result = BigInt(a) - BigInt(b);
  if (result < 0n) throw new Error(`negative amount: ${a} - ${b}`);
  return result.toString();
}

export function cmpBase(a: string, b: string): -1 | 0 | 1 {
  const x = BigInt(a);
  const y = BigInt(b);
  return x < y ? -1 : x > y ? 1 : 0;
}

/** amount >= due minus tolerance (basis points) */
export function meetsWithTolerance(paid: string, due: string, toleranceBps: number): boolean {
  const minimum = (BigInt(due) * BigInt(10_000 - toleranceBps)) / 10_000n;
  return BigInt(paid) >= minimum;
}
