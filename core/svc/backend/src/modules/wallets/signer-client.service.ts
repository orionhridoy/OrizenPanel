import { Inject, Injectable, Logger } from '@nestjs/common';
import { APP_CONFIG, AppConfig } from '../../config/config';
import { hmacSha256Hex } from '../../common/utils/crypto.util';
import { Chain } from '../../common/types';

export interface CreatedWallet {
  walletRef: string;
  chain: Chain;
  xpub: string | null;
  address: string | null;
}

export type SignRequest =
  | {
      kind: 'psbt';
      chain: 'bitcoin' | 'litecoin';
      walletRef: string;
      psbtBase64: string;
      inputPaths: string[];
      purpose: 'sweep' | 'withdrawal';
      destination: string;
      amountBaseUnits: string;
      assetCode: string;
    }
  | {
      kind: 'eth-tx';
      chain: 'ethereum';
      walletRef: string;
      path: string;
      tx: {
        to: string;
        value: string;
        data: string;
        nonce: number;
        gasLimit: string;
        maxFeePerGas: string;
        maxPriorityFeePerGas: string;
        chainId: number;
      };
      purpose: 'sweep' | 'withdrawal' | 'gas-topup';
      destination: string;
      amountBaseUnits: string;
      assetCode: string;
    }
  | {
      kind: 'xrp-tx';
      chain: 'xrp';
      walletRef: string;
      txJson: Record<string, unknown>;
      purpose: 'sweep' | 'withdrawal';
      destination: string;
      amountBaseUnits: string;
      assetCode: string;
    }
  | {
      kind: 'tron-tx';
      chain: 'tron';
      walletRef: string;
      path: string;
      tx: Record<string, unknown>;
      purpose: 'sweep' | 'withdrawal' | 'gas-topup';
      destination: string;
      amountBaseUnits: string;
      assetCode: string;
    };

/**
 * The ONLY component that talks to the signer, over the isolated `signing`
 * network, with per-request HMAC authentication. Only the worker role is
 * attached to that network - the API container cannot reach the signer at all.
 */
@Injectable()
export class SignerClientService {
  private readonly logger = new Logger(SignerClientService.name);

  constructor(@Inject(APP_CONFIG) private readonly config: AppConfig) {}

  private async post<T>(path: string, body: Record<string, unknown>): Promise<T> {
    const payload = JSON.stringify(body);
    const timestamp = Date.now().toString();
    const signature = hmacSha256Hex(this.config.signerHmacKey, `${timestamp}.${path}.${payload}`);
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), 30_000);
    try {
      const response = await fetch(`${this.config.signerUrl}${path}`, {
        method: 'POST',
        headers: {
          'content-type': 'application/json',
          'x-signer-timestamp': timestamp,
          'x-signer-signature': signature,
        },
        body: payload,
        signal: controller.signal,
      });
      const text = await response.text();
      if (!response.ok) {
        throw new Error(`signer ${path} failed (${response.status}): ${text.slice(0, 300)}`);
      }
      return JSON.parse(text) as T;
    } finally {
      clearTimeout(timer);
    }
  }

  async createWallet(chain: Chain, purpose: 'deposit' | 'treasury'): Promise<CreatedWallet> {
    return this.post<CreatedWallet>('/v1/wallets', { chain, purpose });
  }

  /** Registers a trusted sweep destination (treasury address) with the signer. */
  async trustDestination(chain: Chain, address: string): Promise<void> {
    await this.post('/v1/trusted-destinations', { chain, address });
  }

  async sign(request: SignRequest): Promise<string> {
    const result = await this.post<{ signed: string }>('/v1/sign', request);
    this.logger.log(
      `signed ${request.kind} purpose=${request.purpose} asset=${request.assetCode} dest=${request.destination}`,
    );
    return result.signed;
  }
}
