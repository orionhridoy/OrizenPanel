import { BIP32Factory } from 'bip32';
import * as ecc from '@bitcoinerlab/secp256k1';
import * as bip39 from 'bip39';
import * as bitcoin from 'bitcoinjs-lib';
import { Chain } from '../keystore';

const bip32 = BIP32Factory(ecc);
bitcoin.initEccLib(ecc);

const ACCOUNT_PATH: Partial<Record<Chain, string>> = {
  bitcoin: "m/84'/0'/0'",
  litecoin: "m/84'/2'/0'",
  ethereum: "m/44'/60'/0'",
  tron: "m/44'/195'/0'",
};

/** New 24-word mnemonic. */
export function generateMnemonic(): string {
  return bip39.generateMnemonic(256);
}

/**
 * Account-level xpub with STANDARD version bytes for all chains - the backend
 * derives receive addresses from this and never sees private material.
 */
export function accountXpub(mnemonic: string, chain: Chain): string {
  const path = ACCOUNT_PATH[chain];
  if (!path) throw new Error(`no account path for ${chain}`);
  const root = bip32.fromSeed(bip39.mnemonicToSeedSync(mnemonic));
  return root.derivePath(path).neutered().toBase58();
}

/**
 * Signs a PSBT. inputPaths[i] is the full derivation path of input i's key.
 * Returns the fully signed, finalized raw transaction hex.
 */
export function signPsbt(mnemonic: string, psbtBase64: string, inputPaths: string[]): string {
  const root = bip32.fromSeed(bip39.mnemonicToSeedSync(mnemonic));
  const psbt = bitcoin.Psbt.fromBase64(psbtBase64);
  if (psbt.inputCount !== inputPaths.length) {
    throw new Error(`psbt has ${psbt.inputCount} inputs but ${inputPaths.length} paths supplied`);
  }
  for (let i = 0; i < psbt.inputCount; i++) {
    psbt.signInput(i, root.derivePath(inputPaths[i]));
  }
  if (!psbt.validateSignaturesOfAllInputs((pubkey, msghash, signature) =>
    ecc.verify(msghash, pubkey, signature),
  )) {
    throw new Error('psbt signature validation failed');
  }
  psbt.finalizeAllInputs();
  return psbt.extractTransaction().toHex();
}
