// Review edge cases for the session rule: GET /v1/me only with the exact, non-empty XSRF-TOKEN
// cookie. States a real browser reaches: a leftover token cookie with no session, a lookalike or
// empty cookie on an account page, sign-out followed by route changes, the demo button on an
// account page, a cookie another tab wrote, and a cookie that goes away while signed in.
import type { QueryClient } from '@tanstack/react-query';
import { act, cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import App from '../App';
import type * as QueryModule from '../query';
import { navigate } from '../router';
import {
  clearXsrfCookie,
  demoAlertsRoutes,
  demoSampleRoutes,
  demoSampleUnavailableRoutes,
  holdSessionCookie,
  jsonResponse,
  problemResponse,
  rawResponse,
  setXsrfCookie,
  signedInMe,
  stubAccountFetch,
  unauthenticatedProblem,
  watchlistBody,
} from '../test/fixtures';
import { SESSION_SIGNAL_KEY } from './session';

vi.mock('lightweight-charts', () => import('../test/lightweightChartsFake'));

const created = vi.hoisted(() => ({ clients: [] as QueryClient[] }));
vi.mock('../query', async (importOriginal) => {
  const actual = await importOriginal<typeof QueryModule>();
  return {
    ...actual,
    createQueryClient: (): QueryClient => {
      const client = actual.createQueryClient();
      created.clients.push(client);
      return client;
    },
  };
});

const ME = 'GET /v1/me';
const TOKEN = 'GET /sanctum/csrf-cookie';
const EXPIRED = 'Thu, 01 Jan 1970 00:00:00 GMT';

function visit(path: string): void {
  window.history.replaceState(null, '', path);
}

function main(): HTMLElement {
  return screen.getByRole('main');
}

function headerSessionLink(): string | null {
  const links = within(screen.getByRole('banner')).queryAllByRole('link', {
    name: /^(Sign in|Account)$/,
  });
  return links[0]?.textContent ?? null;
}

async function settle(): Promise<void> {
  for (let round = 0; round < 6; round += 1) {
    await act(async () => {
      await new Promise((resolve) => setTimeout(resolve, 0));
    });
  }
}

function go(path: string): void {
  act(() => {
    navigate(path);
  });
}

function accountKeys(calls: readonly { readonly key: string }[]): string[] {
  return calls
    .map((call) => call.key)
    .filter((key) => !key.startsWith('GET /v1/movers?') && key !== 'GET /v1/prices/freshness');
}

function clearAll(): void {
  clearXsrfCookie();
  for (const name of ['XSRF-TOKEN2', 'XXSRF-TOKEN', 'X-XSRF-TOKEN']) {
    document.cookie = `${name}=; path=/; expires=${EXPIRED}`;
  }
}

beforeEach(() => {
  clearAll();
  window.localStorage.clear();
});

afterEach(() => {
  cleanup();
  clearAll();
  created.clients.length = 0;
  window.localStorage.clear();
  visit('/');
});

describe('which cookies count as held on an account page', () => {
  it.each([
    ['an empty XSRF-TOKEN value', 'XSRF-TOKEN='],
    ['a name ending in XSRF-TOKEN2', 'XSRF-TOKEN2=abc'],
    ['a name that only contains XSRF-TOKEN', 'XXSRF-TOKEN=abc'],
  ])('%s: /watchlist is signed out at once and asks no session', async (_label, cookie) => {
    document.cookie = `${cookie}; path=/`;
    const calls = stubAccountFetch({
      [ME]: problemResponse(unauthenticatedProblem),
      ...demoSampleUnavailableRoutes(),
    });
    visit('/watchlist');
    render(<App />);
    // The first render already reads signed out: only the sample's own loading line shows.
    expect(within(main()).getByRole('status').textContent).toBe('Loading the sample watchlist…');
    expect(headerSessionLink()).toBe('Sign in');
    await waitFor(() => {
      expect(within(main()).getByText('Sign in to keep a watchlist.')).toBeTruthy();
    });
    await settle();
    expect(within(main()).queryByRole('status')).toBeNull();
    expect(headerSessionLink()).toBe('Sign in');
    expect(accountKeys(calls)).toEqual(['GET /v1/demo/watchlist']);
  });
});

describe('a leftover token cookie with no session (signed out earlier)', () => {
  it('asks once, gets one 401 and keeps asking nothing across route changes', async () => {
    setXsrfCookie();
    const calls = stubAccountFetch({
      [ME]: problemResponse(unauthenticatedProblem),
      ...demoSampleUnavailableRoutes(),
    });
    visit('/watchlist');
    render(<App />);
    await waitFor(() => {
      expect(within(main()).getByText('Sign in to keep a watchlist.')).toBeTruthy();
    });
    await settle();
    expect(headerSessionLink()).toBe('Sign in');
    for (const path of ['/account', '/sign-in', '/portfolio', '/']) {
      go(path);
      await settle();
      expect(headerSessionLink()).toBe('Sign in');
    }
    // One session ask, the public sample reads of the two list pages, then the home page's three
    // (the two failed list reads are asked again there, the alerts for the first time).
    expect(accountKeys(calls)).toEqual([
      ME,
      'GET /v1/demo/watchlist',
      'GET /v1/demo/portfolio',
      'GET /v1/demo/watchlist',
      'GET /v1/demo/portfolio',
      'GET /v1/demo/alerts',
    ]);
  });

  it('landing on a public price page asks once, and the account pages after it ask nothing more', async () => {
    setXsrfCookie();
    const calls = stubAccountFetch({
      [ME]: problemResponse(unauthenticatedProblem),
      'GET /v1/movers?direction=up&limit=5': jsonResponse({ data: [] }),
      ...demoSampleUnavailableRoutes(),
    });
    visit('/');
    render(<App />);
    await settle();
    expect(headerSessionLink()).toBe('Sign in');
    go('/watchlist');
    await settle();
    expect(within(main()).getByText('Sign in to keep a watchlist.')).toBeTruthy();
    go('/sign-in');
    await settle();
    // The home page's three public reads come first; /watchlist then asks its own again.
    expect(accountKeys(calls)).toEqual([
      'GET /v1/demo/watchlist',
      'GET /v1/demo/portfolio',
      'GET /v1/demo/alerts',
      ME,
      'GET /v1/demo/watchlist',
    ]);
  });
});

describe('sign-out in this tab', () => {
  it('reads signed out at once and a loop of route changes after it asks nothing more', async () => {
    setXsrfCookie();
    const calls = stubAccountFetch({
      [ME]: [jsonResponse(signedInMe), problemResponse(unauthenticatedProblem)],
      'POST /logout': rawResponse(204, null),
      ...demoSampleRoutes(),
      ...demoAlertsRoutes(),
    });
    visit('/account');
    render(<App />);
    expect(await screen.findByText('Signed in as collector@example.com')).toBeTruthy();
    fireEvent.click(within(main()).getByRole('button', { name: 'Sign out' }));
    await waitFor(() => {
      expect(headerSessionLink()).toBe('Sign in');
    });
    await settle();
    // The cookie stays after a sign-out, so the first account page opened afterwards may ask once
    // (the 401 answer is stale by design); a loop of route changes after that sends nothing more.
    go('/watchlist');
    await settle();
    expect(headerSessionLink()).toBe('Sign in');
    const afterFirstPage = accountKeys(calls);
    expect(afterFirstPage.filter((key) => key === ME).length).toBeLessThanOrEqual(3);
    for (const path of ['/sign-in', '/account', '/', '/watchlist']) {
      go(path);
      await settle();
      expect(headerSessionLink()).toBe('Sign in');
    }
    expect(accountKeys(calls)).toEqual(afterFirstPage);
  });
});

describe('the cookie appearing mid-life without a page load', () => {
  it('the demo button on /watchlist: the token, the demo, one session ask, and the header changes', async () => {
    const calls = stubAccountFetch({
      [ME]: () => jsonResponse({ data: { ...signedInMe.data, read_only: true } }),
      'POST /v1/demo/session': rawResponse(204, null),
      'GET /v1/watchlist': jsonResponse(watchlistBody([])),
      ...demoSampleUnavailableRoutes(),
    });
    visit('/watchlist');
    render(<App />);
    expect(headerSessionLink()).toBe('Sign in');
    // The prompt (and its demo button) shows once the public sample read has settled.
    fireEvent.click(await within(main()).findByRole('button', { name: 'Try the read-only demo' }));
    await waitFor(() => {
      expect(headerSessionLink()).toBe('Account');
    });
    await settle();
    expect(accountKeys(calls).slice(0, 4)).toEqual([
      'GET /v1/demo/watchlist',
      TOKEN,
      'POST /v1/demo/session',
      ME,
    ]);
    expect(accountKeys(calls).filter((key) => key === ME)).toEqual([ME]);
  });

  it("another tab's sign-in signal on /sign-in asks once and the header reads Account", async () => {
    const calls = stubAccountFetch({ [ME]: () => jsonResponse(signedInMe) });
    visit('/sign-in');
    render(<App />);
    await settle();
    expect(accountKeys(calls)).toEqual([]);
    holdSessionCookie();
    act(() => {
      window.dispatchEvent(
        new StorageEvent('storage', {
          key: SESSION_SIGNAL_KEY,
          oldValue: null,
          newValue: '1700000000000.1',
          storageArea: window.localStorage,
        }),
      );
    });
    await waitFor(() => {
      expect(headerSessionLink()).toBe('Account');
    });
    await settle();
    expect(accountKeys(calls).filter((key) => key === ME)).toEqual([ME]);
  });
});

describe('a token cookie that goes away while the page is open', () => {
  it('a forced refetch of the session sends no request once the cookie is gone', async () => {
    setXsrfCookie();
    const calls = stubAccountFetch({ [ME]: () => jsonResponse(signedInMe) });
    visit('/account');
    render(<App />);
    expect(await screen.findByText('Signed in as collector@example.com')).toBeTruthy();
    await settle();
    expect(accountKeys(calls)).toEqual([ME]);
    clearXsrfCookie();
    const first = created.clients[0];
    if (first === undefined) throw new Error('App created no QueryClient');
    await act(async () => {
      await first.refetchQueries({ queryKey: ['me'] });
    });
    await settle();
    expect(accountKeys(calls)).toEqual([ME]);
  });
});
