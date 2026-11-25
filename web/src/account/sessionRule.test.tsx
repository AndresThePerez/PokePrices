// The one rule for the session ask: GET /v1/me is sent only when the browser already holds the
// XSRF-TOKEN cookie. Without it every page is signed out from the first render and asks nothing;
// a form sent from there (or the demo button) fetches the token first, then writes, then asks;
// with the cookie a page asks once, and a 401 (an expired session) is the signed-out state.
import type { QueryClient } from '@tanstack/react-query';
import { act, cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import App from '../App';
import type * as QueryModule from '../query';
import {
  accountValidationProblem,
  clearXsrfCookie,
  demoAlertsUnavailableRoutes,
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
const TYPED = 'fake-password-0000-ok';
const DEMO = 'Try the read-only demo';

function visit(path: string): void {
  window.history.replaceState(null, '', path);
}

function main(): HTMLElement {
  return screen.getByRole('main');
}

function banner(): HTMLElement {
  return screen.getByRole('banner');
}

function client(): QueryClient {
  const first = created.clients[0];
  if (first === undefined) throw new Error('App created no QueryClient');
  return first;
}

function fill(label: string, value: string): void {
  fireEvent.change(within(main()).getByLabelText(label), { target: { value } });
}

function press(name: string): void {
  fireEvent.click(within(main()).getByRole('button', { name }));
}

async function settle(): Promise<void> {
  for (let round = 0; round < 6; round += 1) {
    await act(async () => {
      await new Promise((resolve) => setTimeout(resolve, 0));
    });
  }
}

/** The recorded account requests: the public reads of the page (movers, freshness) left out. */
function accountKeys(calls: readonly { readonly key: string }[]): string[] {
  return calls
    .map((call) => call.key)
    .filter((key) => !key.startsWith('GET /v1/movers?') && key !== 'GET /v1/prices/freshness');
}

/** The header's last link: "Sign in" or "Account". */
function headerSessionLink(): string | null {
  const links = within(banner()).queryAllByRole('link', { name: /^(Sign in|Account)$/ });
  return links[0]?.textContent ?? null;
}

/** What the browser fires in this tab when another tab of the origin writes the signal. */
function otherTabSignals(): void {
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
}

beforeEach(() => {
  clearXsrfCookie();
  window.localStorage.clear();
});

afterEach(() => {
  cleanup();
  clearXsrfCookie();
  created.clients.length = 0;
  window.localStorage.clear();
  visit('/');
});

/** Each sign-in or account page with what its signed-out state shows. */
const PAGES: readonly (readonly [string, () => HTMLElement])[] = [
  ['/sign-in', () => within(main()).getByRole('button', { name: 'Sign in' })],
  ['/account', () => within(main()).getByText('You are not signed in.')],
];

/**
 * The three sample pages: signed out they read the demo's public sample (no session) before their
 * sign-in prompt shows, so each carries its sample route, its loading line and its prompt.
 */
const LIST_PAGES: readonly (readonly [string, string, string, () => HTMLElement])[] = [
  [
    '/watchlist',
    'GET /v1/demo/watchlist',
    'Loading the sample watchlist…',
    () => within(main()).getByText('Sign in to keep a watchlist.'),
  ],
  [
    '/portfolio',
    'GET /v1/demo/portfolio',
    'Loading the sample portfolio…',
    () => within(main()).getByText('Sign in to keep a portfolio.'),
  ],
  [
    '/alerts',
    'GET /v1/demo/alerts',
    'Loading the sample alerts…',
    () => within(main()).getByText('Sign in to set up alerts and webhooks.'),
  ],
];

describe('signed out with no token cookie', () => {
  it.each(PAGES)(
    '%s shows its signed-out state at once and asks neither the session nor the token',
    async (path, signedOut) => {
      const calls = stubAccountFetch({ [ME]: problemResponse(unauthenticatedProblem) });
      visit(path);
      render(<App />);
      // At once: the first render already shows it, with no loading line before it.
      expect(signedOut()).toBeTruthy();
      expect(within(main()).queryByRole('status')).toBeNull();
      expect(headerSessionLink()).toBe('Sign in');
      await settle();
      expect(signedOut()).toBeTruthy();
      expect(screen.queryByRole('alert')).toBeNull();
      expect(accountKeys(calls)).toEqual([]);
      expect(document.cookie).not.toContain('XSRF-TOKEN');
    },
  );

  it.each(LIST_PAGES)(
    '%s shows its signed-out state without asking the session or the token: the sample loading line, then the sign-in prompt',
    async (path, sample, loading, signedOut) => {
      const calls = stubAccountFetch({
        [ME]: problemResponse(unauthenticatedProblem),
        ...demoSampleUnavailableRoutes(),
        ...demoAlertsUnavailableRoutes(),
      });
      visit(path);
      render(<App />);
      // The first render already reads signed out: no session loading line, only the sample's own.
      expect(within(main()).getByRole('status').textContent).toBe(loading);
      expect(headerSessionLink()).toBe('Sign in');
      await waitFor(() => {
        expect(signedOut()).toBeTruthy();
      });
      await settle();
      expect(signedOut()).toBeTruthy();
      expect(within(main()).queryByRole('status')).toBeNull();
      expect(headerSessionLink()).toBe('Sign in');
      expect(screen.queryByRole('alert')).toBeNull();
      expect(accountKeys(calls)).toEqual([sample]);
      expect(document.cookie).not.toContain('XSRF-TOKEN');
    },
  );

  it.each(['/', '/about'])(
    '%s sends no session request and no token request, and the header reads Sign in',
    async (path) => {
      const calls = stubAccountFetch({ [ME]: problemResponse(unauthenticatedProblem) });
      visit(path);
      render(<App />);
      expect(headerSessionLink()).toBe('Sign in');
      await settle();
      expect(headerSessionLink()).toBe('Sign in');
      const keys = calls.map((call) => call.key);
      expect(keys.filter((key) => key === ME || key === TOKEN)).toEqual([]);
      expect(document.cookie).not.toContain('XSRF-TOKEN');
    },
  );

  it('/sign-in: the header link reads Sign in, marked as the current page, on the first render', () => {
    stubAccountFetch({ [ME]: problemResponse(unauthenticatedProblem) });
    visit('/sign-in');
    render(<App />);
    const link = within(banner()).getByRole('link', { name: 'Sign in' });
    expect(link.getAttribute('aria-current')).toBe('page');
    expect(within(banner()).queryByRole('link', { name: 'Account' })).toBeNull();
  });

  it('a poll or a refetch of the session on /watchlist still sends no session request', async () => {
    const calls = stubAccountFetch({
      [ME]: problemResponse(unauthenticatedProblem),
      ...demoSampleUnavailableRoutes(),
    });
    visit('/watchlist');
    render(<App />);
    await settle();
    await act(async () => {
      await client().refetchQueries({ queryKey: ['me'] });
    });
    await act(async () => {
      await client().invalidateQueries({ queryKey: ['me'] });
    });
    await settle();
    expect(within(main()).getByText('Sign in to keep a watchlist.')).toBeTruthy();
    expect(accountKeys(calls)).toEqual(['GET /v1/demo/watchlist']);
  });

  it("another tab's signal with still no cookie here sends nothing and stays signed out", async () => {
    const calls = stubAccountFetch({ [ME]: problemResponse(unauthenticatedProblem) });
    visit('/account');
    render(<App />);
    await settle();
    otherTabSignals();
    await settle();
    expect(within(main()).getByText('You are not signed in.')).toBeTruthy();
    expect(accountKeys(calls)).toEqual([]);
  });
});

describe('a cookie that appears through another tab makes this tab ask', () => {
  it('on /watchlist: no request before, one GET /v1/me after the signal, then the signed-in list', async () => {
    const calls = stubAccountFetch({
      [ME]: () => jsonResponse(signedInMe),
      'GET /v1/watchlist': jsonResponse(watchlistBody([])),
      ...demoSampleUnavailableRoutes(),
    });
    visit('/watchlist');
    render(<App />);
    await settle();
    expect(accountKeys(calls)).toEqual(['GET /v1/demo/watchlist']);
    expect(headerSessionLink()).toBe('Sign in');
    // The other tab signed in: the browser now holds its cookie, and its signal arrives.
    holdSessionCookie();
    otherTabSignals();
    await waitFor(() => {
      expect(headerSessionLink()).toBe('Account');
    });
    await settle();
    expect(accountKeys(calls).filter((key) => key === ME)).toEqual([ME]);
    expect(accountKeys(calls).slice(0, 2)).toEqual(['GET /v1/demo/watchlist', ME]);
    expect(within(main()).queryByText('Sign in to keep a watchlist.')).toBeNull();
  });
});

// The session these writes open is answered by a function: the stub sets the token cookie only for
// a browser that starts signed in (a 2xx Response given outright), and these start without it.
describe('a form sent with no token cookie fetches the token, then writes, then asks', () => {
  it('sign-in: the token, POST /login, then the session it opened, signed in without a reload', async () => {
    const calls = stubAccountFetch({
      [ME]: () => jsonResponse(signedInMe),
      'POST /login': jsonResponse({ two_factor: false }),
    });
    visit('/sign-in');
    render(<App />);
    fill('Email', 'collector@example.com');
    fill('Password', TYPED);
    press('Sign in');
    await waitFor(() => {
      expect(window.location.pathname).toBe('/account');
    });
    expect(await screen.findByText('Signed in as collector@example.com')).toBeTruthy();
    await settle();
    expect(headerSessionLink()).toBe('Account');
    expect(accountKeys(calls)).toEqual([TOKEN, 'POST /login', ME]);
  });

  it('sign-in refused: the token and one POST /login, the problem shown, the session not asked', async () => {
    const calls = stubAccountFetch({
      [ME]: problemResponse(unauthenticatedProblem),
      'POST /login': problemResponse(
        accountValidationProblem('/login', {
          email: ['These credentials do not match our records.'],
        }),
      ),
    });
    visit('/sign-in');
    render(<App />);
    fill('Email', 'collector@example.com');
    fill('Password', TYPED);
    press('Sign in');
    expect(await screen.findByRole('alert')).toBeTruthy();
    await settle();
    expect(within(main()).getByRole('button', { name: 'Sign in' })).toBeTruthy();
    expect(accountKeys(calls)).toEqual([TOKEN, 'POST /login']);
  });

  it.each(['/sign-in', '/'])(
    'the demo button on %s: the token, POST /v1/demo/session, then one session ask and the list',
    async (path) => {
      const calls = stubAccountFetch({
        [ME]: () => jsonResponse({ data: { ...signedInMe.data, read_only: true } }),
        'POST /v1/demo/session': rawResponse(204, null),
        'GET /v1/watchlist': jsonResponse({ data: [] }),
      });
      visit(path);
      render(<App />);
      press(DEMO);
      await waitFor(() => {
        expect(window.location.pathname).toBe('/watchlist');
      });
      await settle();
      expect(headerSessionLink()).toBe('Account');
      expect(accountKeys(calls).slice(0, 3)).toEqual([TOKEN, 'POST /v1/demo/session', ME]);
      expect(accountKeys(calls).filter((key) => key === ME)).toEqual([ME]);
    },
  );

  it('sign-up: the token, POST /register, then the session it opened', async () => {
    const calls = stubAccountFetch({
      [ME]: () => jsonResponse(signedInMe),
      'POST /register': rawResponse(201, ''),
    });
    visit('/sign-up');
    render(<App />);
    fill('Invite code', 'fake-invite-0000');
    fill('Email', 'collector@example.com');
    fill('Password', TYPED);
    fill('Confirm password', TYPED);
    press('Create account');
    await waitFor(() => {
      expect(window.location.pathname).toBe('/account');
    });
    await settle();
    expect(accountKeys(calls)).toEqual([TOKEN, 'POST /register', ME]);
  });

  it('forgot password: the token, then POST /forgot-password, and no session request', async () => {
    const calls = stubAccountFetch({
      [ME]: problemResponse(unauthenticatedProblem),
      'POST /forgot-password': jsonResponse({ message: 'We have emailed your reset link.' }),
    });
    visit('/password/forgot');
    render(<App />);
    fill('Email', 'collector@example.com');
    press('Send reset link');
    expect(await screen.findByText('We have emailed your reset link.')).toBeTruthy();
    await settle();
    expect(accountKeys(calls)).toEqual([TOKEN, 'POST /forgot-password']);
  });

  it('reset password: the token, then POST /reset-password', async () => {
    const calls = stubAccountFetch({
      [ME]: problemResponse(unauthenticatedProblem),
      'POST /reset-password': jsonResponse({ message: 'Your password has been reset.' }),
    });
    visit('/password/reset?token=fake0reset0value0000abcd&email=collector%40example.com');
    render(<App />);
    fill('New password', TYPED);
    fill('Confirm new password', TYPED);
    press('Save password');
    await waitFor(() => {
      expect(window.location.pathname).toBe('/sign-in');
    });
    await settle();
    expect(accountKeys(calls).slice(0, 2)).toEqual([TOKEN, 'POST /reset-password']);
    expect(screen.queryByRole('alert')).toBeNull();
  });
});

describe('with the token cookie the session is asked once', () => {
  it.each(PAGES)(
    '%s: a 401 (an expired session) is asked once, then the signed-out state and no problem',
    async (path, signedOut) => {
      setXsrfCookie();
      const calls = stubAccountFetch({ [ME]: problemResponse(unauthenticatedProblem) });
      visit(path);
      render(<App />);
      expect(within(main()).getByRole('status').textContent).toMatch(/^Loading/);
      await waitFor(() => {
        expect(signedOut()).toBeTruthy();
      });
      await settle();
      expect(headerSessionLink()).toBe('Sign in');
      expect(screen.queryByRole('alert')).toBeNull();
      expect(accountKeys(calls)).toEqual([ME]);
    },
  );

  it.each(LIST_PAGES)(
    '%s: a 401 (an expired session) is asked once, then the signed-out state, the public sample read and no problem',
    async (path, sample, _loading, signedOut) => {
      setXsrfCookie();
      const calls = stubAccountFetch({
        [ME]: problemResponse(unauthenticatedProblem),
        ...demoSampleUnavailableRoutes(),
        ...demoAlertsUnavailableRoutes(),
      });
      visit(path);
      render(<App />);
      expect(within(main()).getByRole('status').textContent).toMatch(/^Loading/);
      await waitFor(() => {
        expect(signedOut()).toBeTruthy();
      });
      await settle();
      expect(headerSessionLink()).toBe('Sign in');
      expect(screen.queryByRole('alert')).toBeNull();
      expect(accountKeys(calls)).toEqual([ME, sample]);
    },
  );

  it('/watchlist: a 200 is asked once and shows the signed-in header', async () => {
    const calls = stubAccountFetch({
      [ME]: jsonResponse(signedInMe),
      'GET /v1/watchlist': jsonResponse(watchlistBody([])),
    });
    visit('/watchlist');
    render(<App />);
    await waitFor(() => {
      expect(headerSessionLink()).toBe('Account');
    });
    await settle();
    expect(accountKeys(calls).filter((key) => key === ME)).toEqual([ME]);
  });

  it('/: a 200 is asked once and shows the signed-in header', async () => {
    const calls = stubAccountFetch({ [ME]: jsonResponse(signedInMe) });
    visit('/');
    render(<App />);
    await waitFor(() => {
      expect(headerSessionLink()).toBe('Account');
    });
    await settle();
    expect(calls.map((call) => call.key).filter((key) => key === ME)).toEqual([ME]);
  });

  it('sign-out reads signed out at once, asks once more (the cookie stays) and never again', async () => {
    const calls = stubAccountFetch({
      [ME]: [jsonResponse(signedInMe), problemResponse(unauthenticatedProblem)],
      'POST /logout': rawResponse(204, null),
    });
    visit('/account');
    render(<App />);
    expect(await screen.findByText('Signed in as collector@example.com')).toBeTruthy();
    press('Sign out');
    await waitFor(() => {
      expect(headerSessionLink()).toBe('Sign in');
    });
    await settle();
    await settle();
    expect(headerSessionLink()).toBe('Sign in');
    expect(accountKeys(calls)).toEqual([ME, 'POST /logout', ME]);
  });
});
