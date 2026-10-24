// The PokéPrices session (D11): the ['me'] query and the signed-in, signed-out, loading and
// failed states every account page reads. A 401 from GET /v1/me is the signed-out state (null),
// not a problem. The header reads it passively only (useKnownSession), so public pages keep their
// exact request lists. Every query key that holds one account's data lives under ['account', …]
// (accountKey), so a session transition forgets all of it at once (endSession); ['me'] itself is
// outside it.
import { useQuery, useQueryClient, type QueryClient } from '@tanstack/react-query';
import { useCallback, useEffect, useState, useSyncExternalStore } from 'react';
import { getMe, type ApiFailure, type Me, type RequestOptions } from '../api/client';

export const ME_KEY = ['me'] as const;

/** The root of every per-user query key. Public keys (cards, prices, movers) never use it. */
export const ACCOUNT_KEY = ['account'] as const;

/**
 * The localStorage key a session transition writes, so the other open tabs of this origin forget
 * the last account too. Its value is a nonce: never an email or anything else about the account.
 */
export const SESSION_SIGNAL_KEY = 'pokeprices:session';

/** A per-user query key: ['account', ...parts]. */
export function accountKey<const Parts extends readonly unknown[]>(
  ...parts: Parts
): readonly ['account', ...Parts] {
  return [...ACCOUNT_KEY, ...parts];
}

/**
 * The query meta of a one-time read (the verification link): a signed-out ['me'] answer never
 * marks it stale, so the same account coming back does not send it a second time, and a forget
 * leaves it alone while its page still shows it (one GET per page visit, whatever ['me'] says).
 */
export const ONE_TIME = { oneTime: true } as const;

/** Whether a query carries ONE_TIME. */
export function isOneTime(meta: Readonly<Record<string, unknown>> | undefined): boolean {
  return meta?.['oneTime'] === true;
}

/**
 * Forgets everything the last account owned in this tab. An ['account', …] query no page reads is
 * removed (its fetch cancelled); one a page still reads is reset, so that page sees it empty at
 * once and an enabled reader asks again with the session the browser holds now. A one-time read
 * its page still shows is kept (ONE_TIME). Writes no signal (see endSession).
 */
export function forgetAccount(queryClient: QueryClient): void {
  queryClient.removeQueries({
    queryKey: ACCOUNT_KEY,
    predicate: (query) => query.getObserversCount() === 0,
  });
  void queryClient.resetQueries({
    queryKey: ACCOUNT_KEY,
    predicate: (query) => !isOneTime(query.meta),
  });
}

const transitionListeners = new WeakMap<QueryClient, () => void>();

/** Registers the client's own reaction to a transition this tab makes (see createQueryClient). */
export function onSessionEnd(queryClient: QueryClient, listener: () => void): void {
  transitionListeners.set(queryClient, listener);
}

let signals = 0;

/**
 * A session transition this tab made (sign-out, sign-in, sign-up, demo, deletion): forgets the
 * last account (forgetAccount) and writes SESSION_SIGNAL_KEY so the other tabs forget it too.
 * Each caller handles ['me']. Blocked storage only loses the signal: another tab then forgets the
 * last account when its own ['me'] answer names a different one, or names its first one.
 */
export function endSession(queryClient: QueryClient): void {
  transitionListeners.get(queryClient)?.();
  forgetAccount(queryClient);
  signals += 1;
  try {
    window.localStorage.setItem(SESSION_SIGNAL_KEY, `${String(Date.now())}.${String(signals)}`);
  } catch {
    // Blocked or full storage: see above.
  }
}

/** The account an ['me'] answer names (its email; GET /v1/me has no id), or null when signed out. */
export function identityOf(data: unknown): string | null {
  if (typeof data !== 'object' || data === null || !('email' in data)) return null;
  return typeof data.email === 'string' ? data.email : null;
}

export type Session =
  | { readonly state: 'loading' }
  | { readonly state: 'signed-out' }
  | { readonly state: 'signed-in'; readonly me: Me }
  | { readonly state: 'failed'; readonly failure: ApiFailure };

/** GET /v1/me as query data: the account, or null when signed out; any other failure throws. */
export async function fetchMe(options: RequestOptions = {}): Promise<Me | null> {
  const result = await getMe(options);
  if (result.ok) {
    const body: unknown = result.data;
    const me = typeof body === 'object' && body !== null && 'data' in body ? body.data : undefined;
    if (typeof me === 'object' && me !== null && 'email' in me) return me as Me;
    const failure: ApiFailure = {
      ok: false,
      status: 200,
      type: 'about:blank',
      title: 'The PokéPrices API sent an unexpected answer (HTTP 200)',
      requestId: null,
      problem: null,
    };
    const thrown: unknown = failure;
    throw thrown;
  }
  if (result.status === 401) return null;
  const failure: unknown = result;
  throw failure;
}

/** The session from ['me'] (P3 D9 defaults: polled, retry off, the last good answer kept). */
export function useSession(): Session {
  const query = useQuery<Me | null, ApiFailure>({
    queryKey: ME_KEY,
    queryFn: ({ signal }) => fetchMe({ signal }),
  });
  if (query.data === null) return { state: 'signed-out' };
  if (query.data !== undefined) return { state: 'signed-in', me: query.data };
  if (query.error !== null) return { state: 'failed', failure: query.error };
  return { state: 'loading' };
}

function arrivalStatus(): string | null {
  const state: unknown = window.history.state;
  if (typeof state !== 'object' || state === null || !('status' in state)) return null;
  return typeof state.status === 'string' ? state.status : null;
}

/**
 * The status line the navigation to this page carried (router navigate(to, status)), shown once:
 * it is kept in component state and removed from the history entry, so a reload drops it.
 */
export function useArrivalStatus(): string | null {
  const [status] = useState(arrivalStatus);
  useEffect(() => {
    if (status !== null) {
      window.history.replaceState(null, '', window.location.pathname + window.location.search);
    }
  }, [status]);
  return status;
}

/** What the cache already knows about the session: 'unknown', null (signed out) or the account. */
export type KnownSession = 'unknown' | Me | null;

const UNKNOWN = 'unknown';

/**
 * The session as the ['me'] cache already holds it, read passively for the shell: no observer and
 * no request, so public pages keep their exact request lists. 'unknown' until a page has asked,
 * and again once a held account is marked stale (another tab's session signal): that account may
 * be gone. A cached null (signed out, also after this tab's own sign-out) stays signed out. The
 * snapshot is the raw cached value, so an unchanged cache never re-renders the reader.
 */
export function useKnownSession(): KnownSession {
  const cache = useQueryClient().getQueryCache();
  const subscribe = useCallback((onChange: () => void) => cache.subscribe(onChange), [cache]);
  return useSyncExternalStore(subscribe, () => {
    const query = cache.find<Me | null>({ queryKey: ME_KEY, exact: true });
    const data = query?.state.data;
    if (query === undefined || data === undefined) return UNKNOWN;
    if (data !== null && query.state.isInvalidated) return UNKNOWN;
    return data;
  });
}
