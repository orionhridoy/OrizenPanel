import {
  createCipheriv,
  createDecipheriv,
  createHash,
  createHmac,
  randomBytes,
  scrypt as scryptCb,
  ScryptOptions,
  timingSafeEqual,
} from 'crypto';

// Promisify manually so the options overload is preserved (util.promisify
// collapses scrypt to its 3-arg signature and rejects the options argument).
function scrypt(
  password: string | Buffer,
  salt: string | Buffer,
  keylen: number,
  options: ScryptOptions,
): Promise<Buffer> {
  return new Promise((resolve, reject) => {
    scryptCb(password, salt, keylen, options, (err, derivedKey) =>
      err ? reject(err) : resolve(derivedKey),
    );
  });
}

// scrypt parameters per OWASP recommendation (N=2^15, r=8, p=1)
const SCRYPT_N = 32768;
const SCRYPT_R = 8;
const SCRYPT_P = 1;
const SCRYPT_KEYLEN = 64;

export function sha256Hex(input: string | Buffer): string {
  return createHash('sha256').update(input).digest('hex');
}

export function hmacSha256Hex(key: string | Buffer, data: string | Buffer): string {
  return createHmac('sha256', key).update(data).digest('hex');
}

export function randomHex(bytes: number): string {
  return randomBytes(bytes).toString('hex');
}

export function timingSafeEqualStr(a: string, b: string): boolean {
  const ab = Buffer.from(a);
  const bb = Buffer.from(b);
  if (ab.length !== bb.length) {
    // still burn constant time on a same-length dummy comparison
    timingSafeEqual(ab, ab);
    return false;
  }
  return timingSafeEqual(ab, bb);
}

export async function hashPassword(password: string): Promise<string> {
  const salt = randomBytes(16);
  const derived = await scrypt(password, salt, SCRYPT_KEYLEN, {
    N: SCRYPT_N,
    r: SCRYPT_R,
    p: SCRYPT_P,
    maxmem: 128 * SCRYPT_N * SCRYPT_R * 2,
  });
  return `scrypt$${SCRYPT_N}$${SCRYPT_R}$${SCRYPT_P}$${salt.toString('base64')}$${derived.toString('base64')}`;
}

export async function verifyPassword(password: string, stored: string): Promise<boolean> {
  const parts = stored.split('$');
  if (parts.length !== 6 || parts[0] !== 'scrypt') return false;
  const [, nStr, rStr, pStr, saltB64, hashB64] = parts;
  const N = Number.parseInt(nStr, 10);
  const r = Number.parseInt(rStr, 10);
  const p = Number.parseInt(pStr, 10);
  const salt = Buffer.from(saltB64, 'base64');
  const expected = Buffer.from(hashB64, 'base64');
  const derived = await scrypt(password, salt, expected.length, {
    N,
    r,
    p,
    maxmem: 128 * N * r * 2,
  });
  return derived.length === expected.length && timingSafeEqual(derived, expected);
}

/**
 * AES-256-GCM under APP_ENCRYPTION_KEY (hex). Output: base64(iv || tag || ciphertext).
 * Used for API secrets, webhook secrets and TOTP seeds at rest.
 */
export function encryptSecret(plaintext: string, keyHex: string): string {
  const key = Buffer.from(keyHex, 'hex');
  const iv = randomBytes(12);
  const cipher = createCipheriv('aes-256-gcm', key, iv);
  const ciphertext = Buffer.concat([cipher.update(plaintext, 'utf8'), cipher.final()]);
  return Buffer.concat([iv, cipher.getAuthTag(), ciphertext]).toString('base64');
}

export function decryptSecret(blobB64: string, keyHex: string): string {
  const key = Buffer.from(keyHex, 'hex');
  const blob = Buffer.from(blobB64, 'base64');
  if (blob.length < 12 + 16 + 1) throw new Error('encrypted blob too short');
  const iv = blob.subarray(0, 12);
  const tag = blob.subarray(12, 28);
  const ciphertext = blob.subarray(28);
  const decipher = createDecipheriv('aes-256-gcm', key, iv);
  decipher.setAuthTag(tag);
  return Buffer.concat([decipher.update(ciphertext), decipher.final()]).toString('utf8');
}
