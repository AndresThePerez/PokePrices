// The header's last link for a signed-out visitor: "Sign in" on every route of the route table,
// from the first render on, without one request of the header's own. A browser that holds the
// session cookie may be signed in: there the link stays "Account" until the session is answered.
import { act, cleanup, render, screen, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import App from './App';
import type { Route } from './router';
import { ROUTES } from './routes';
import {
  FORBIDDEN_WORDS,
  answerMovers,
  cardBody,
  clearXsrfCookie,
  isHistoryUrl,
  isMoversUrl,
  jsonResponse,
  problemResponse,
  searchPage,
  setXsrfCookie,
  signedInMe,
  stubFetch,
  unauthenticatedProblem,
} from './test/fixtures';

vi.mock('lightweight-charts', () => import('./test/lightweightChartsFake'));

/** One address per route of the route table; a new route does not compile until it has one. */
const PATHS: Readonly<Record<Route['name'], string>> = {
  home: '/',
  search: '/search?q=unown',
  card: '/card/ex10-%3F',
  movers: '/movers',
  'sign-in': '/sign-in',
  'sign-up': '/sign-up',
  'forgot-password': '/password/forgot',
  'reset-password': '/password/reset?token=fake-token&email=a%40example.com',
  account: '/account',
  'verify-email': '/account/verify-email',
  watchlist: '/watchlist',
  portfolio: '/portfolio',
  alerts: '/alerts',
  about: '/about',
  'not-found': '/nowhere',
};

/** How many times each route's own page asks GET /v1/me from a browser with no cookie. */
const ME_ASKS: Readonly<Record<Route['name'], number>> = {
  home: 0,
  search: 0,
  card: 0,
  movers: 0,
  'sign-in': 1,
  'sign-up': 0,
  'forgot-password': 0,
  'reset-password': 0,
  account: 1,
  'verify-email': 0,
  watchlist: 1,
  portfolio: 1,
  alerts: 1,
  about: 0,
  'not-found': 0,
};

const ROUTE_NAMES = Object.keys(ROUTES) as Route['name'][];

function visit(path: string): void {
  window.history.replaceState(null, '', path);
}

function lastLink(): [string | null, string | null] {
  const nav = within(screen.getByRole('banner')).getByRole('navigation', { name: 'Main' });
  const last = within(nav).getAllByRole('link').at(-1);
  return [last?.textContent ?? null, last?.getAttribute('href') ?? null];
}

function navLinkCount(): number {
  const nav = within(screen.getByRole('banner')).getByRole('navigation', { name: 'Main' });
  return within(nav).getAllByRole('link').length;
}

function answer(url: string, me: () => Response | Error): Response | Error {
  if (url.startsWith('/v1/me')) return me();
  if (isMoversUrl(url)) return answerMovers(url);
  if (url.startsWith('/v1/cards?')) return jsonResponse(searchPage);
  if (isHistoryUrl(url)) return new Error('no history in this test');
  if (url.startsWith('/v1/cards/')) return jsonResponse(cardBody);
  return new Error(`no request expected: ${url}`);
}

function meCalls(calls: readonly { url: string }[]): string[] {
  return calls.map((call) => call.url).filter((url) => url.startsWith('/v1/me'));
}

async function settle(): Promise<void> {
  for (let round = 0; round < 5; round += 1) {
    await act(async () => {
      await new Promise((resolve) => setTimeout(resolve, 0));
    });
  }
}

afterEach(() => {
  cleanup();
  clearXsrfCookie();
  visit('/');
});

describe('the signed-out header label on every route of the route table', () => {
  it('has an address for each route of routes.ts', () => {
    expect(Object.keys(PATHS).sort()).toEqual([...ROUTE_NAMES].sort());
  });

  it.each(ROUTE_NAMES)(
    'reads Sign in on %s from the first render, and after the page settled',
    async (name) => {
      stubFetch((url) => answer(url, () => problemResponse(unauthenticatedProblem)));
      visit(PATHS[name]);
      render(<App />);
      expect(lastLink()).toEqual(['Sign in', '/sign-in']);
      expect(navLinkCount()).toBe(6);
      await settle();
      expect(lastLink()).toEqual(['Sign in', '/sign-in']);
      expect(navLinkCount()).toBe(6);
      const banner = screen.getByRole('banner');
      expect(within(banner).queryByRole('link', { name: 'Account' })).toBeNull();
      for (const word of FORBIDDEN_WORDS) expect(banner.textContent).not.toMatch(word);
    },
  );

  it.each(ROUTE_NAMES)('adds no session request of its own on %s', async (name) => {
    const calls = stubFetch((url) => answer(url, () => problemResponse(unauthenticatedProblem)));
    visit(PATHS[name]);
    render(<App />);
    await settle();
    expect(meCalls(calls)).toHaveLength(ME_ASKS[name]);
  });
});

describe('the header label in a browser that holds the session cookie', () => {
  it('keeps Account while the session is not answered, then reads Sign in on a 401', async () => {
    let release: (response: Response) => void = () => undefined;
    const held = new Promise<Response>((resolve) => {
      release = resolve;
    });
    const urls: string[] = [];
    vi.stubGlobal('fetch', (input: string | URL) => {
      const url = String(input);
      urls.push(url);
      if (url.startsWith('/v1/me')) return held;
      const settled = answerMovers(url);
      return settled instanceof Response ? Promise.resolve(settled) : Promise.reject(settled);
    });
    setXsrfCookie();
    visit('/movers');
    render(<App />);
    await settle();
    expect(lastLink()).toEqual(['Account', '/account']);
    expect(meCalls(urls.map((url) => ({ url })))).toHaveLength(1);
    release(problemResponse(unauthenticatedProblem));
    await settle();
    expect(lastLink()).toEqual(['Sign in', '/sign-in']);
    expect(meCalls(urls.map((url) => ({ url })))).toHaveLength(1);
    vi.unstubAllGlobals();
  });

  it('keeps Account on a page that asks nothing (the cookie may belong to a session)', async () => {
    const calls = stubFetch((url) => answer(url, () => problemResponse(unauthenticatedProblem)));
    setXsrfCookie();
    visit('/nowhere');
    render(<App />);
    await settle();
    expect(lastLink()).toEqual(['Account', '/account']);
    expect(meCalls(calls)).toEqual([]);
  });

  it('reads Account once the session is answered signed in', async () => {
    stubFetch((url) => answer(url, () => jsonResponse(signedInMe)));
    setXsrfCookie();
    visit('/movers');
    render(<App />);
    await settle();
    expect(lastLink()).toEqual(['Account', '/account']);
  });
});
