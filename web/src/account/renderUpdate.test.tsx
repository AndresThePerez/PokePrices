// No component updates another while it renders (T09 fix round 1, point C): React's "Cannot update
// a component (…) while rendering a different component" is logged when a passive ['me'] reader
// (the header's estimate, the demo strip, the card's controls) is told of a cache change from inside
// another component's render (a useQuery building its query). These are the flows the reviewers
// saw it in: signing out from /account to / (with the cookie; without it /account is signed out
// at once and / follows), the demo strip's
// sign-out, and the card page when ['me'] names another account.
import type { QueryClient } from '@tanstack/react-query';
import { act, cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi, type MockInstance } from 'vitest';
import App from '../App';
import type * as QueryModule from '../query';
import { navigate } from '../router';
import {
  clearXsrfCookie,
  demoMe,
  historyFor,
  jsonResponse,
  portfolioBody,
  pricedCardBody,
  problemResponse,
  rawResponse,
  setXsrfCookie,
  holdSessionCookie,
  signedInMe,
  stubAccountFetch,
  unauthenticatedProblem,
  watchItemOf,
  watchlistBody,
} from '../test/fixtures';

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

const RENDER_UPDATE = /Cannot update a component .* while rendering a different component/;

let errors: MockInstance<typeof console.error>;

beforeEach(() => {
  errors = vi.spyOn(console, 'error');
});

afterEach(() => {
  cleanup();
  errors.mockRestore();
  clearXsrfCookie();
  created.clients.length = 0;
  window.localStorage.clear();
  window.history.replaceState(null, '', '/');
});

async function settle(): Promise<void> {
  for (let i = 0; i < 6; i += 1) {
    await act(async () => {
      await new Promise((resolve) => setTimeout(resolve, 0));
    });
  }
}

/** Every console.error line that is React's render-time update warning. */
function renderUpdates(): string[] {
  return errors.mock.calls
    .map((args) => args.map((arg) => String(arg)).join(' '))
    .filter((line) => RENDER_UPDATE.test(line));
}

const signedInWithEstimate = {
  data: {
    ...signedInMe.data,
    portfolio: {
      ...signedInMe.data.portfolio,
      count: 1,
      priced: 1,
      total_value: '1047.93',
      change: '16.93',
      change_pct: '1.64',
      first_day: '2026-10-12',
      last_day: '2026-10-12',
    },
  },
};

describe('signing out from /account to /', () => {
  it('logs no render-time update (with the cookie)', async () => {
    setXsrfCookie();
    stubAccountFetch({
      'GET /v1/me': [jsonResponse(signedInWithEstimate), problemResponse(unauthenticatedProblem)],
      'POST /logout': rawResponse(204, null),
    });
    window.history.replaceState(null, '', '/account');
    render(<App />);
    fireEvent.click(await screen.findByRole('button', { name: 'Sign out' }));
    await waitFor(() => {
      expect(window.location.pathname).toBe('/');
    });
    await settle();
    expect(screen.queryByRole('link', { name: /^Portfolio estimate/ })).toBeNull();
    expect(renderUpdates()).toEqual([]);
  });

  // Without the cookie nobody is signed in: /account is signed out at once and asks nothing, so
  // there is no sign-out; going on to / logs no render-time update either.
  it('logs no render-time update (without the cookie: signed out at once, nothing asked)', async () => {
    const calls = stubAccountFetch({
      'GET /v1/me': problemResponse(unauthenticatedProblem),
      'POST /logout': rawResponse(204, null),
    });
    window.history.replaceState(null, '', '/account');
    render(<App />);
    expect(screen.getByText('You are not signed in.')).toBeTruthy();
    expect(screen.queryByRole('button', { name: 'Sign out' })).toBeNull();
    act(() => {
      navigate('/');
    });
    await settle();
    expect(window.location.pathname).toBe('/');
    expect(screen.queryByRole('link', { name: /^Portfolio estimate/ })).toBeNull();
    const keys = calls.map((call) => call.key);
    expect(keys.filter((key) => key === 'GET /v1/me' || key.includes('csrf'))).toEqual([]);
    expect(renderUpdates()).toEqual([]);
  });
});

describe("the demo strip's sign-out", () => {
  it('logs no render-time update', async () => {
    setXsrfCookie();
    stubAccountFetch({
      'GET /v1/me': [jsonResponse(demoMe), problemResponse(unauthenticatedProblem)],
      'GET /v1/watchlist': jsonResponse(watchlistBody()),
      'POST /logout': rawResponse(204, null),
    });
    window.history.replaceState(null, '', '/watchlist');
    render(<App />);
    await screen.findByRole('list', { name: 'Watchlist' });
    fireEvent.click(within(screen.getByRole('banner')).getByRole('button', { name: 'Sign out' }));
    await waitFor(() => {
      expect(window.location.pathname).toBe('/');
    });
    await settle();
    expect(renderUpdates()).toEqual([]);
  });
});

describe("the card page when ['me'] names another account", () => {
  it('logs no render-time update', async () => {
    holdSessionCookie();
    let me: Response = jsonResponse(signedInMe);
    const holo = watchItemOf({ variant_key: 'ex10-?:holo:unlimited:-', card_id: 'ex10-?' });
    let watched: readonly (typeof holo)[] = [holo];
    stubAccountFetch({
      'GET /v1/me': () => me,
      'GET /v1/cards/ex10-%3F': jsonResponse(pricedCardBody),
      'GET /v1/cards/ex10-%3F/prices?range=1M': jsonResponse(historyFor(pricedCardBody)),
      'GET /v1/watchlist': () => jsonResponse(watchlistBody(watched)),
      'GET /v1/portfolio': jsonResponse(portfolioBody([])),
    });
    window.history.replaceState(null, '', '/account');
    render(<App />);
    await screen.findByText(`Signed in as ${signedInMe.data.email}`);
    // The card page below is read with no cookie again: it has no session observer of its own,
    // so the poll of ['me'] in this test refetches the cached answer.
    clearXsrfCookie();
    act(() => {
      navigate('/card/ex10-%3F');
    });
    await screen.findByText('On your watchlist.');
    me = jsonResponse({ data: { ...signedInMe.data, email: 'second@example.com' } });
    watched = [];
    const client = created.clients[0];
    if (client === undefined) throw new Error('no client');
    await act(async () => {
      await client.refetchQueries({ queryKey: ['me'] });
    });
    await settle();
    expect(screen.queryByText('On your watchlist.')).toBeNull();
    expect(renderUpdates()).toEqual([]);
  });
});
