// Review tests for AccountScope (Q1-i6-T01): the identity edges the task's own tests leave open
// (a scope mounted while ['me'] is only stale or not yet known), the passive-read promise, the
// watchlist rows under the scope, the card page's R221 controls across an account change, and the
// encoded `next` of the verify-email prompt.
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { act, cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import { StrictMode, useState } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import App from '../App';
import type * as QueryModule from '../query';
import { navigate } from '../router';
import {
  clearXsrfCookie,
  historyFor,
  jsonResponse,
  portfolioBody,
  pricedCardBody,
  signedInMe,
  stubAccountFetch,
  unauthenticatedProblem,
  problemResponse,
  watchItemOf,
  watchlistBody,
} from '../test/fixtures';
import AccountScope from './AccountScope';
import { ME_KEY } from './session';

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
  vi.unstubAllGlobals();
  visit('/');
});

const A = signedInMe.data;
const B = { ...signedInMe.data, email: 'second@example.com' };
const CARD = 'GET /v1/cards/ex10-%3F';
const HISTORY = 'GET /v1/cards/ex10-%3F/prices?range=1M';

function client(): QueryClient {
  const first = created.clients[0];
  if (first === undefined) throw new Error('App created no QueryClient');
  return first;
}

async function settle(): Promise<void> {
  for (let round = 0; round < 6; round += 1) {
    await act(async () => {
      await new Promise((resolve) => setTimeout(resolve, 0));
    });
  }
}

function Draft() {
  const [text, setText] = useState('');
  return (
    <input
      aria-label="Draft"
      value={text}
      onChange={(event) => {
        setText(event.target.value);
      }}
    />
  );
}

function draft(): HTMLInputElement {
  return screen.getByRole<HTMLInputElement>('textbox', { name: 'Draft' });
}

function mount(queryClient: QueryClient, strict = false) {
  const tree = (
    <QueryClientProvider client={queryClient}>
      <AccountScope>
        <Draft />
      </AccountScope>
    </QueryClientProvider>
  );
  return render(strict ? <StrictMode>{tree}</StrictMode> : tree);
}

describe('a scope mounted before the cache names an account (plan: a same-account refresh never remounts)', () => {
  it('keeps what was typed when the account the cache held stale is confirmed as the same one', () => {
    const queryClient = new QueryClient();
    queryClient.setQueryData(ME_KEY, A);
    // Another tab's signal marked it stale before this page opened: the account is held, not known.
    act(() => {
      void queryClient.invalidateQueries({ queryKey: ME_KEY, refetchType: 'none' });
    });
    mount(queryClient);
    fireEvent.change(draft(), { target: { value: 'typed' } });
    // The refresh answers: the same account, now fresh.
    act(() => {
      queryClient.setQueryData(ME_KEY, { ...A });
    });
    expect(draft().value).toBe('typed');
  });

  it('keeps what was typed when the first answer of an empty cache names the account', () => {
    const queryClient = new QueryClient();
    mount(queryClient);
    fireEvent.change(draft(), { target: { value: 'typed before any answer' } });
    act(() => {
      queryClient.setQueryData(ME_KEY, A);
    });
    expect(draft().value).toBe('typed before any answer');
  });

  it('still remounts when the stale-held account is replaced by another one', () => {
    const queryClient = new QueryClient();
    queryClient.setQueryData(ME_KEY, A);
    act(() => {
      void queryClient.invalidateQueries({ queryKey: ME_KEY, refetchType: 'none' });
    });
    mount(queryClient);
    fireEvent.change(draft(), { target: { value: 'typed as A' } });
    act(() => {
      queryClient.setQueryData(ME_KEY, B);
    });
    expect(draft().value).toBe('');
  });
});

