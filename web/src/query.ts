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
  onSessionEnd,
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
 * or A, signed out, then B), every ['account', …] query is forgotten (forgetAccount), and so is
 * everything cached before the first account this client learns of (a public page reads per-user
 * data without ['me']); a 401 on any of them asks GET /v1/me again. This tab's own sign-out or
 * sign-in (endSession) and another tab's (its signal) forget them too; the signal also drops an
 * ['me'] request still in flight (it carries the old session's cookie) and marks ['me'] stale; no
 * request goes out unless a page here shows ['me'].
 */
export function createQueryClient(): QueryClient {
  const queryCache = new QueryCache({
    onError: (error, query) => {
      if (isAccount(query.queryKey) && isUnauthenticated(error)) {
        void client.invalidateQueries({ queryKey: ME_KEY });
      }
    },
  });
  // The account the last ['me'] answer named; null until the first one names an account.
  let identity: string | null = null;
  // Set by a session transition (this tab's endSession or another tab's signal): everything is
  // already forgotten, so the next ['me'] answer only names the account a later change is measured
  // against (its own per-user answers that arrived first are not dropped a second time).
  let forgotten = false;
  const client = new SessionQueryClient(
    { queryCache, defaultOptions: { queries: { ...QUERY_DEFAULTS } } },
    () => {
      forgotten = true;
      forgetAccount(client);
      void client.invalidateQueries({ queryKey: ME_KEY, refetchType: 'none' });
      // An answer to a request sent before the change would name the old account: cancel it (a
      // first load is left with no answer, an earlier answer is put back), then mark ['me'] stale
      // again and ask where a page shows it.
      void client
        .cancelQueries({ queryKey: ME_KEY })
        .then(() => client.invalidateQueries({ queryKey: ME_KEY }));
    },
  );
  onSessionEnd(client, () => {
    forgotten = true;
  });
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
    // A different account, or the first one: whatever was cached before cannot be told apart from
    // another account's. The tab that made the change wrote the signal; writing it again here
    // would echo between tabs.
    if (next !== identity) forgetAccount(client);
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
