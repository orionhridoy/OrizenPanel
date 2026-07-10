import { BIP32Factory } from 'bip32';
import * as ecc from '@bitcoinerlab/secp256k1';
import * as bitcoin from 'bitcoinjs-lib';
import { SigningKey, keccak256 } from 'ethers';
import bs58check from 'bs58check';
import { Chain } from './keystore';

const bip32 = BIP32Factory(ecc);
bitcoin.initEccLib(ecc);

const LITECOIN_NETWORK: bitcoin.networks.Network = {
  messagePrefix: '\x19Litecoin Signed Message:\n',
  bech32: 'ltc',
  bip32: { public: 0x019da462, private: 0x019d9cfe },
  pubKeyHash: 0x30,
  scriptHash: 0x32,
  wif: 0xb0,
};

/** Treasury wallets are single-address: external chain, index 0 of the account xpub. */
export function deriveTreasuryAddress(chain: Chain, xpub: string): string {
  const pubkey = Buffer.from(bip32.fromBase58(xpub).derive(0).derive(0).publicKey);
  switch (chain) {
    case 'bitcoin': {
      const { address } = bitcoin.payments.p2wpkh({
        pubkey,
        network: bitcoin.networks.bitcoin,
      });
      if (!address) throw new Error('btc treasury derivation failed');
      return address;
    }
    case 'litecoin': {
      const { address } = bitcoin.payments.p2wpkh({ pubkey, network: LITECOIN_NETWORK });
      if (!address) throw new Error('ltc treasury derivation failed');
      return address;
    }
    case 'ethereum': {
      const uncompressed = SigningKey.computePublicKey(`0x${pubkey.toString('hex')}`, false);
      return `0x${keccak256(`0x${uncompressed.slice(4)}`).slice(-40)}`.toLowerCase();
    }
    case 'tron': {
      const uncompressed = SigningKey.computePublicKey(`0x${pubkey.toString('hex')}`, false);
      const bytes = keccak256(`0x${uncompressed.slice(4)}`).slice(-40);
      return bs58check.encode(Buffer.from(`41${bytes}`, 'hex'));
    }
    default:
      throw new Error(`treasury derivation unsupported for ${chain}`);
  }
}
