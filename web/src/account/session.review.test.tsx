// Session transitions, review follow-ups (Q0-i1-T05): the one-time verification GET is never sent
// again by an identity change while its page shows it; an ['me'] request already in flight when
// another tab signals is never taken as the new account; a tab that never read ['me'] still drops
// what it cached before the first account it learns of; and a forget reaches every mounted reader,
// so later invalidations still ask and an open form never keeps the last account's values.
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
  portfolioBody,
  pricedCardBody,
  rawResponse,
  signedInMe,
  stubAccountFetch,
  watchlistBody,
  type AccountCall,
} from '../test/fixtures';
import { SESSION_SIGNAL_KEY, fetchMe } from './session';

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
const B_EMAIL = 'second@example.com';
const B_ME = { data: { ...signedInMe.data, email: B_EMAIL } };
const SIGNATURE = 'fake0signature0000beef';
const LINK = `/email/verify/7/0a1b2c?expires=1700000000&signature=${SIGNATURE}`;
const VERIFY_PAGE = `/account/verify-email?link=${encodeURIComponent(LINK)}`;
const CARD = '/card/ex10-%3F';
const HOLO = 'ex10-?:holo:unlimited:-';

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

function portfolioHolding(quantity: number): Response {
  return jsonResponse(
    portfolioBody([holdingOf({ variant_key: HOLO, card_id: 'ex10-?', quantity })]),
  );
}

const CARD_ROUTES = {
  'GET /v1/cards/ex10-%3F': jsonResponse(pricedCardBody),
  'GET /v1/cards/ex10-%3F/prices?range=1M': jsonResponse(historyFor(pricedCardBody)),
};

async function openHoldingForm(): Promise<void> {
  const prices = screen.getByRole('region', { name: 'Prices' });
  fireEvent.click(
    within(prices).getByRole('button', { name: 'Add holo, unlimited to your portfolio' }),
  );
  await screen.findByLabelText('Quantity owned');
}

function quantityShown(): string {
  return screen.getByLabelText<HTMLInputElement>('Quantity owned').value;
}

describe('the one-time verification GET survives an identity change while its page shows it', () => {
  it('A verified the link, then ["me"] says B: the link was asked once and the page still says verified', async () => {
    let me: Response = jsonResponse(signedInMe);
    const calls = stubAccountFetch({
      'GET /v1/me': () => me,
      [`GET ${LINK}`]: [rawResponse(204, null), rawResponse(403, null)],
    });
    visit(VERIFY_PAGE);
    render(<App />);
    expect(await screen.findByText('Your email address is verified.')).toBeTruthy();
    await settle();

    me = jsonResponse(B_ME);
    await pollMe();
    await settle();
    // A re-render after the switch (any later ['me'] answer) still asks nothing.
    await pollMe();
    await settle();
    expect(count(calls, 'GET /v1/me')).toBeGreaterThanOrEqual(3);
    expect(count(calls, `GET ${LINK}`)).toBe(1);
    expect(screen.getByText('Your email address is verified.')).toBeTruthy();
  });

  it('A verified the link, then another tab signals and ["me"] says B: the link was asked once', async () => {
    let me: Response = jsonResponse(signedInMe);
    const calls = stubAccountFetch({
      'GET /v1/me': () => me,
      [`GET ${LINK}`]: [rawResponse(204, null), rawResponse(403, null)],
    });
    visit(VERIFY_PAGE);
    render(<App />);
    expect(await screen.findByText('Your email address is verified.')).toBeTruthy();
    await settle();

    me = jsonResponse(B_ME);
    otherTabSignals();
    await settle();
    await pollMe();
    await settle();
    expect(count(calls, 'GET /v1/me')).toBeGreaterThanOrEqual(3);
    expect(count(calls, `GET ${LINK}`)).toBe(1);
    expect(screen.getByText('Your email address is verified.')).toBeTruthy();
  });
});

describe('an ["me"] request in flight when another tab signals is never the new account', () => {
  it('first load: the pre-switch answer (A) is dropped and the page ends on B', async () => {
    // Every GET /v1/me after the first is sent with B's cookie.
    const calls = stubAccountFetch({ 'GET /v1/me': jsonResponse(B_ME) });
    const inner = globalThis.fetch;
    let releaseFirst: ((response: Response) => void) | undefined;
    let first = true;
    vi.stubGlobal('fetch', (input: string | URL, init?: RequestInit): Promise<Response> => {
      if (String(input) === '/v1/me' && first) {
        first = false;
        calls.push({ url: '/v1/me', init, key: 'GET /v1/me' });
        return new Promise<Response>((resolve) => {
          releaseFirst = resolve;
        });
      }
      return inner(input, init);
    });
    visit('/account');
    render(<App />);
    await waitFor(() => {
      expect(releaseFirst).toBeDefined();
    });

    // Another tab signs A out and B in while this tab's first GET /v1/me (sent with A's cookie)
    // has not answered yet; then that answer arrives, naming A.
    otherTabSignals();
    await act(async () => {
      releaseFirst?.(jsonResponse(signedInMe));
      await Promise.resolve();
    });
    await settle();

    expect(await screen.findByText(`Signed in as ${B_EMAIL}`)).toBeTruthy();
    expect(screen.queryByText(`Signed in as ${A_EMAIL}`)).toBeNull();
    expect(count(calls, 'GET /v1/me')).toBe(2);
  });
});

