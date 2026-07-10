import { createServer, IncomingMessage, ServerResponse } from 'http';
import { Keystore, Chain } from './keystore';
import { Policy, Purpose } from './policy';
import { verifyHmac } from './hmac';
import { accountXpub, generateMnemonic, signPsbt } from './chains/bitcoin';
import { signEthTx, EthTxRequest } from './chains/ethereum';
import { signXrpTx, xrpAddress } from './chains/xrp';
import { signTronTx } from './chains/tron';
import { deriveTreasuryAddress } from './treasury';

const CHAINS: readonly Chain[] = ['bitcoin', 'litecoin', 'ethereum', 'xrp', 'tron'];
const MAX_BODY_BYTES = 1_048_576;

function env(name: string): string {
  const value = process.env[name];
  if (value === undefined || value.trim() === '' || value.includes('CHANGE_ME')) {
    throw new Error(`missing/placeholder env: ${name}`);
  }
  return value.trim();
}

const hmacKey = env('SIGNER_HMAC_KEY');
const keystore = new Keystore(
  process.env.SIGNER_KEYSTORE_PATH ?? '/keystore',
  env('SIGNER_KEYSTORE_PASSPHRASE'),
);
const policy = new Policy(process.env);
const port = Number.parseInt(process.env.SIGNER_PORT ?? '4000', 10);

function audit(event: string, detail: Record<string, unknown>): void {
  process.stdout.write(
    `${JSON.stringify({ time: new Date().toISOString(), service: 'signer', event, ...detail })}\n`,
  );
}

function readBody(request: IncomingMessage): Promise<string> {
  return new Promise((resolve, reject) => {
    const chunks: Buffer[] = [];
    let size = 0;
    request.on('data', (chunk: Buffer) => {
      size += chunk.length;
      if (size > MAX_BODY_BYTES) {
        reject(new Error('body too large'));
        request.destroy();
        return;
      }
      chunks.push(chunk);
    });
    request.on('end', () => resolve(Buffer.concat(chunks).toString('utf8')));
    request.on('error', reject);
  });
}

function respond(response: ServerResponse, status: number, body: Record<string, unknown>): void {
  const payload = JSON.stringify(body);
  response.writeHead(status, { 'content-type': 'application/json' });
  response.end(payload);
}

interface WalletCreateBody {
  chain: Chain;
  purpose: 'deposit' | 'treasury';
}

interface TrustBody {
  chain: Chain;
  address: string;
}

interface SignBody {
  kind: 'psbt' | 'eth-tx' | 'xrp-tx' | 'tron-tx';
  chain: Chain;
  walletRef: string;
  purpose: Purpose;
  destination: string;
  amountBaseUnits: string;
  assetCode: string;
  psbtBase64?: string;
  inputPaths?: string[];
  path?: string;
  tx?: unknown;
  txJson?: Record<string, unknown>;
}

async function handleWalletCreate(body: WalletCreateBody): Promise<Record<string, unknown>> {
  if (!CHAINS.includes(body.chain)) throw new Error('invalid chain');
  if (body.purpose !== 'deposit' && body.purpose !== 'treasury') throw new Error('invalid purpose');

  const mnemonic = generateMnemonic();
  const walletRef = keystore.storeWallet(body.chain, body.purpose, mnemonic);

  let xpub: string | null = null;
  let address: string | null = null;
  if (body.chain === 'xrp') {
    address = xrpAddress(mnemonic);
  } else {
    xpub = accountXpub(mnemonic, body.chain);
    if (body.purpose === 'treasury') {
      // treasuries are single-address: external chain index 0 of the account xpub
      address = deriveTreasuryAddress(body.chain, xpub);
    }
  }
  if (body.purpose === 'treasury' && address) {
    keystore.trustDestination(body.chain, address);
  }
  audit('wallet.created', { chain: body.chain, purpose: body.purpose, walletRef, address });
  return { walletRef, chain: body.chain, xpub, address };
}

