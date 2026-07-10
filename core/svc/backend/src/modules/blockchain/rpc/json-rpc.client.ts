/** Minimal JSON-RPC 1.0/2.0 client over HTTP (Bitcoin Core, Litecoin Core, Geth). */
export class JsonRpcError extends Error {
  constructor(
    readonly code: number,
    message: string,
  ) {
    super(message);
  }
}

export class JsonRpcClient {
  private readonly authHeader: string | null;

  constructor(
    private readonly url: string,
    username?: string,
    password?: string,
    private readonly timeoutMs = 30_000,
  ) {
    this.authHeader =
      username !== undefined
        ? `Basic ${Buffer.from(`${username}:${password ?? ''}`).toString('base64')}`
        : null;
  }

  async call<T>(method: string, params: unknown[] = [], walletPath = ''): Promise<T> {
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), this.timeoutMs);
    try {
      const response = await fetch(this.url + walletPath, {
        method: 'POST',
        headers: {
          'content-type': 'application/json',
          ...(this.authHeader ? { authorization: this.authHeader } : {}),
        },
        body: JSON.stringify({ jsonrpc: '2.0', id: Date.now(), method, params }),
        signal: controller.signal,
      });
      const text = await response.text();
      let body: { result?: T; error?: { code: number; message: string } };
      try {
        body = JSON.parse(text) as typeof body;
      } catch {
        throw new Error(`non-JSON RPC response (${response.status}): ${text.slice(0, 200)}`);
      }
      if (body.error) throw new JsonRpcError(body.error.code, body.error.message);
      if (!response.ok) throw new Error(`RPC HTTP ${response.status}`);
      return body.result as T;
    } finally {
      clearTimeout(timer);
    }
  }
}
