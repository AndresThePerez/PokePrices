// Session follow-ups (Q0-i1-T03): a save whose re-read fails still shows what was saved; another
// tab's sign-out or sign-in reaches this tab through a localStorage signal (no request of its own);
// the one-time verification GET stays one GET across a signed-out poll; and a window focus after
// another tab switched accounts asks for the list once.
import type { QueryClient } from '@tanstack/react-query';
import { act, cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import App from '../App';
import type * as QueryModule from '../query';
import { navigate } from '../router';
import {
  clearXsrfCookie,
  historyFor,
  holdingOf,
  jsonResponse,
  notificationsBody,
  portfolioBody,
  pricedCardBody,
  problemResponse,
  rawResponse,
  serverErrorProblem,
  signedInMe,
  stubAccountFetch,
  unauthenticatedProblem,
  watchlistBody,
  webhooksBody,
  type AccountCall,
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

function visit(path: string): void {
  window.history.replaceState(null, '', path);
}

afterEach(() => {
  cleanup();
  clearXsrfCookie();
  created.clients.length = 0;
  window.localStorage.clear();
  visit('/');
});

const A_EMAIL = signedInMe.data.email;
const B_ME = { data: { ...signedInMe.data, email: 'second@example.com' } };
const SIGNATURE = 'fake0signature0000beef';
const LINK = `/email/verify/7/0a1b2c?expires=1700000000&signature=${SIGNATURE}`;
const VERIFY_PAGE = `/account/verify-email?link=${encodeURIComponent(LINK)}`;

function client(): QueryClient {
  const first = created.clients[0];
  if (first === undefined) throw new Error('App created no QueryClient');
  return first;
}

function count(calls: readonly AccountCall[], key: string): number {
  return calls.filter((call) => call.key === key).length;
}

async function settle(): Promise<void> {
  for (let round = 0; round < 6; round += 1) {
    await act(async () => {
      await new Promise((resolve) => setTimeout(resolve, 0));
    });
  }
}

async function pollMe(): Promise<void> {
  await act(async () => {
    await client().refetchQueries({ queryKey: ['me'] });
  });
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

function digest(): boolean {
  return screen.getByRole<HTMLInputElement>('checkbox', { name: 'Send me a daily digest' }).checked;
}

describe('a save whose re-read fails still confirms what was saved (F1)', () => {
  it('shows the PUT answer beside "Saved." until a fresher read arrives', async () => {
    const calls = stubAccountFetch({
      'GET /v1/me': jsonResponse(signedInMe),
      'GET /v1/webhooks': jsonResponse(webhooksBody([])),
      'GET /v1/me/notifications': [
        jsonResponse(notificationsBody()),
        problemResponse(serverErrorProblem('/v1/me/notifications')),
        jsonResponse(notificationsBody({ digest: false })),
      ],
      'PUT /v1/me/notifications': jsonResponse(notificationsBody({ digest: true })),
    });
    visit('/alerts');
    render(<App />);
    await screen.findByText(`Email (${A_EMAIL})`);
    expect(digest()).toBe(false);
    fireEvent.click(screen.getByRole('checkbox', { name: 'Send me a daily digest' }));
    fireEvent.click(screen.getByRole('button', { name: 'Save' }));
    expect(await screen.findByText('Saved.')).toBeTruthy();
    await settle();
    expect(count(calls, 'GET /v1/me/notifications')).toBe(2);
    // The re-read failed: the form shows the saved settings the PUT answered, not the old cache.
    expect(screen.getByText('Saved.')).toBeTruthy();
    expect(digest()).toBe(true);
    // A later read is fresher than the PUT answer and wins.
    await act(async () => {
      await client().refetchQueries({ queryKey: ['account', 'notifications'] });
    });
    await settle();
    expect(count(calls, 'GET /v1/me/notifications')).toBe(3);
    expect(digest()).toBe(false);
  });
});

describe('another tab signing out or in reaches this tab (F2)', () => {
  it('a sign-out here writes the signal, a nonce without the email', async () => {
    stubAccountFetch({
      'GET /v1/me': [jsonResponse(signedInMe), problemResponse(unauthenticatedProblem)],
      'POST /logout': rawResponse(204, null),
    });
    visit('/account');
    render(<App />);
    await screen.findByText(`Signed in as ${A_EMAIL}`);
    expect(window.localStorage.getItem(SESSION_SIGNAL_KEY)).toBeNull();
    fireEvent.click(screen.getByRole('button', { name: 'Sign out' }));
    await screen.findByRole('heading', {
      level: 1,
      name: 'Pokémon card prices at the daily close',
    });
    const signal = window.localStorage.getItem(SESSION_SIGNAL_KEY);
    expect(signal).not.toBeNull();
    expect(signal).not.toContain(A_EMAIL);
    expect(signal).not.toContain('@');
  });

  it('after another tab signs A out, the card page does not prefill A quantity and asks nothing of its own', async () => {
    let portfolio: Response = jsonResponse(
      portfolioBody([
        holdingOf({ variant_key: 'ex10-?:holo:unlimited:-', card_id: 'ex10-?', quantity: 7 }),
      ]),
    );
    const calls = stubAccountFetch({
      'GET /v1/me': jsonResponse(signedInMe),
      'GET /v1/portfolio': () => portfolio,
      'GET /v1/watchlist': jsonResponse(watchlistBody([])),
      'GET /v1/cards/ex10-%3F': jsonResponse(pricedCardBody),
      'GET /v1/cards/ex10-%3F/prices?range=1M': jsonResponse(historyFor(pricedCardBody)),
    });
    visit('/portfolio');
    render(<App />);
    await waitFor(() => {
      expect(client().getQueryData(['account', 'portfolio'])).toBeDefined();
    });
    act(() => {
      navigate('/card/ex10-%3F');
    });
    await screen.findByRole('heading', { level: 1, name: 'Unown' });
    await settle();
    const before = calls.map((call) => call.key);

    // Another tab signs A out and writes the signal; this tab's card page, which reads the
    // lists of a known session, reads them again with the session the browser holds now.
    portfolio = problemResponse({ ...unauthenticatedProblem, instance: '/v1/portfolio' });
    const written = window.localStorage.getItem(SESSION_SIGNAL_KEY);
    otherTabSignals();
    await settle();
    expect(calls.map((call) => call.key)).toEqual([
      ...before,
      'GET /v1/portfolio',
      'GET /v1/watchlist',
    ]);
    // Handling the signal writes nothing back (no echo between tabs).
    expect(window.localStorage.getItem(SESSION_SIGNAL_KEY)).toBe(written);

    const prices = screen.getByRole('region', { name: 'Prices' });
    fireEvent.click(
      within(prices).getByRole('button', { name: 'Add holo, unlimited to your portfolio' }),
    );
    await settle();
    expect(screen.queryByDisplayValue('7')).toBeNull();
    expect(
      await within(prices).findByText('Sign in to keep a watchlist and a portfolio.'),
    ).toBeTruthy();
  });
});

describe('the one-time verification GET stays one GET (F3)', () => {
  it('a poll that finds the session signed out and then the same account again asks the link once', async () => {
    let me: Response = jsonResponse(signedInMe);
    const calls = stubAccountFetch({
      'GET /v1/me': () => me,
      [`GET ${LINK}`]: [rawResponse(204, null), problemResponse(serverErrorProblem(LINK))],
    });
    visit(VERIFY_PAGE);
    render(<App />);
    expect(await screen.findByText('Your email address is verified.')).toBeTruthy();
    await settle();

    me = problemResponse(unauthenticatedProblem);
    await pollMe();
    expect(await screen.findByText('Sign in to verify your email address.')).toBeTruthy();
    me = jsonResponse(signedInMe);
    await pollMe();
    await settle();
    expect(count(calls, `GET ${LINK}`)).toBe(1);
    expect(screen.getByText('Your email address is verified.')).toBeTruthy();
  });
});

describe('a window focus after another tab switched accounts (F5)', () => {
  it('asks for the list once after the signal, never once for each identity', async () => {
    let me: Response = jsonResponse(signedInMe);
    let list: Response = jsonResponse(watchlistBody());
    const calls = stubAccountFetch({
      'GET /v1/me': () => me,
      'GET /v1/watchlist': () => list,
    });
    visit('/watchlist');
    render(<App />);
    expect(await screen.findByRole('link', { name: 'Chimecho' })).toBeTruthy();
    await settle();

    // The tab sat in the background past the five-minute staleTime.
    await act(async () => {
      await client().invalidateQueries({ refetchType: 'none' });
    });
    // Meanwhile another tab signed A out and B in, and wrote the signal.
    me = jsonResponse(B_ME);
    list = jsonResponse(watchlistBody([]));
    otherTabSignals();
    await settle();
    // The user comes back to this tab.
    act(() => {
      document.dispatchEvent(new Event('visibilitychange', { bubbles: true }));
    });
    await settle();

    expect(screen.queryAllByRole('link', { name: 'Chimecho' })).toEqual([]);
    expect(count(calls, 'GET /v1/me')).toBe(2);
    expect(count(calls, 'GET /v1/watchlist')).toBe(2);
  });
});
