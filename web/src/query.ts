// The PokéPrices query client (D9): TanStack Query polling every five minutes while the tab is
// visible. No push, no websockets; the browser's HTTP cache revalidates with the API's ETags.
import { QueryCache, QueryClient } from '@tanstack/react-query';
import { ACCOUNT_KEY, ME_KEY, endSession, identityOf } from './account/session';
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

/**
 * A fresh client with the D9 defaults; App creates one per mount. It keeps one account's data from
 * showing under another: when ['me'] names a different account than the last one it named (A to B,
 * or A, signed out, then B), every ['account', …] query is dropped (endSession), and a 401 on any
 * of them asks GET /v1/me again.
 */
export function createQueryClient(): QueryClient {
  const queryCache = new QueryCache({
    onError: (error, query) => {
      if (isAccount(query.queryKey) && isUnauthenticated(error)) {
        void client.invalidateQueries({ queryKey: ME_KEY });
      }
    },
  });
  const client = new QueryClient({
    queryCache,
    defaultOptions: { queries: { ...QUERY_DEFAULTS } },
  });
  let identity: string | null = null;
  queryCache.subscribe((event) => {
    const key: unknown = event.query.queryKey;
    if (event.type !== 'updated' || !isMe(key)) return;
    const next = identityOf(event.query.state.data);
    if (next === null) {
      // Signed out after an account: mark its data stale but keep it cached (A to null to A stays cheap).
      if (identity !== null)
        void client.invalidateQueries({ queryKey: ACCOUNT_KEY, refetchType: 'none' });
      return;
    }
    if (identity !== null && next !== identity) endSession(client);
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
