const API_BASE = (import.meta.env.VITE_API_BASE as string | undefined) ?? '/api/v1';

interface Session {
  accessToken: string;
  refreshToken: string;
  merchant: { id: string; email: string; name?: string; role: string; forcePasswordChange: boolean };
}

const STORAGE_KEY = 'orizen.session';

// localStorage (not sessionStorage) so one sign-in is shared across every tab
// and window; the 'storage' event keeps tabs in sync on login and logout.
export function getSession(): Session | null {
  const raw = localStorage.getItem(STORAGE_KEY);
  if (!raw) return null;
  try {
    return JSON.parse(raw) as Session;
  } catch {
    return null;
  }
}

export function setSession(session: Session | null): void {
  if (session) {
    localStorage.setItem(STORAGE_KEY, JSON.stringify(session));
  } else {
    localStorage.removeItem(STORAGE_KEY);
  }
  window.dispatchEvent(new Event('orizen:session'));
}

export class ApiError extends Error {
  constructor(
    readonly status: number,
    message: string,
  ) {
    super(message);
  }
}

let refreshing: Promise<boolean> | null = null;

async function tryRefresh(): Promise<boolean> {
  if (!refreshing) {
    refreshing = (async () => {
      const session = getSession();
      if (!session) return false;
      const response = await fetch(`${API_BASE}/auth/refresh`, {
        method: 'POST',
        headers: { 'content-type': 'application/json' },
        body: JSON.stringify({ refreshToken: session.refreshToken }),
      });
      if (!response.ok) {
        setSession(null);
        return false;
      }
      const pair = (await response.json()) as Session;
      setSession(pair);
      return true;
    })().finally(() => {
      refreshing = null;
    });
  }
  return refreshing;
}

// Global "Working..." indicator so every action gives instant feedback (mutating requests can
// take many seconds). Counts in-flight requests; shows a floating pill while any are running.
let __busyCount = 0;
function setBusy(delta: number): void {
  if (typeof document === 'undefined') return;
  __busyCount = Math.max(0, __busyCount + delta);
  let el = document.getElementById('__gwbusy');
  if (__busyCount > 0 && !el) {
    if (!document.getElementById('__gwbusycss')) {
      const s = document.createElement('style');
      s.id = '__gwbusycss';
      s.textContent = '@keyframes gwspin{to{transform:rotate(360deg)}}#__gwbusy{position:fixed;top:14px;right:14px;z-index:99999;display:flex;align-items:center;gap:9px;padding:9px 15px;border-radius:999px;font:600 13px system-ui,sans-serif;background:var(--panel,#1e293b);color:var(--ink,#e6edf3);border:1px solid var(--border,rgba(140,140,170,.3));box-shadow:0 10px 30px -10px rgba(0,0,0,.5)}#__gwbusy i{width:13px;height:13px;border:2px solid rgba(150,150,170,.35);border-top-color:currentColor;border-radius:50%;display:inline-block;animation:gwspin .7s linear infinite}';
      document.head.appendChild(s);
    }
    el = document.createElement('div');
    el.id = '__gwbusy';
    el.innerHTML = '<i></i>Working...';
    document.body.appendChild(el);
  } else if (__busyCount === 0 && el) {
    el.remove();
  }
}

export async function api<T>(
  path: string,
  options: { method?: string; body?: unknown; auth?: boolean } = {},
): Promise<T> {
  const { method = 'GET', body, auth = true } = options;
  const mutating = method !== 'GET' && method !== 'HEAD';
  if (mutating) setBusy(1);
  const doFetch = async (): Promise<Response> => {
    const session = getSession();
    return fetch(`${API_BASE}${path}`, {
      method,
      headers: {
        ...(body !== undefined ? { 'content-type': 'application/json' } : {}),
        ...(auth && session ? { authorization: `Bearer ${session.accessToken}` } : {}),
      },
      body: body !== undefined ? JSON.stringify(body) : undefined,
    });
  };

  try {
    let response = await doFetch();
    if (response.status === 401 && auth && (await tryRefresh())) {
      response = await doFetch();
    }
    if (response.status === 401 && auth) {
      setSession(null);
      throw new ApiError(401, 'session expired');
    }
    if (response.status === 204) return undefined as T;
    const text = await response.text();
    const data = text === '' ? undefined : (JSON.parse(text) as T & { error?: { message?: string } });
    if (!response.ok) {
      const message =
        (data as { error?: { message?: string } } | undefined)?.error?.message ??
        `request failed (${response.status})`;
      throw new ApiError(response.status, Array.isArray(message) ? message.join(', ') : String(message));
    }
    return data as T;
  } finally {
    if (mutating) setBusy(-1);
  }
}

/** Fetch authenticated by a store-session token (hosted customer page). */
export async function apiToken<T>(
  path: string,
  token: string,
  options: { method?: string; body?: unknown } = {},
): Promise<T> {
  const { method = 'GET', body } = options;
  const response = await fetch(`${API_BASE}${path}`, {
    method,
    headers: {
      authorization: `Bearer ${token}`,
      ...(body !== undefined ? { 'content-type': 'application/json' } : {}),
    },
    body: body !== undefined ? JSON.stringify(body) : undefined,
  });
  if (response.status === 204) return undefined as T;
  const text = await response.text();
  const data = text === '' ? undefined : (JSON.parse(text) as T & { error?: { message?: string } });
  if (!response.ok) {
    const message = (data as { error?: { message?: string } } | undefined)?.error?.message ?? `request failed (${response.status})`;
    throw new ApiError(response.status, Array.isArray(message) ? message.join(', ') : String(message));
  }
  return data as T;
}

export { API_BASE };
