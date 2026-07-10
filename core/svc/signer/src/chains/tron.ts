import { SigningKey } from 'ethers';
import { createHash } from 'crypto';
import { privateKeyAt } from './ethereum';

interface TronTx {
  txID: string;
  raw_data_hex: string;
  signature?: string[];
  [key: string]: unknown;
}

/**
 * Tron signing: signature = secp256k1(r||s||recovery) over sha256(raw_data).
 * The tx digest is recomputed locally and MUST match txID - we never sign a
 * digest we didn't derive ourselves.
 */
export function signTronTx(mnemonic: string, path: string, tx: TronTx): string {
  const digest = createHash('sha256')
    .update(Buffer.from(tx.raw_data_hex, 'hex'))
    .digest('hex');
  if (digest !== tx.txID.toLowerCase()) {
    throw new Error('tron txID does not match sha256(raw_data_hex) - refusing to sign');
  }
  const key = new SigningKey(privateKeyAt(mnemonic, path));
  const signature = key.sign(`0x${digest}`);
  const recovery = signature.v - 27;
  const sigHex =
    signature.r.slice(2) + signature.s.slice(2) + (recovery === 0 ? '00' : '01');
  const signed: TronTx = { ...tx, signature: [sigHex] };
  return JSON.stringify(signed);
}