async function handleSign(body: SignBody): Promise<Record<string, unknown>> {
  if (!CHAINS.includes(body.chain)) throw new Error('invalid chain');
  const { chain, mnemonic } = keystore.loadMnemonic(body.walletRef);
  if (chain !== body.chain) throw new Error('walletRef chain mismatch');

  policy.check({
    purpose: body.purpose,
    chain: body.chain,
    assetCode: body.assetCode,
    amountBaseUnits: body.amountBaseUnits,
    destination: body.destination,
    isTrustedDestination: keystore.isTrusted(body.chain, body.destination),
  });

  let signed: string;
  switch (body.kind) {
    case 'psbt': {
      if (!body.psbtBase64 || !Array.isArray(body.inputPaths)) {
        throw new Error('psbt signing requires psbtBase64 and inputPaths');
      }
      signed = signPsbt(mnemonic, body.psbtBase64, body.inputPaths);
      break;
    }
    case 'eth-tx': {
      if (!body.path || typeof body.tx !== 'object' || body.tx === null) {
        throw new Error('eth signing requires path and tx');
      }
      signed = await signEthTx(mnemonic, body.path, body.tx as EthTxRequest);
      break;
    }
    case 'xrp-tx': {
      if (!body.txJson) throw new Error('xrp signing requires txJson');
      signed = signXrpTx(mnemonic, body.txJson);
      break;
    }
    case 'tron-tx': {
      if (!body.path || typeof body.tx !== 'object' || body.tx === null) {
        throw new Error('tron signing requires path and tx');
      }
      signed = signTronTx(
        mnemonic,
        body.path,
        body.tx as { txID: string; raw_data_hex: string },
      );
      break;
    }
    default:
      throw new Error('unknown signing kind');
  }
  audit('sign.ok', {
    kind: body.kind,
    chain: body.chain,
    purpose: body.purpose,
    assetCode: body.assetCode,
    amountBaseUnits: body.amountBaseUnits,
    destination: body.destination,
  });
  return { signed };
}

const server = createServer((request, response) => {
  void (async () => {
    const path = (request.url ?? '/').split('?')[0];

    if (request.method === 'GET' && path === '/health') {
      respond(response, 200, { status: 'ok' });
      return;
    }
    if (request.method !== 'POST') {
      respond(response, 405, { error: 'method not allowed' });
      return;
    }

    let rawBody: string;
    try {
      rawBody = await readBody(request);
    } catch (err) {
      respond(response, 413, { error: (err as Error).message });
      return;
    }

    const auth = verifyHmac(
      hmacKey,
      path,
      rawBody,
      request.headers['x-signer-timestamp'] as string | undefined,
      request.headers['x-signer-signature'] as string | undefined,
    );
    if (!auth.ok) {
      audit('auth.rejected', { path, reason: auth.reason });
      respond(response, 401, { error: `unauthorized: ${auth.reason}` });
      return;
    }

    try {
      const body = JSON.parse(rawBody === '' ? '{}' : rawBody) as Record<string, unknown>;
      if (path === '/v1/wallets') {
        respond(response, 200, await handleWalletCreate(body as unknown as WalletCreateBody));
      } else if (path === '/v1/trusted-destinations') {
        const trust = body as unknown as TrustBody;
        if (!CHAINS.includes(trust.chain) || typeof trust.address !== 'string') {
          throw new Error('invalid trust request');
        }
        keystore.trustDestination(trust.chain, trust.address);
        audit('destination.trusted', { chain: trust.chain, address: trust.address });
        respond(response, 200, { ok: true });
      } else if (path === '/v1/sign') {
        respond(response, 200, await handleSign(body as unknown as SignBody));
      } else {
        respond(response, 404, { error: 'not found' });
      }
    } catch (err) {
      const message = (err as Error).message;
      audit('request.failed', { path, error: message });
      respond(response, message.startsWith('policy:') ? 403 : 400, { error: message });
    }
  })();
});

server.listen(port, '0.0.0.0', () => {
  audit('signer.started', { port });
});

process.on('SIGTERM', () => server.close(() => process.exit(0)));
process.on('SIGINT', () => server.close(() => process.exit(0)));
