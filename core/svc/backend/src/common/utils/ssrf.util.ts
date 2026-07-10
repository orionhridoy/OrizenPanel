import { lookup } from 'dns/promises';
import { isIP } from 'net';

/**
 * Webhook SSRF guard: merchant-supplied URLs must never reach internal
 * services. Rejects non-HTTP(S) schemes and any hostname resolving to
 * loopback, private, link-local, or otherwise reserved address space.
 */
function isPrivateV4(address: string): boolean {
  const octets = address.split('.').map((n) => Number.parseInt(n, 10));
  if (octets.length !== 4 || octets.some((n) => Number.isNaN(n))) return true;
  const [a, b] = octets;
  return (
    a === 0 ||
    a === 10 ||
    a === 127 ||
    (a === 100 && b >= 64 && b <= 127) || // CGNAT
    (a === 169 && b === 254) ||
    (a === 172 && b >= 16 && b <= 31) ||
    (a === 192 && b === 168) ||
    (a === 192 && b === 0) ||
    (a === 198 && (b === 18 || b === 19)) ||
    a >= 224 // multicast + reserved
  );
}

function isPrivateV6(address: string): boolean {
  const lower = address.toLowerCase();
  return (
    lower === '::' ||
    lower === '::1' ||
    lower.startsWith('fc') ||
    lower.startsWith('fd') ||
    lower.startsWith('fe80') ||
    lower.startsWith('::ffff:') // v4-mapped - treat as suspicious
  );
}

function isPrivateAddress(address: string): boolean {
  const family = isIP(address);
  if (family === 4) return isPrivateV4(address);
  if (family === 6) return isPrivateV6(address);
  return true;
}

export async function assertSafeWebhookUrl(rawUrl: string): Promise<URL> {
  let url: URL;
  try {
    url = new URL(rawUrl);
  } catch {
    throw new Error('invalid webhook URL');
  }
  if (url.protocol !== 'https:' && url.protocol !== 'http:') {
    throw new Error('webhook URL must be http(s)');
  }
  if (url.username !== '' || url.password !== '') {
    throw new Error('webhook URL must not contain credentials');
  }
  const hostname = url.hostname.replace(/^\[|\]$/g, '');
  if (isIP(hostname)) {
    if (isPrivateAddress(hostname)) throw new Error('webhook URL resolves to a private address');
    return url;
  }
  let records: Array<{ address: string }>;
  try {
    records = await lookup(hostname, { all: true });
  } catch {
    throw new Error('webhook hostname does not resolve');
  }
  if (records.length === 0 || records.some((r) => isPrivateAddress(r.address))) {
    throw new Error('webhook URL resolves to a private address');
  }
  return url;
}