describe('a tab that never read ["me"] drops what it cached before the first account it learns of', () => {
  it('A portfolio read on the card page does not prefill B quantity after /account says B', async () => {
    let me: Response = jsonResponse(signedInMe);
    let portfolio: Response = portfolioHolding(7);
    const calls = stubAccountFetch({
      'GET /v1/me': () => me,
      'GET /v1/portfolio': () => portfolio,
      ...CARD_ROUTES,
    });
    visit(CARD);
    render(<App />);
    await screen.findByRole('heading', { level: 1, name: 'Unown' });
    await openHoldingForm();
    expect(quantityShown()).toBe('7');
    expect(count(calls, 'GET /v1/me')).toBe(0);

    // Another tab signs A out and B in; no signal reaches this tab (storage blocked).
    me = jsonResponse(B_ME);
    portfolio = portfolioHolding(2);
    act(() => {
      navigate('/account');
    });
    expect(await screen.findByText(`Signed in as ${B_EMAIL}`)).toBeTruthy();
    act(() => {
      navigate(CARD);
    });
    await screen.findByRole('heading', { level: 1, name: 'Unown' });
    await openHoldingForm();
    await settle();
    expect(quantityShown()).toBe('2');
    expect(count(calls, 'GET /v1/portfolio')).toBe(2);
  });
});

describe('a forget reaches every mounted reader', () => {
  it('same account after a signal: the watchlist stays in the cache and a later invalidation asks again', async () => {
    const calls = stubAccountFetch({
      'GET /v1/me': jsonResponse(signedInMe),
      'GET /v1/watchlist': jsonResponse(watchlistBody()),
    });
    visit('/watchlist');
    render(<App />);
    expect(await screen.findByRole('link', { name: 'Chimecho' })).toBeTruthy();
    await settle();

    // Another tab signed A out and A in again.
    otherTabSignals();
    await settle();
    expect(screen.getByRole('link', { name: 'Chimecho' })).toBeTruthy();
    expect(
      client()
        .getQueryCache()
        .find({ queryKey: ['account', 'watchlist'] }),
    ).toBeDefined();
    const before = count(calls, 'GET /v1/watchlist');

    // What every watchlist write does after it succeeds (WatchlistPage, PortfolioPage, Webhooks).
    await act(async () => {
      await client().invalidateQueries({ queryKey: ['account', 'watchlist'] });
    });
    await settle();
    expect(count(calls, 'GET /v1/watchlist')).toBe(before + 1);
  });

  it('an open Add to portfolio form (no ["me"] reader) drops A quantity once another tab switches to B', async () => {
    let portfolio: Response = portfolioHolding(7);
    const calls = stubAccountFetch({
      'GET /v1/me': jsonResponse(B_ME),
      'GET /v1/portfolio': () => portfolio,
      ...CARD_ROUTES,
    });
    visit(CARD);
    render(<App />);
    await screen.findByRole('heading', { level: 1, name: 'Unown' });
    await openHoldingForm();
    expect(quantityShown()).toBe('7');

    portfolio = portfolioHolding(2);
    otherTabSignals();
    await settle();
    await waitFor(() => {
      expect(quantityShown()).toBe('2');
    });
    expect(screen.queryByDisplayValue('7')).toBeNull();
    expect(count(calls, 'GET /v1/portfolio')).toBe(2);
  });
});

describe('fetchMe on a 200 (Q1-i5-T04)', () => {
  const UNEXPECTED = {
    ok: false,
    status: 200,
    title: 'The PokéPrices API sent an unexpected answer (HTTP 200)',
    requestId: null,
  };

  it('returns the decoded account as the answer sent it', async () => {
    stubAccountFetch({ 'GET /v1/me': jsonResponse(B_ME) });
    expect(await fetchMe()).toEqual(B_ME.data);
  });

  it('throws the decoder failure for a 200 without a usable email, never a partial account', async () => {
    const bodies: unknown[] = [
      { data: { ...signedInMe.data, email: 7 } },
      { data: { email_verified: true, two_factor: false, read_only: false } },
      { data: null },
      { me: signedInMe.data },
      null,
    ];
    for (const body of bodies) {
      stubAccountFetch({ 'GET /v1/me': jsonResponse(body) });
      await expect(fetchMe()).rejects.toMatchObject(UNEXPECTED);
    }
  });
});
