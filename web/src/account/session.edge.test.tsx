// Adversarial edge cases for the session-transition core (Q0-i1-T01): a poll of the same account
// keeps what the user typed, a persistent 401 never storms GET /v1/me, a late answer of the last
// account never repopulates a cleared key, and the card page never shows a signed-out visitor the
// last account's holdings.
import type { QueryClient } from '@tanstack/react-query';
import { act, cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import App from '../App';
import type * as QueryModule from '../query';
import { navigate } from '../router';
import {
  FAKE_SIGNING_SECRET,
  clearXsrfCookie,
  demoMe,
  demoSamplesBody,
  domHolds,
  historyFor,
  holdingOf,
  jsonResponse,
  notificationsBody,
  portfolioBody,
  pricedCardBody,
  problemResponse,
  signedInMe,
  stubAccountFetch,
  unauthenticatedProblem,
  watchlistBody,
  webhookCreatedBody,
  webhookOf,
  webhooksBody,
  type AccountCall,
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

function visit(path: string): void {
  window.history.replaceState(null, '', path);
}

afterEach(() => {
  cleanup();
  clearXsrfCookie();
  created.clients.length = 0;
  visit('/');
});

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

function unauthenticated(instance: string): Response {
  return problemResponse({ ...unauthenticatedProblem, instance });
}

describe('the same account polled again keeps its screen (spec: only an identity change clears)', () => {
  it('keeps an unticked-then-ticked digest draft and a shown signing secret across a ["me"] poll', async () => {
    const calls = stubAccountFetch({
      // A new response object each poll, a new data object, the same account.
      'GET /v1/me': () => jsonResponse(structuredClone(signedInMe)),
      'GET /v1/webhooks': jsonResponse(webhooksBody([])),
      'GET /v1/me/notifications': jsonResponse(notificationsBody()),
      'POST /v1/webhooks': jsonResponse(webhookCreatedBody(webhookOf(), FAKE_SIGNING_SECRET), {
        status: 201,
      }),
    });
    visit('/alerts');
    render(<App />);
    fireEvent.change(await screen.findByLabelText('URL'), {
      target: { value: 'https://hooks.example.com/a' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Add webhook' }));
    expect(await screen.findByRole('heading', { level: 3, name: 'Signing secret' })).toBeTruthy();
    const digest = screen.getByRole<HTMLInputElement>('checkbox', {
      name: 'Send me a daily digest',
    });
    fireEvent.click(digest);
    expect(digest.checked).toBe(true);
    const settingsReads = count(calls, 'GET /v1/me/notifications');

    await pollMe();
    await pollMe();
    await settle();

    expect(count(calls, 'GET /v1/me')).toBeGreaterThanOrEqual(3);
    expect(domHolds(FAKE_SIGNING_SECRET)).toBe(true);
    expect(
      screen.getByRole<HTMLInputElement>('checkbox', { name: 'Send me a daily digest' }).checked,
    ).toBe(true);
    expect(count(calls, 'GET /v1/me/notifications')).toBe(settingsReads);
  });
});

describe('a persistent 401 on per-user queries never storms GET /v1/me (C5)', () => {
  it('asks again a bounded number of times when webhooks and settings both answer 401 and ["me"] still says signed in', async () => {
    const calls = stubAccountFetch({
      'GET /v1/me': () => jsonResponse(signedInMe),
      'GET /v1/webhooks': () => unauthenticated('/v1/webhooks'),
      'GET /v1/me/notifications': () => unauthenticated('/v1/me/notifications'),
    });
    visit('/alerts');
    render(<App />);
    await waitFor(() => {
      expect(count(calls, 'GET /v1/webhooks')).toBeGreaterThanOrEqual(1);
    });
    await settle();
    const meAfterSettle = count(calls, 'GET /v1/me');
    const hooksAfterSettle = count(calls, 'GET /v1/webhooks');
    await new Promise((resolve) => setTimeout(resolve, 200));
    await settle();
    // Two 401s, one initial read: a few reads, and none of it keeps growing.
    expect(meAfterSettle).toBeLessThanOrEqual(4);
    expect(count(calls, 'GET /v1/me')).toBe(meAfterSettle);
    expect(count(calls, 'GET /v1/webhooks')).toBe(hooksAfterSettle);
    expect(hooksAfterSettle).toBeLessThanOrEqual(2);
  });
});

describe('a late answer of the last account never repopulates a cleared key (C1, C2)', () => {
  it('A refetch of the watchlist still in flight when ["me"] says B never shows A rows afterwards', async () => {
    let me: Response = jsonResponse(signedInMe);
    const rowsOfA = watchlistBody();
    let list: Response = jsonResponse(rowsOfA);
    const calls = stubAccountFetch({
      'GET /v1/me': () => me,
      'GET /v1/watchlist': () => list,
    });
    const inner = globalThis.fetch;
    let hold = false;
    let release: ((response: Response) => void) | undefined;
    vi.stubGlobal('fetch', (input: string | URL, init?: RequestInit): Promise<Response> => {
      if (hold && init?.method === 'GET' && String(input) === '/v1/watchlist') {
        hold = false;
        return new Promise<Response>((resolve) => {
          release = resolve;
        });
      }
      return inner(input, init);
    });
    visit('/watchlist');
    render(<App />);
    expect(await screen.findByRole('link', { name: 'Chimecho' })).toBeTruthy();

    // A's next poll of the list hangs on the wire.
    hold = true;
    act(() => {
      void client().refetchQueries({ queryKey: ['account', 'watchlist'] });
    });
    await waitFor(() => {
      expect(release).toBeDefined();
    });
    // Another tab switched to B; B's own list is empty.
    me = jsonResponse({ data: { ...signedInMe.data, email: 'second@example.com' } });
    list = jsonResponse(watchlistBody([]));
    await pollMe();
    await settle();
    expect(screen.queryAllByRole('link', { name: 'Chimecho' })).toEqual([]);
    // A's answer arrives late.
    await act(async () => {
      release?.(jsonResponse(rowsOfA));
      await Promise.resolve();
    });
    await settle();
    expect(screen.queryAllByRole('link', { name: 'Chimecho' })).toEqual([]);
    expect(document.body.textContent).not.toContain('Chimecho');
    expect(count(calls, 'GET /v1/me')).toBe(2);
  });

  it('A saves settings, signs out and the demo opens /alerts: the late PUT adds no request and no A setting', async () => {
    const A_SETTINGS = notificationsBody({
      channel: 'discord',
      digest: false,
      discord_available: true,
    });
    const A_SAVED = notificationsBody({
      channel: 'discord',
      digest: true,
      discord_available: true,
    });
    let me: Response = jsonResponse(signedInMe);
    const calls = stubAccountFetch({
      'GET /v1/me': () => me,
      'GET /v1/webhooks': jsonResponse(webhooksBody([])),
      // A's settings, then (asked again with the demo's cookie when the switch is seen) the demo's.
      'GET /v1/me/notifications': [
        jsonResponse(A_SETTINGS),
        jsonResponse(notificationsBody({ channel: 'email', digest: false })),
      ],
      'POST /logout': new Response(null, { status: 204 }),
      'POST /demo': jsonResponse({}),
      'GET /v1/demo/samples': jsonResponse(demoSamplesBody()),
    });
    const inner = globalThis.fetch;
    let releasePut: ((response: Response) => void) | undefined;
    vi.stubGlobal('fetch', (input: string | URL, init?: RequestInit): Promise<Response> => {
      if (init?.method === 'PUT' && String(input) === '/v1/me/notifications') {
        return new Promise<Response>((resolve) => {
          releasePut = resolve;
        });
      }
      return inner(input, init);
    });
    visit('/alerts');
    render(<App />);
    await screen.findByText('Email (collector@example.com)');
    fireEvent.click(screen.getByRole('checkbox', { name: 'Send me a daily digest' }));
    fireEvent.click(screen.getByRole('button', { name: 'Save' }));
    await waitFor(() => {
      expect(releasePut).toBeDefined();
    });
    // The demo takes over through another tab (the poll sees a different identity).
    me = jsonResponse(demoMe);
    await pollMe();
    await settle();
    const settingsReads = count(calls, 'GET /v1/me/notifications');
    await act(async () => {
      releasePut?.(jsonResponse(A_SAVED));
      await Promise.resolve();
    });
    await settle();
    expect(count(calls, 'GET /v1/me/notifications')).toBe(settingsReads);
    expect(screen.queryByRole('button', { name: 'Save' })).toBeNull();
    expect(screen.queryByText('Email (collector@example.com)')).toBeNull();
    // Neither A's settings nor A's late PUT answer is cached: at most the demo's own read, asked
    // with the demo's cookie when the switch was seen.
    const cached = client().getQueryData(['account', 'notifications']);
    expect(cached).not.toEqual(A_SETTINGS);
    expect(cached).not.toEqual(A_SAVED);
    expect([undefined, notificationsBody({ channel: 'email', digest: false })]).toContainEqual(
      cached,
    );
  });
});

describe('the card page never shows a signed-out visitor the last account holdings', () => {
  it('after the poll finds A signed out, Add to portfolio does not prefill A quantity', async () => {
    let me: Response = jsonResponse(signedInMe);
    const variant = 'ex10-?:holo:unlimited:-';
    const held = portfolioBody([
      holdingOf({ variant_key: variant, card_id: 'ex10-?', quantity: 7 }),
    ]);
    let portfolio: Response = jsonResponse(held);
    stubAccountFetch({
      'GET /v1/me': () => me,
      'GET /v1/portfolio': () => portfolio,
      'GET /v1/cards/ex10-%3F': jsonResponse(pricedCardBody),
      'GET /v1/cards/ex10-%3F/prices?range=1M': jsonResponse(historyFor(pricedCardBody)),
    });
    visit('/portfolio');
    render(<App />);
    await waitFor(() => {
      expect(client().getQueryData(['account', 'portfolio'])).toBeDefined();
    });
    // The session expires; the poll learns it, and nobody has signed in since.
    me = problemResponse(unauthenticatedProblem);
    portfolio = unauthenticated('/v1/portfolio');
    await pollMe();
    await settle();
    act(() => {
      navigate('/card/ex10-%3F');
    });
    await screen.findByRole('heading', { level: 1, name: 'Unown' });
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