describe('AccountScope as a passive cache read', () => {
  it('leaves no observer on ["me"] and sends no request, inside StrictMode too', () => {
    const fetchSpy = vi.fn();
    vi.stubGlobal('fetch', fetchSpy);
    const queryClient = new QueryClient();
    queryClient.setQueryData(ME_KEY, A);
    const view = mount(queryClient, true);
    expect(queryClient.getQueryCache().find({ queryKey: ME_KEY })?.getObserversCount()).toBe(0);
    act(() => {
      queryClient.setQueryData(ME_KEY, B);
    });
    expect(queryClient.getQueryCache().find({ queryKey: ME_KEY })?.getObserversCount()).toBe(0);
    expect(fetchSpy).not.toHaveBeenCalled();
    expect(view.container.innerHTML).toBe('<input aria-label="Draft" value="">');
  });

  it('drops a typed draft on signing out, and A signing in again starts a fresh one', () => {
    const queryClient = new QueryClient();
    queryClient.setQueryData(ME_KEY, A);
    mount(queryClient);
    fireEvent.change(draft(), { target: { value: 'typed as A' } });
    act(() => {
      queryClient.setQueryData(ME_KEY, null);
    });
    expect(draft().value).toBe('');
    fireEvent.change(draft(), { target: { value: 'typed signed out' } });
    act(() => {
      queryClient.setQueryData(ME_KEY, A);
    });
    expect(draft().value).toBe('');
  });
});

describe('the watchlist rows under the scope', () => {
  it('keeps an unsaved threshold with its own card when a refetch reorders the rows', async () => {
    const first = watchItemOf({ alert_pct: 10 });
    const second = watchItemOf({
      variant_key: 'ex5-17:reverse:-:-',
      card_id: 'ex5-17',
      finish: 'reverse',
      edition: '-',
      card: { name: 'Chimecho', set_name: 'Hidden Legends', number: '17' },
      alert_pct: 10,
    });
    let list = [first, second];
    stubAccountFetch({
      'GET /v1/me': jsonResponse(signedInMe),
      'GET /v1/watchlist': () => jsonResponse(watchlistBody(list)),
    });
    visit('/watchlist');
    render(<App />);
    const rows = await screen.findByRole('list', { name: 'Watchlist' });
    expect(rows.children).toHaveLength(2);
    const chimecho = (): HTMLSelectElement =>
      screen.getByRole<HTMLSelectElement>('combobox', { name: /^Alert threshold for Chimecho/ });
    fireEvent.change(chimecho(), { target: { value: '20' } });
    list = [second, first];
    await act(async () => {
      await client().refetchQueries({ queryKey: ['account'] });
    });
    await settle();
    const after = screen.getByRole('list', { name: 'Watchlist' });
    expect(after.children).toHaveLength(2);
    expect(Array.from(after.children).every((child) => child.tagName === 'LI')).toBe(true);
    expect(
      within(after.children[0] as HTMLElement)
        .getByRole('combobox')
        .getAttribute('aria-label'),
    ).toMatch(/Chimecho/);
    expect(chimecho().value).toBe('20');
  });

  it('keeps an unsaved threshold when the page opens while ["me"] is stale and the refresh names the same account', async () => {
    let releaseMe: (() => void) | undefined;
    let meCalls = 0;
    vi.stubGlobal('fetch', (input: string | URL, init?: RequestInit): Promise<Response> => {
      const key = `${init?.method ?? 'GET'} ${String(input)}`;
      if (key === 'GET /v1/me') {
        meCalls += 1;
        // The first answer is immediate; the refresh after the stale mark waits for the test.
        if (meCalls === 1) return Promise.resolve(jsonResponse(signedInMe));
        return new Promise<Response>((resolve) => {
          releaseMe = () => {
            resolve(jsonResponse(signedInMe));
          };
        });
      }
      if (key === 'GET /v1/watchlist') {
        return Promise.resolve(jsonResponse(watchlistBody([watchItemOf({ alert_pct: 10 })])));
      }
      return Promise.reject(new TypeError(`no request expected: ${key}`));
    });
    visit('/account');
    render(<App />);
    await screen.findByText(`Signed in as ${signedInMe.data.email}`);
    // Another tab's signal: ['me'] is held but marked stale, nothing asked yet.
    act(() => {
      void client().invalidateQueries({ queryKey: ['me'], refetchType: 'none' });
    });
    act(() => {
      navigate('/watchlist');
    });
    await screen.findByRole('list', { name: 'Watchlist' });
    const threshold = (): HTMLSelectElement =>
      screen.getByRole<HTMLSelectElement>('combobox', { name: /^Alert threshold for / });
    fireEvent.change(threshold(), { target: { value: '20' } });
    expect(releaseMe).toBeDefined();
    await act(async () => {
      releaseMe?.();
      await Promise.resolve();
    });
    await settle();
    // The refresh named the same account: the unsaved choice stays.
    expect(threshold().value).toBe('20');
  });
});

