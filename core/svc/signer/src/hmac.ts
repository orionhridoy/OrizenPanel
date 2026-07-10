import { createHmac, timingSafeEqual } from 'crypto';

const MAX_SKEW_MS = 60_000;
const seen = new Map<string, number>(); // signature -> expiry (replay guard)

function sweep(now: number): void {
  for (const [sig, expiry] of seen) {
    if (expiry < now) seen.delete(sig);
  }
}

/**
 * Verifies X-Signer-Timestamp / X-Signer-Signature:
 *   signature = HMAC-SHA256(key, `${timestamp}.${path}.${rawBody}`)
 * ±60 s skew; each signature is single-use within the window (replay guard).
 */
export function verifyHmac(
  key: string,
  path: string,
  rawBody: string,
  timestampHeader: string | undefined,
  signatureHeader: string | undefined,
): { ok: true } | { ok: false; reason: string } {
  if (!timestampHeader || !signatureHeader) {
    return { ok: false, reason: 'missing auth headers' };
  }
  const timestamp = Number.parseInt(timestampHeader, 10);
  const now = Date.now();
  if (!Number.isFinite(timestamp) || Math.abs(now - timestamp) > MAX_SKEW_MS) {
    return { ok: false, reason: 'timestamp outside window' };
  }
  const expected = createHmac('sha256', key)
    .update(`${timestampHeader}.${path}.${rawBody}`)
    .digest('hex');
  const a = Buffer.from(expected);
  const b = Buffer.from(signatureHeader.toLowerCase());
  if (a.length !== b.length || !timingSafeEqual(a, b)) {
    return { ok: false, reason: 'bad signature' };
  }
  sweep(now);
  if (seen.has(signatureHeader)) {
    return { ok: false, reason: 'replayed signature' };
  }
  seen.set(signatureHeader, now + MAX_SKEW_MS * 2);
  return { ok: true };
}
