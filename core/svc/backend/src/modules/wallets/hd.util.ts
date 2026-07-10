import { BIP32Factory, BIP32Interface } from 'bip32';
import * as ecc from '@bitcoinerlab/secp256k1';
import * as bitcoin from 'bitcoinjs-lib';
import { SigningKey, keccak256 } from 'ethers';
import bs58check from 'bs58check';
import { LITECOIN_NETWORK } from '../blockchain/adapters/litecoin/litecoin.network';

const bip32 = BIP32Factory(ecc);
bitcoin.initEccLib(ecc);

/**
 * Watch-only HD derivation from ACCOUNT-LEVEL xpubs.
 * The signer exports all xpubs with standard version bytes (0x0488B21E)
 * regardless of chain - the chain is tracked in the wallets table, not in the
 * serialization format.
 *
 * Derivation standards (account xpub -> external chain 0 -> index):
 *   BTC  m/84'/0'/0'   -> P2WPKH (bc1...)
 *   LTC  m/84'/2'/0'   -> P2WPKH (ltc1...)
 *   ETH  m/44'/60'/0'  -> keccak(pubkey)[12:]
 *   TRON m/44'/195'/0' -> base58check(0x41 ‖ keccak(pubkey)[12:])
 */
function child(xpub: string, index: number): BIP32Interface {
  return bip32.fromBase58(xpub).derive(0).derive(index);
}

export function deriveBtcAddress(xpub: string, index: number): string {
  const { address } = bitcoin.payments.p2wpkh({
    pubkey: Buffer.from(child(xpub, index).publicKey),
    network: bitcoin.networks.bitcoin,
  });
  if (!address) throw new Error('BTC derivation failed');
  return address;
}

export function deriveLtcAddress(xpub: string, index: number): string {
  const { address } = bitcoin.payments.p2wpkh({
    pubkey: Buffer.from(child(xpub, index).publicKey),
    network: LITECOIN_NETWORK,
  });
  if (!address) throw new Error('LTC derivation failed');
  return address;
}

function evmAddressBytes(xpub: string, index: number): string {
  const compressed = Buffer.from(child(xpub, index).publicKey).toString('hex');
  const uncompressed = SigningKey.computePublicKey(`0x${compressed}`, false);
  // drop 0x04 prefix, keccak, take last 20 bytes
  return keccak256(`0x${uncompressed.slice(4)}`).slice(-40);
}

export function deriveEthAddress(xpub: string, index: number): string {
  return `0x${evmAddressBytes(xpub, index)}`.toLowerCase();
}

export function deriveTronAddress(xpub: string, index: number): string {
  return bs58check.encode(Buffer.from(`41${evmAddressBytes(xpub, index)}`, 'hex'));
}

/** BIP84/BIP44 external-chain derivation path for signer-side key lookup. */
export function derivationPathFor(chain: string, index: number): string {
  switch (chain) {
    case 'bitcoin':
      return `m/84'/0'/0'/0/${index}`;
    case 'litecoin':
      return `m/84'/2'/0'/0/${index}`;
    case 'ethereum':
      return `m/44'/60'/0'/0/${index}`;
    case 'tron':
      return `m/44'/195'/0'/0/${index}`;
    default:
      throw new Error(`no derivation path for chain ${chain}`);
  }
}
