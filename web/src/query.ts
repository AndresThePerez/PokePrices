// The PokéPrices query client (D9): TanStack Query polling every five minutes while the tab is
// visible. No push, no websockets; the browser's HTTP cache revalidates with the API's ETags.
import { QueryCache, QueryClient, type QueryClientConfig } from '@tanstack/react-query';
import {
  ACCOUNT_KEY,
  ME_KEY,
  SESSION_SIGNAL_KEY,
  forgetAccount,
  identityOf,
  isOneTime,
} from './account/session';
import type { ApiResult } from './api/client';

export const QUERY_DEFAULTS = {
  refetchInterval: 300_000,
  refetchIntervalInBackground: false,
  staleTime: 300_000,
  refetchOnWindowFocus: true,
  retry: false,
} as const;

function isMe(key: unknown): boolean {
  return Array.isArray(key) && key.length === ME_KEY.length && key[0] === ME_KEY[0];
}

function isAccount(key: unknown): boolean {
  return Array.isArray(key) && key[0] === ACCOUNT_KEY[0];
}

function isUnauthenticated(error: unknown): boolean {
  return typeof error === 'object' && error !== null && 'status' in error && error.status === 401;
}

/** A client that, while mounted, hears another tab's session transition (SESSION_SIGNAL_KEY). */
class SessionQueryClient extends QueryClient {
  #mounts = 0;
  readonly #onStorage: (event: StorageEvent) => void;

  constructor(config: QueryClientConfig, onSignal: () => void) {
    super(config);
    this.#onStorage = (event) => {
      if (event.key === SESSION_SIGNAL_KEY) onSignal();
    };
  }

  override mount(): void {
    super.mount();
    this.#mounts += 1;
    if (this.#mounts === 1) window.addEventListener('storage', this.#onStorage);
  }

  override unmount(): void {
    super.unmount();
    this.#mounts -= 1;
    if (this.#mounts === 0) window.removeEventListener('storage', this.#onStorage);
  }
}

/**
 * A fresh client with the D9 defaults; App creates one per mount. It keeps one account's data from
 * showing under another: when ['me'] names a different account than the last one it named (A to B,
 * or A, signed out, then B), every ['account', …] query is dropped (forgetAccount), and a 401 on any
 * of them asks GET /v1/me again. Another tab's sign-out or sign-in (its endSession signal) drops them
 * too and marks ['me'] stale; no request goes out unless a page here shows ['me'].
 */
export function createQueryClient(): QueryClient {
  const queryCache = new QueryCache({
    onError: (error, query) => {
      if (isAccount(query.queryKey) && isUnauthenticated(error)) {
        void client.invalidateQueries({ queryKey: ME_KEY });
      }
    },
  });
  let identity: string | null = null;
  // Set by another tab's signal: everything is already forgotten, so the next ['me'] answer only
  // names the account a later change is measured against (its own per-user answers that arrived
  // first are not dropped a second time).
  let forgotten = false;
  const client = new SessionQueryClient(
    { queryCache, defaultOptions: { queries: { ...QUERY_DEFAULTS } } },
    () => {
      forgotten = true;
      forgetAccount(client);
      void client.invalidateQueries({ queryKey: ME_KEY });
    },
  );
  queryCache.subscribe((event) => {
    const key: unknown = event.query.queryKey;
    if (event.type !== 'updated' || !isMe(key)) return;
    // No answer yet (a rebuilt ['me'] fetching): neither signed in nor signed out.
    if (event.query.state.data === undefined) return;
    const next = identityOf(event.query.state.data);
    if (forgotten) {
      if (event.action.type !== 'success') return;
      forgotten = false;
      identity = next;
      return;
    }
    if (next === null) {
      // Signed out after an account: mark its data stale but keep it cached (A to null to A stays
      // cheap). A one-time read stays as it is.
      if (identity !== null) {
        void client.invalidateQueries({
          queryKey: ACCOUNT_KEY,
          refetchType: 'none',
          predicate: (query) => !isOneTime(query.meta),
        });
      }
      return;
    }
    // The tab that made the change wrote the signal; writing it again here would echo between tabs.
    if (identity !== null && next !== identity) forgetAccount(client);
    identity = next;
  });
  return client;
}

/**
 * The one converter every queryFn uses. The client resolves failures as values, so returning its
 * result would turn a failed poll into new data; instead the ApiFailure object itself is thrown
 * (TanStack keeps the last good data and records the failure as the query's error). An abort is
 * rethrown exactly as the client throws it.
 */
export async function unwrap<T>(pending: Promise<ApiResult<T>>): Promise<T> {
  const result = await pending;
  if (result.ok) return result.data;
  const failure: unknown = result;
  throw failure;
}
