import { Wallet } from 'xrpl';

/** XRPL account derived from the mnemonic (m/44'/144'/0'/0/0). */
export function xrpAddress(mnemonic: string): string {
  return Wallet.fromMnemonic(mnemonic).classicAddress;
}

/** Offline-signs a fully prepared tx_json. Returns the signed tx_blob. */
export function signXrpTx(mnemonic: string, txJson: Record<string, unknown>): string {
  const wallet = Wallet.fromMnemonic(mnemonic);
  const signed = wallet.sign(txJson as never);
  return signed.tx_blob;
}
