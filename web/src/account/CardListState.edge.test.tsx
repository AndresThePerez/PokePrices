// Adversarial tests for the card page's list state: the read-only demo opening a card directly sees
// the lines and no control that writes in every state (its lists failing, slow or odd), a card with
// many variants still reads each list once, three variants in three states, a quantity of 9999, a
// failing, slow or wrongly shaped list leaves the page its prices without a page-wide error, adding
// from the card page and removing from it.
import { act, cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import App from '../App';
import {
  FORBIDDEN_WORDS,
  cardWithPrices,
  clearXsrfCookie,
  demoMe,
  emptyPortfolioBody,
  historyFor,
  holdingOf,
  jsonResponse,
  portfolioBody,
  problemResponse,
  rawResponse,
  risingPrice,
  serverErrorProblem,
  setXsrfCookie,
  signedInMe,
  stubAccountFetch,
  watchItemOf,
  watchlistBody,
  type AccountAnswer,
} from '../test/fixtures';

vi.mock('lightweight-charts', () => import('../test/lightweightChartsFake'));

const ME = 'GET /v1/me';
const WATCHLIST = 'GET /v1/watchlist';
const PORTFOLIO = 'GET /v1/portfolio';
const CARD = 'GET /v1/cards/ex10-%3F';
const HISTORY = 'GET /v1/cards/ex10-%3F/prices?range=1M';
const FRESHNESS = 'GET /v1/prices/freshness';

const card = cardWithPrices(risingPrice, risingPrice, risingPrice);
const key = (index: number) => `ex10-?:finish${String(index)}:unlimited:-`;
const path = (index: number) => `ex10-%3F%3Afinish${String(index)}%3Aunlimited%3A-`;

function unown(index: number) {
  return {
    variant_key: key(index),
    card_id: 'ex10-?',
    finish: `finish${String(index)}`,
    edition: 'unlimited',
    treatment: '-',
    card: { name: 'Unown', set_name: 'Unseen Forces', number: '?' },
  };
}

afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
  clearXsrfCookie();
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

function prices(): HTMLElement {
  return screen.getByRole('region', { name: 'Prices' });
}

function rowOf(finish: number): HTMLElement {
  const row = within(prices())
    .getAllByRole('listitem')
    .find((item) => item.textContent.startsWith(`finish${String(finish)}`));
  if (row === undefined) throw new Error(`no row for finish${String(finish)}`);
  return row;
}

function controls(element: HTMLElement): string[] {
  return within(element)
    .queryAllByRole('button')
    .map((button) => button.getAttribute('aria-label') ?? button.textContent);
}

async function openCard(
  routes: Readonly<Record<string, AccountAnswer | readonly AccountAnswer[]>>,
  body: unknown = card,
) {
  const calls = stubAccountFetch({
    [CARD]: jsonResponse(body),
    [HISTORY]: jsonResponse(historyFor(card)),
    ...routes,
  });
  window.history.replaceState(null, '', '/card/ex10-%3F');
  render(<App />);
  await screen.findByRole('heading', { level: 1, name: 'Unown' });
  await settle();
  return calls;
}

const HELD_9999 = holdingOf({ ...unown(1), quantity: 9999, value: '707,000.00' });

describe('the read-only demo opening a card page directly', () => {
  it('shows only lines, never a Add, Remove, Edit or quantity control, when both its list reads fail', async () => {
    setXsrfCookie();
    await openCard({
      [ME]: jsonResponse(demoMe),
      [WATCHLIST]: problemResponse(serverErrorProblem('/v1/watchlist')),
      [PORTFOLIO]: problemResponse(serverErrorProblem('/v1/portfolio')),
    });
    expect(within(prices()).queryAllByRole('button')).toHaveLength(0);
    expect(within(prices()).queryByLabelText('Quantity owned')).toBeNull();
    expect(screen.getByRole('heading', { level: 1, name: 'Unown' })).toBeTruthy();
    expect(prices().textContent).toMatch(/\$\d/);
  });

  it('shows only lines when its lists are empty or wrongly shaped', async () => {
    setXsrfCookie();
    await openCard({
      [ME]: jsonResponse(demoMe),
      [WATCHLIST]: jsonResponse({ data: 'not a list' }),
      [PORTFOLIO]: jsonResponse(emptyPortfolioBody),
    });
    expect(within(prices()).queryAllByRole('button')).toHaveLength(0);
    expect(within(prices()).queryByText(/On your watchlist/)).toBeNull();
  });

  it('shows the lines of every variant it holds, quantity 9999 included, and still no control', async () => {
    setXsrfCookie();
    await openCard({
      [ME]: jsonResponse(demoMe),
      [WATCHLIST]: jsonResponse(watchlistBody([watchItemOf(unown(0))])),
      [PORTFOLIO]: jsonResponse(portfolioBody([HELD_9999], { total_value: '707000.00' })),
    });
    await within(rowOf(1)).findByText('In your portfolio: 9999.');
    expect(within(rowOf(0)).getByText('On your watchlist.')).toBeTruthy();
    expect(within(rowOf(2)).queryByText(/On your watchlist|In your portfolio/)).toBeNull();
    expect(within(prices()).queryAllByRole('button')).toHaveLength(0);
  });

  it('once its session answer lands after a slow read, the Add buttons that showed meanwhile are gone', async () => {
    setXsrfCookie();
    let release: (response: Response) => void = () => undefined;
    const pending = new Promise<Response>((resolve) => {
      release = resolve;
    });
    const answers: Record<string, Response> = {
      [FRESHNESS]: jsonResponse({
        data: { day: '2026-10-12', age_hours: 1.5, within_slo: true },
      }),
      [CARD]: jsonResponse(card),
      [HISTORY]: jsonResponse(historyFor(card)),
      [WATCHLIST]: jsonResponse(watchlistBody([watchItemOf(unown(0))])),
      [PORTFOLIO]: jsonResponse(emptyPortfolioBody),
    };
    vi.stubGlobal('fetch', (input: string | URL, init?: RequestInit): Promise<Response> => {
      const name = `${init?.method ?? 'GET'} ${String(input)}`;
      if (name === ME) return pending;
      const found = answers[name];
      return found === undefined
        ? Promise.reject(new Error(`no ${name}`))
        : Promise.resolve(found.clone());
    });
    window.history.replaceState(null, '', '/card/ex10-%3F');
    render(<App />);
    await screen.findByRole('heading', { level: 1, name: 'Unown' });
    await settle();
    expect(prices().textContent).toMatch(/\$\d/);
    release(jsonResponse(demoMe));
    await within(rowOf(0)).findByText('On your watchlist.');
    await settle();
    expect(controls(prices())).toEqual([]);
    expect(within(prices()).queryByLabelText('Quantity owned')).toBeNull();
  });
});

describe('a signed-in card page with a failing, slow or odd list read', () => {
  it.each([
    [
      'a 500 on both lists',
      problemResponse(serverErrorProblem('/v1/watchlist')),
      problemResponse(serverErrorProblem('/v1/portfolio')),
    ],
    ['a network error on both', new Error('network down'), new Error('network down')],
    ['a wrong shape on both', jsonResponse({ data: 7 }), jsonResponse({ nothing: true })],
    [
      'a portfolio whose meta lacks the change fields',
      jsonResponse(watchlistBody([])),
      jsonResponse({ data: [], meta: { count: 0 } }),
    ],
  ] as const)(
    'after %s the prices and the Add buttons stay, with no page-wide error',
    async (...row) => {
      const [, watchlist, portfolio] = row;
      setXsrfCookie();
      await openCard({
        [ME]: jsonResponse(signedInMe),
        [WATCHLIST]: watchlist,
        [PORTFOLIO]: portfolio,
      });
      expect(screen.getByRole('heading', { level: 1, name: 'Unown' })).toBeTruthy();
      expect(prices().textContent).toMatch(/\$\d/);
      expect(controls(prices())).toHaveLength(6);
      expect(screen.queryByRole('alert')).toBeNull();
      expect(document.body.textContent).not.toMatch(/\b(NaN|undefined|Infinity)\b/);
    },
  );

  it('reads each list once for a card with three variants and once for a card with one', async () => {
    setXsrfCookie();
    const three = await openCard({
      [ME]: jsonResponse(signedInMe),
      [WATCHLIST]: jsonResponse(watchlistBody([])),
      [PORTFOLIO]: jsonResponse(emptyPortfolioBody),
    });
    const threeKeys = three.map((call) => call.key);
    cleanup();
    const oneCard = cardWithPrices(risingPrice);
    const one = await openCard(
      {
        [ME]: jsonResponse(signedInMe),
        [WATCHLIST]: jsonResponse(watchlistBody([])),
        [PORTFOLIO]: jsonResponse(emptyPortfolioBody),
      },
      oneCard,
    );
    expect(threeKeys.filter((name) => name === WATCHLIST)).toHaveLength(1);
    expect(threeKeys.filter((name) => name === PORTFOLIO)).toHaveLength(1);
    expect(one.map((call) => call.key)).toEqual(threeKeys);
  });
});

describe('three variants in three different states', () => {
  it('shows each variant its own lines: both, watchlist only (with a quantity of 9999 elsewhere), neither', async () => {
    setXsrfCookie();
    await openCard({
      [ME]: jsonResponse(signedInMe),
      [WATCHLIST]: jsonResponse(watchlistBody([watchItemOf(unown(0)), watchItemOf(unown(1))])),
      [PORTFOLIO]: jsonResponse(
        portfolioBody([holdingOf({ ...unown(0), quantity: 9999, value: '707,000.00' })], {
          total_value: '707000.00',
        }),
      ),
    });
    await within(rowOf(0)).findByText('In your portfolio: 9999.');
    expect(controls(rowOf(0))).toEqual([
      'Remove finish0, unlimited from your watchlist',
      'Edit how many finish0, unlimited you hold',
      'Remove finish0, unlimited from your portfolio',
    ]);
    expect(within(rowOf(1)).getByText('On your watchlist.')).toBeTruthy();
    expect(within(rowOf(1)).queryByText(/In your portfolio/)).toBeNull();
    expect(controls(rowOf(1))).toEqual([
      'Add finish1, unlimited to your portfolio',
      'Remove finish1, unlimited from your watchlist',
    ]);
    expect(controls(rowOf(2))).toEqual([
      'Add finish2, unlimited to your watchlist',
      'Add finish2, unlimited to your portfolio',
    ]);
    for (const word of FORBIDDEN_WORDS) expect(prices().textContent).not.toMatch(word);
  });
});

describe('adding and removing from the card page', () => {
  it('adds to the watchlist: one PUT, the list read again, the line with its Remove and focus on it', async () => {
    setXsrfCookie();
    const calls = await openCard({
      [ME]: jsonResponse(signedInMe),
      [WATCHLIST]: [
        jsonResponse(watchlistBody([])),
        jsonResponse(watchlistBody([watchItemOf(unown(2))])),
      ],
      [PORTFOLIO]: jsonResponse(emptyPortfolioBody),
      [`PUT /v1/watchlist/${path(2)}`]: jsonResponse({ data: { variant_key: key(2) } }),
    });
    const row = rowOf(2);
    fireEvent.click(
      await within(row).findByRole('button', { name: 'Add finish2, unlimited to your watchlist' }),
    );
    await within(row).findByText('On your watchlist.');
    const remove = within(row).getByRole('button', {
      name: 'Remove finish2, unlimited from your watchlist',
    });
    await waitFor(() => {
      expect(document.activeElement).toBe(remove);
    });
    // One line only: the outcome line gives way to the list's own.
    expect(within(row).getAllByText('On your watchlist.')).toHaveLength(1);
    expect(
      within(row).queryByRole('button', { name: 'Add finish2, unlimited to your watchlist' }),
    ).toBeNull();
    expect(calls.filter((call) => call.key === `PUT /v1/watchlist/${path(2)}`)).toHaveLength(1);
  });

  it('removes from the watchlist, then a reload of the page reads it gone and shows the Add button', async () => {
    setXsrfCookie();
    const routes = {
      [ME]: jsonResponse(signedInMe),
      [WATCHLIST]: [
        jsonResponse(watchlistBody([watchItemOf(unown(0))])),
        jsonResponse(watchlistBody([])),
      ],
      [PORTFOLIO]: jsonResponse(emptyPortfolioBody),
      [`DELETE /v1/watchlist/${path(0)}`]: rawResponse(204, null),
    };
    await openCard(routes);
    fireEvent.click(
      await within(rowOf(0)).findByRole('button', {
        name: 'Remove finish0, unlimited from your watchlist',
      }),
    );
    await within(rowOf(0)).findByRole('button', {
      name: 'Add finish0, unlimited to your watchlist',
    });
    expect(within(rowOf(0)).queryByText('On your watchlist.')).toBeNull();
    cleanup();
    // The reload reads the server as it is now: the row is gone.
    await openCard({ ...routes, [WATCHLIST]: jsonResponse(watchlistBody([])) });
    expect(
      await within(rowOf(0)).findByRole('button', {
        name: 'Add finish0, unlimited to your watchlist',
      }),
    ).toBeTruthy();
  });

  it('leaves the line and shows the calm problem when removing from the portfolio answers 429, with focus on its Remove', async () => {
    setXsrfCookie();
    await openCard({
      [ME]: jsonResponse(signedInMe),
      [WATCHLIST]: jsonResponse(watchlistBody([])),
      [PORTFOLIO]: jsonResponse(portfolioBody([HELD_9999], { total_value: '707000.00' })),
      [`DELETE /v1/portfolio/${path(1)}`]: problemResponse({
        type: 'https://pokeprices.andrestheperez.com/problems/too-many-requests',
        title: 'Too Many Requests',
        status: 429,
        instance: `/v1/portfolio/${path(1)}`,
        request_id: 'req-edge-429',
      }),
    });
    const row = rowOf(1);
    await within(row).findByText('In your portfolio: 9999.');
    fireEvent.click(
      within(row).getByRole('button', { name: 'Remove finish1, unlimited from your portfolio' }),
    );
    await within(row).findByText(/Could not change your portfolio/);
    expect(within(row).getByText('In your portfolio: 9999.')).toBeTruthy();
    await waitFor(() => {
      expect(document.activeElement).toBe(
        within(row).getByRole('button', { name: 'Remove finish1, unlimited from your portfolio' }),
      );
    });
  });
});
