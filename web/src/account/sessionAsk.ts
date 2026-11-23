// The one rule for when a public price page asks for the session (PublicSession mounts the ask
// from it), and what a page may read from it: whether that ask is still waiting for its answer.
import { useQueryClient } from '@tanstack/react-query';
import { useSyncExternalStore } from 'react';
import { useRoute, type Route } from '../router';
import { ME_KEY, holdsSessionCookie, useCacheSubscription } from './session';

/** The public routes that show a price and, with the cookie, ask for the session. */
const ASKING: ReadonlySet<Route['name']> = new Set(['home', 'search', 'card', 'movers']);

/** Whether this route asks GET /v1/me: a public price page in a browser that holds the cookie. */
export function asksSession(route: Route['name']): boolean {
  return ASKING.has(route) && holdsSessionCookie();
}

/**
 * True while this page's ask for the session is on its way: the ['me'] read is running and no
 * answer is cached. False without the cookie, off the asking routes, once any answer (an account
 * or a 401's null) is cached, once the ask failed (500, network, wrong shape), and when nothing
 * was asked (a cookie a write set on this page asks nothing until the next page). It reads the
 * cache passively, like useKnownSession: no observer and no request of its own.
 */
export function useSessionAwaited(): boolean {
  const { route } = useRoute();
  const cache = useQueryClient().getQueryCache();
  const subscribe = useCacheSubscription(cache);
  const waiting = useSyncExternalStore(subscribe, () => {
    const state = cache.find({ queryKey: ME_KEY, exact: true })?.state;
    return (
      state !== undefined &&
      state.data === undefined &&
      state.status === 'pending' &&
      state.fetchStatus === 'fetching'
    );
  });
  return waiting && asksSession(route.name);
}
