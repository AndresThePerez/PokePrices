// The PokéPrices SPA router: four routes over the History API, no dependency.
import { useSyncExternalStore } from 'react';

/** Which list of the movers board: rises (up) or falls (down). */
export type MoverDirection = 'up' | 'down';

export type Route =
  | { readonly name: 'home' }
  | { readonly name: 'search'; readonly query: URLSearchParams }
  | { readonly name: 'card'; readonly id: string }
  | { readonly name: 'movers'; readonly direction: MoverDirection }
  | { readonly name: 'not-found' };

const NAVIGATE_EVENT = 'pokeprices:navigate';
const CARD = /^\/card\/([^/]+)$/;

/** Maps a pathname and query string to a route; card ids are percent-decoded exactly once. */
export function matchRoute(pathname: string, search: string): Route {
  if (pathname === '/') return { name: 'home' };
  if (pathname === '/search') return { name: 'search', query: new URLSearchParams(search) };
  if (pathname === '/movers') {
    const down = new URLSearchParams(search).get('direction') === 'down';
    return { name: 'movers', direction: down ? 'down' : 'up' };
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

/** Pushes a same-origin path onto the history and re-renders every useLocation() reader. */
export function navigate(to: string): void {
  window.history.pushState(null, '', to);
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