describe('the card page controls across an account change (R221)', () => {
  it('closes an opened portfolio form for B and shows B the buttons only', async () => {
    let me = jsonResponse(signedInMe);
    const calls = stubAccountFetch({
      'GET /v1/me': () => me,
      [CARD]: jsonResponse(pricedCardBody),
      [HISTORY]: jsonResponse(historyFor(pricedCardBody)),
      'GET /v1/portfolio': jsonResponse(portfolioBody([])),
      'GET /v1/watchlist': jsonResponse(watchlistBody([])),
    });
    visit('/account');
    render(<App />);
    await screen.findByText(`Signed in as ${signedInMe.data.email}`);
    act(() => {
      navigate('/card/ex10-%3F');
    });
    await screen.findByRole('heading', { level: 1, name: 'Unown' });
    const prices = screen.getByRole('region', { name: 'Prices' });
    const open = within(prices).getByRole('button', {
      name: 'Add holo, unlimited to your portfolio',
    });
    fireEvent.click(open);
    await settle();
    expect(open.getAttribute('aria-expanded')).toBe('true');
    expect(calls.filter((call) => call.key === 'GET /v1/portfolio')).toHaveLength(1);

    me = jsonResponse({ data: B });
    await act(async () => {
      await client().refetchQueries({ queryKey: ['me'] });
    });
    await settle();
    const again = within(screen.getByRole('region', { name: 'Prices' })).getByRole('button', {
      name: 'Add holo, unlimited to your portfolio',
    });
    expect(again.getAttribute('aria-expanded')).toBe('false');
    // B sees the buttons only: no form, no portfolio loading line, nothing of A's open state.
    expect(screen.queryByText('Loading your portfolio…')).toBeNull();
    expect(screen.queryByRole('spinbutton')).toBeNull();
  });

  it('drops the watched line when the visitor signs out under the page', async () => {
    const calls = stubAccountFetch({
      'GET /v1/me': jsonResponse(signedInMe),
      [CARD]: jsonResponse(pricedCardBody),
      [HISTORY]: jsonResponse(historyFor(pricedCardBody)),
      'PUT /v1/watchlist/ex10-%3F%3Aholo%3Aunlimited%3A-': jsonResponse({
        data: { variant_key: 'ex10-?:holo:unlimited:-' },
      }),
      'GET /v1/watchlist': problemResponse(unauthenticatedProblem),
      'GET /v1/portfolio': jsonResponse(portfolioBody([])),
    });
    visit('/account');
    render(<App />);
    await screen.findByText(`Signed in as ${signedInMe.data.email}`);
    act(() => {
      navigate('/card/ex10-%3F');
    });
    await screen.findByRole('heading', { level: 1, name: 'Unown' });
    fireEvent.click(screen.getByRole('button', { name: 'Add holo, unlimited to your watchlist' }));
    expect(await screen.findByText('On your watchlist.')).toBeTruthy();
    act(() => {
      client().setQueryData(ME_KEY, null);
    });
    await settle();
    expect(screen.queryByText('On your watchlist.')).toBeNull();
    expect(
      calls.filter((call) => call.key === 'PUT /v1/watchlist/ex10-%3F%3Aholo%3Aunlimited%3A-'),
    ).toHaveLength(1);
  });
});

describe('the verify-email sign-in prompt encodes its next once', () => {
  it('carries a link with &, #, %, a space and non-ASCII text as one encoded next, never double-encoded', async () => {
    const link = '/email/verify/7/0a1b2c?expires=1700000000&signature=fake0sig&note=a b%2Fc#fragé☃';
    const here = `/account/verify-email?link=${encodeURIComponent(link)}`;
    stubAccountFetch({ 'GET /v1/me': problemResponse(unauthenticatedProblem) });
    visit(here);
    render(<App />);
    await screen.findByText('Sign in to verify your email address.');
    const href = screen.getByRole('main').querySelector('a')?.getAttribute('href');
    expect(href).toBe(`/sign-in?next=${encodeURIComponent(here)}`);
    // One round trip of the query string gives the page back, byte for byte.
    const next = new URLSearchParams((href ?? '').split('?')[1]).get('next');
    expect(next).toBe(here);
    expect(new URLSearchParams((next ?? '').split('?')[1]).get('link')).toBe(link);
  });
});
