import {
  createCipheriv,
  createDecipheriv,
  randomBytes,
  randomUUID,
  scryptSync,
} from 'crypto';
import { existsSync, mkdirSync, readFileSync, renameSync, writeFileSync } from 'fs';
import { join } from 'path';

export type Chain = 'bitcoin' | 'litecoin' | 'ethereum' | 'xrp' | 'tron';

interface StoredWallet {
  chain: Chain;
  purpose: 'deposit' | 'treasury';
  salt: string; // per-wallet scrypt salt (hex)
  mnemonicEncrypted: string; // base64(iv || tag || ciphertext), AES-256-GCM
  createdAt: string;
}

interface KeystoreFile {
  version: 1;
  wallets: Record<string, StoredWallet>;
}

interface TrustedFile {
  version: 1;
  destinations: Partial<Record<Chain, string[]>>;
}

/**
 * Encrypted-at-rest wallet seed storage.
 * Every mnemonic is encrypted with AES-256-GCM under a key derived from
 * SIGNER_KEYSTORE_PASSPHRASE via scrypt with a per-wallet salt. Plaintext
 * mnemonics exist only transiently in memory during signing.
 */
export class Keystore {
  private readonly walletsPath: string;
  private readonly trustedPath: string;

  constructor(
    keystoreDir: string,
    private readonly passphrase: string,
  ) {
    mkdirSync(keystoreDir, { recursive: true, mode: 0o700 });
    this.walletsPath = join(keystoreDir, 'wallets.json');
    this.trustedPath = join(keystoreDir, 'trusted.json');
  }

  private readWallets(): KeystoreFile {
    if (!existsSync(this.walletsPath)) return { version: 1, wallets: {} };
    return JSON.parse(readFileSync(this.walletsPath, 'utf8')) as KeystoreFile;
  }

  private writeAtomic(path: string, data: unknown): void {
    const tmp = `${path}.tmp`;
    writeFileSync(tmp, JSON.stringify(data, null, 2), { mode: 0o600 });
    renameSync(tmp, path);
  }

  private deriveKey(saltHex: string): Buffer {
    return scryptSync(this.passphrase, Buffer.from(saltHex, 'hex'), 32, {
      N: 32768,
      r: 8,
      p: 1,
      maxmem: 64 * 1024 * 1024,
    });
  }

  storeWallet(chain: Chain, purpose: 'deposit' | 'treasury', mnemonic: string): string {
    const file = this.readWallets();
    const walletRef = randomUUID();
    const salt = randomBytes(16).toString('hex');
    const key = this.deriveKey(salt);
    const iv = randomBytes(12);
    const cipher = createCipheriv('aes-256-gcm', key, iv);
    const ciphertext = Buffer.concat([cipher.update(mnemonic, 'utf8'), cipher.final()]);
    file.wallets[walletRef] = {
      chain,
      purpose,
      salt,
      mnemonicEncrypted: Buffer.concat([iv, cipher.getAuthTag(), ciphertext]).toString('base64'),
      createdAt: new Date().toISOString(),
    };
    this.writeAtomic(this.walletsPath, file);
    return walletRef;
  }

  loadMnemonic(walletRef: string): { chain: Chain; mnemonic: string } {
    const stored = this.readWallets().wallets[walletRef];
    if (!stored) throw new Error(`unknown walletRef`);
    const key = this.deriveKey(stored.salt);
    const blob = Buffer.from(stored.mnemonicEncrypted, 'base64');
    const decipher = createDecipheriv('aes-256-gcm', key, blob.subarray(0, 12));
    decipher.setAuthTag(blob.subarray(12, 28));
    const mnemonic = Buffer.concat([
      decipher.update(blob.subarray(28)),
      decipher.final(),
    ]).toString('utf8');
    return { chain: stored.chain, mnemonic };
  }

  trustDestination(chain: Chain, address: string): void {
    const file: TrustedFile = existsSync(this.trustedPath)
      ? (JSON.parse(readFileSync(this.trustedPath, 'utf8')) as TrustedFile)
      : { version: 1, destinations: {} };
    const list = file.destinations[chain] ?? [];
    if (!list.includes(address)) {
      list.push(address);
      file.destinations[chain] = list;
      this.writeAtomic(this.trustedPath, file);
    }
  }

  isTrusted(chain: Chain, address: string): boolean {
    if (!existsSync(this.trustedPath)) return false;
    const file = JSON.parse(readFileSync(this.trustedPath, 'utf8')) as TrustedFile;
    return (file.destinations[chain] ?? []).includes(address);
  }
}
