// AccountScope: what one account left in component state (an outcome line, an unsaved draft) is
// dropped when the ['me'] cache names another account, and kept when the same account is only
// asked again. It reads the cache passively: a public page under it still never asks GET /v1/me.
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { act, cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import { useState } from 'react';
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
  visit('/');
});

const B_ME = { data: { ...signedInMe.data, email: 'second@example.com' } };
const CARD = 'GET /v1/cards/ex10-%3F';
const HISTORY = 'GET /v1/cards/ex10-%3F/prices?range=1M';
const WATCH_PUT = 'PUT /v1/watchlist/ex10-%3F%3Aholo%3Aunlimited%3A-';
const WATCHED = 'On your watchlist.';
const CHARIZARD = 'Charizard, holo, unlimited';

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

/** A poll of ['me'] wherever it is cached (shown or not), as the session tests do. */
async function pollMe(): Promise<void> {
  await act(async () => {
    await client().refetchQueries({ queryKey: ['me'] });
  });
  await settle();
}

/** What another tab's session signal does to ['me'] here: marked stale, nothing asked. */
function markMeStale(): void {
  act(() => {
    void client().invalidateQueries({ queryKey: ['me'], refetchType: 'none' });
  });
}

function prices(): HTMLElement {
  return screen.getByRole('region', { name: 'Prices' });
}

function holoRow(): HTMLElement {
  const row = within(prices()).getByText('holo, unlimited').closest('li');
  if (row === null) throw new Error('no holo row');
  return row;
}

describe('the card page outcome line belongs to one account (c9)', () => {
  async function watchedAsA() {
    let me: Response = jsonResponse(signedInMe);
    // The card page of a known session reads both lists: A's watchlist holds the variant once
    // the PUT is answered; B's is empty; neither holds a portfolio.
    const holo = watchItemOf({ variant_key: 'ex10-?:holo:unlimited:-', card_id: 'ex10-?' });
    let watched: readonly (typeof holo)[] = [];
    const calls = stubAccountFetch({
      'GET /v1/me': () => me,
      [CARD]: jsonResponse(pricedCardBody),
      [HISTORY]: jsonResponse(historyFor(pricedCardBody)),
      [WATCH_PUT]: () => {
        watched = [holo];
        return jsonResponse({ data: { variant_key: 'ex10-?:holo:unlimited:-' } });
      },
      'GET /v1/watchlist': () => jsonResponse(watchlistBody(watched)),
      'GET /v1/portfolio': jsonResponse(portfolioBody([])),
    });
    // Signed in as A: the account page asked ['me'], then the card page opens in the same tab.
    visit('/account');
    render(<App />);
    await screen.findByText(`Signed in as ${signedInMe.data.email}`);
    act(() => {
      navigate('/card/ex10-%3F');
    });
    await screen.findByRole('heading', { level: 1, name: 'Unown' });
    fireEvent.click(
      within(prices()).getByRole('button', { name: 'Add holo, unlimited to your watchlist' }),
    );
    expect(await within(holoRow()).findByText(WATCHED)).toBeTruthy();
    return {
      calls,
      answer: (next: Response, list: readonly (typeof holo)[] = watched) => {
        me = next;
        watched = list;
      },
    };
  }

  it("drops the line when ['me'] names account B", async () => {
    const { calls, answer } = await watchedAsA();
    const before = calls.filter((call) => call.key === 'GET /v1/me').length;
    answer(jsonResponse(B_ME), []);
    await pollMe();
    expect(within(holoRow()).queryByText(WATCHED)).toBeNull();
    // The buttons are still there, fresh for B.
    expect(
      within(holoRow()).getByRole('button', { name: 'Add holo, unlimited to your watchlist' }),
    ).toBeTruthy();
    // The card page itself asked nothing of ['me']: only the poll above did.
    expect(calls.filter((call) => call.key === 'GET /v1/me')).toHaveLength(before + 1);
  });

  it('keeps the line when the same account is marked stale and asked again', async () => {
    const { answer } = await watchedAsA();
    markMeStale();
    expect(within(holoRow()).getByText(WATCHED)).toBeTruthy();
    answer(jsonResponse(signedInMe));
    await pollMe();
    expect(within(holoRow()).getByText(WATCHED)).toBeTruthy();
  });
});

describe('a watchlist row alert draft belongs to one account (c9)', () => {
  async function draftAsA() {
    let me: Response = jsonResponse(signedInMe);
    stubAccountFetch({
      'GET /v1/me': () => me,
      'GET /v1/watchlist': jsonResponse(watchlistBody([watchItemOf({ alert_pct: 10 })])),
    });
    visit('/watchlist');
    render(<App />);
    await screen.findByRole('list', { name: 'Watchlist' });
    fireEvent.change(threshold(), { target: { value: '20' } });
    expect(threshold().value).toBe('20');
    return (next: Response) => {
      me = next;
    };
  }

  function threshold(): HTMLSelectElement {
    return screen.getByRole<HTMLSelectElement>('combobox', {
      name: `Alert threshold for ${CHARIZARD}`,
    });
  }

  it("drops the unsaved threshold when ['me'] names account B", async () => {
    const answer = await draftAsA();
    answer(jsonResponse(B_ME));
    await pollMe();
    await screen.findByRole('list', { name: 'Watchlist' });
    expect(threshold().value).toBe('10');
  });

  it('keeps the unsaved threshold when the same account is marked stale and asked again', async () => {
    const answer = await draftAsA();
    markMeStale();
    answer(jsonResponse(signedInMe));
    await pollMe();
    expect(threshold().value).toBe('20');
  });
});

/** A child with its own state, to see whether the scope remounted it. */
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

describe("AccountScope over the ['me'] cache", () => {
  function mount(queryClient: QueryClient) {
    return render(
      <QueryClientProvider client={queryClient}>
        <AccountScope>
          <Draft />
        </AccountScope>
      </QueryClientProvider>,
    );
  }

  it("adds no element and no ['me'] query of its own", () => {
    const queryClient = new QueryClient();
    const view = mount(queryClient);
    expect(view.container.innerHTML).toBe('<input aria-label="Draft" value="">');
    expect(queryClient.getQueryCache().find({ queryKey: ME_KEY })).toBeUndefined();
  });

  it('remounts on another account or on signing out, never on a stale mark of the same one', () => {
    const queryClient = new QueryClient();
    act(() => {
      queryClient.setQueryData(ME_KEY, signedInMe.data);
    });
    mount(queryClient);
    fireEvent.change(draft(), { target: { value: 'typed' } });

    act(() => {
      void queryClient.invalidateQueries({ queryKey: ME_KEY, refetchType: 'none' });
    });
    expect(draft().value).toBe('typed');
    act(() => {
      queryClient.setQueryData(ME_KEY, { ...signedInMe.data });
    });
    expect(draft().value).toBe('typed');

    act(() => {
      queryClient.setQueryData(ME_KEY, B_ME.data);
    });
    expect(draft().value).toBe('');
    fireEvent.change(draft(), { target: { value: 'typed by B' } });

    act(() => {
      queryClient.setQueryData(ME_KEY, null);
    });
    expect(draft().value).toBe('');
    fireEvent.change(draft(), { target: { value: 'typed signed out' } });
    act(() => {
      queryClient.setQueryData(ME_KEY, null);
    });
    expect(draft().value).toBe('typed signed out');
  });
});
