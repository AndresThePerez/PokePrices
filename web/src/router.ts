// The PokéPrices SPA router: its routes over the History API, no dependency.
import { useSyncExternalStore } from 'react';
import type { MoversDirection } from './api/client';

export type Route =
  | { readonly name: 'home' }
  | { readonly name: 'search'; readonly query: URLSearchParams }
  | { readonly name: 'card'; readonly id: string }
  | { readonly name: 'movers'; readonly direction: MoversDirection }
  | { readonly name: 'sign-in'; readonly next: string }
  | { readonly name: 'sign-up' }
  | { readonly name: 'forgot-password' }
  | {
      readonly name: 'reset-password';
      readonly token: string | null;
      readonly email: string | null;
    }
  | { readonly name: 'account' }
  | { readonly name: 'verify-email'; readonly link: string | null }
  | { readonly name: 'watchlist' }
  | { readonly name: 'portfolio' }
  | { readonly name: 'alerts' }
  | { readonly name: 'not-found' };

const NAVIGATE_EVENT = 'pokeprices:navigate';
const CARD = /^\/card\/([^/]+)$/;

/** Where sign-in lands when no usable next was given. */
const ACCOUNT_PATH = '/account';

/**
 * The page to open after signing in (D10): next only when it is an in-app path, i.e. it starts
 * with exactly one '/' (not '//' or '/\') and names a route; otherwise /account.
 */
export function safeNext(raw: string | null): string {
  if (raw === null || !raw.startsWith('/') || raw.startsWith('//') || raw.startsWith('/\\')) {
    return ACCOUNT_PATH;
  }
  const end = raw.search(/[?#]/);
  const pathname = end < 0 ? raw : raw.slice(0, end);
  const rest = end < 0 ? '' : raw.slice(end);
  const hashAt = rest.indexOf('#');
  const search = hashAt < 0 ? rest : rest.slice(0, hashAt);
  return matchRoute(pathname, search.startsWith('?') ? search : '').name === 'not-found'
    ? ACCOUNT_PATH
    : raw;
}

/** Maps a pathname and query string to a route; card ids are percent-decoded exactly once. */
export function matchRoute(pathname: string, search: string): Route {
  if (pathname === '/') return { name: 'home' };
  if (pathname === '/search') return { name: 'search', query: new URLSearchParams(search) };
  if (pathname === '/movers') {
    const down = new URLSearchParams(search).get('direction') === 'down';
    return { name: 'movers', direction: down ? 'down' : 'up' };
  }
  switch (pathname) {
    case '/sign-in':
      return { name: 'sign-in', next: safeNext(new URLSearchParams(search).get('next')) };
    case '/sign-up':
      return { name: 'sign-up' };
    case '/password/forgot':
      return { name: 'forgot-password' };
    case '/password/reset': {
      const query = new URLSearchParams(search);
      return { name: 'reset-password', token: query.get('token'), email: query.get('email') };
    }
    case '/account':
      return { name: 'account' };
    case '/account/verify-email':
      return { name: 'verify-email', link: new URLSearchParams(search).get('link') };
    case '/watchlist':
      return { name: 'watchlist' };
    case '/portfolio':
      return { name: 'portfolio' };
    case '/alerts':
      return { name: 'alerts' };
  }
  const encodedId = CARD.exec(pathname)?.[1];
  if (encodedId !== undefined) {
    try {
      return { name: 'card', id: decodeURIComponent(encodedId) };
    } catch {
      return { name: 'not-found' };
    }
  }
  return { name: 'not-found' };
}

/** The in-app path of a card page; the id travels as one encoded path segment. */
export function cardPath(id: string): string {
  return `/card/${encodeURIComponent(id)}`;
}

/**
 * Pushes a same-origin path onto the history and re-renders every useLocation() reader. A status
 * line for the next page (D10: "Account created. …", "Password saved. …") travels in the history
 * entry's state; the page shows it once (account/session.ts useArrivalStatus).
 */
export function navigate(to: string, status?: string): void {
  window.history.pushState(status === undefined ? null : { status }, '', to);
  window.dispatchEvent(new Event(NAVIGATE_EVENT));
}

function subscribe(onChange: () => void): () => void {
  window.addEventListener('popstate', onChange);
  window.addEventListener(NAVIGATE_EVENT, onChange);
  return () => {
    window.removeEventListener('popstate', onChange);
    window.removeEventListener(NAVIGATE_EVENT, onChange);
  };
}

function currentLocation(): string {
  return window.location.pathname + window.location.search;
}

/** The current pathname and its route; re-renders on navigate() and on back/forward. */
export function useRoute(): { readonly pathname: string; readonly route: Route } {
  const location = useSyncExternalStore(subscribe, currentLocation);
  const queryAt = location.indexOf('?');
  const pathname = queryAt < 0 ? location : location.slice(0, queryAt);
  const search = queryAt < 0 ? '' : location.slice(queryAt);
  return { pathname, route: matchRoute(pathname, search) };
}
