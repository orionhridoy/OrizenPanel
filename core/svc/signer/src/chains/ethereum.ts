import { BIP32Factory } from 'bip32';
import * as ecc from '@bitcoinerlab/secp256k1';
import * as bip39 from 'bip39';
import { Wallet } from 'ethers';

const bip32 = BIP32Factory(ecc);

function privateKeyAt(mnemonic: string, path: string): string {
  const root = bip32.fromSeed(bip39.mnemonicToSeedSync(mnemonic));
  const key = root.derivePath(path).privateKey;
  if (!key) throw new Error('derivation produced no private key');
  return `0x${Buffer.from(key).toString('hex')}`;
}

export interface EthTxRequest {
  to: string;
  value: string;
  data: string;
  nonce: number;
  gasLimit: string;
  maxFeePerGas: string;
  maxPriorityFeePerGas: string;
  chainId: number;
}

/** EIP-1559 transaction, signed offline. Returns raw signed hex. */
export async function signEthTx(
  mnemonic: string,
  path: string,
  tx: EthTxRequest,
): Promise<string> {
  const wallet = new Wallet(privateKeyAt(mnemonic, path));
  return wallet.signTransaction({
    type: 2,
    to: tx.to,
    value: BigInt(tx.value),
    data: tx.data === '' ? '0x' : tx.data,
    nonce: tx.nonce,
    gasLimit: BigInt(tx.gasLimit),
    maxFeePerGas: BigInt(tx.maxFeePerGas),
    maxPriorityFeePerGas: BigInt(tx.maxPriorityFeePerGas),
    chainId: tx.chainId,
  });
}

export { privateKeyAt };
