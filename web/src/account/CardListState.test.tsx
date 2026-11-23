// The card page knows the session after a reload (PublicSession: GET /v1/me only when the browser
// holds the XSRF-TOKEN cookie) and, signed in, reads the two lists once each to show, per variant,
// "On your watchlist." with Remove and "In your portfolio: N." with Edit and Remove, both at once.
// Without the cookie it sends exactly what it sent before; a 401 with the cookie is the signed-out
// page, calmly; the read-only demo sees the two lines and no control that writes.
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import App from '../App';
import {
  FORBIDDEN_WORDS,
  clearXsrfCookie,
  demoMe,
  emptyPortfolioBody,
  estimateOf,
  historyFor,
  holdingOf,
  jsonResponse,
  portfolioBody,
  pricedCardBody,
  problemResponse,
  rawResponse,
  sentBody,
  serverErrorProblem,
  setXsrfCookie,
  signedInMe,
  stubAccountFetch,
  unauthenticatedProblem,
  watchItemOf,
  watchlistBody,
  type AccountAnswer,
} from '../test/fixtures';

vi.mock('lightweight-charts', () => import('../test/lightweightChartsFake'));

const CARD = 'GET /v1/cards/ex10-%3F';
const HISTORY = 'GET /v1/cards/ex10-%3F/prices?range=1M';
const FRESHNESS = 'GET /v1/prices/freshness';
const ME = 'GET /v1/me';
const WATCHLIST = 'GET /v1/watchlist';
const PORTFOLIO = 'GET /v1/portfolio';
const HOLO = 'ex10-?:holo:unlimited:-';
const HOLO_PATH = 'ex10-%3F%3Aholo%3Aunlimited%3A-';
const WATCH_DELETE = `DELETE /v1/watchlist/${HOLO_PATH}`;
const HOLD_PUT = `PUT /v1/portfolio/${HOLO_PATH}`;
const HOLD_DELETE = `DELETE /v1/portfolio/${HOLO_PATH}`;

const unown = {
  variant_key: HOLO,
  card_id: 'ex10-?',
  finish: 'holo',
  edition: 'unlimited',
  treatment: '-',
  card: { name: 'Unown', set_name: 'Unseen Forces', number: '?' },
};

const watched = watchlistBody([watchItemOf(unown)]);
const held = portfolioBody([holdingOf({ ...unown, quantity: 3, value: '212.43' })], {
  total_value: '212.43',
  priced: 1,
  unpriced: 0,
});

afterEach(() => {
  cleanup();
  clearXsrfCookie();
  window.localStorage.clear();
  window.history.replaceState(null, '', '/');
});

async function settle(): Promise<void> {
  for (let i = 0; i < 6; i += 1) {
    await new Promise((resolve) => setTimeout(resolve, 0));
  }
}

function prices(): HTMLElement {
  return screen.getByRole('region', { name: 'Prices' });
}

function rowOf(label: RegExp): HTMLElement {
  const row = within(prices())
    .getAllByRole('listitem')
    .find((item) => label.test(item.textContent));
  if (row === undefined) throw new Error(`no row ${String(label)}`);
  return row;
}

async function openCard(
  routes: Readonly<Record<string, AccountAnswer | readonly AccountAnswer[]>>,
): Promise<ReturnType<typeof stubAccountFetch>> {
  const calls = stubAccountFetch({
    [CARD]: jsonResponse(pricedCardBody),
    [HISTORY]: jsonResponse(historyFor(pricedCardBody)),
    ...routes,
  });
  window.history.replaceState(null, '', '/card/ex10-%3F');
  render(<App />);
  await screen.findByRole('heading', { level: 1, name: 'Unown' });
  await settle();
  return calls;
}

function controls(element: HTMLElement): string[] {
  return within(element)
    .queryAllByRole('button')
    .map((button) => button.getAttribute('aria-label') ?? button.textContent);
}

describe('without the XSRF-TOKEN cookie the card page sends what it sent before', () => {
  it('asks no session and no list, sets no cookie, and keeps both Add buttons on every variant', async () => {
    const calls = await openCard({});
    expect(calls.map((call) => call.key)).toEqual([FRESHNESS, CARD, HISTORY]);
    expect(document.cookie).toBe('');
    expect(controls(prices())).toEqual([
      'Add holo, unlimited to your watchlist',
      'Add holo, unlimited to your portfolio',
      'Add reverse, unlimited to your watchlist',
      'Add reverse, unlimited to your portfolio',
    ]);
    expect(screen.queryByText(/On your watchlist/)).toBeNull();
  });
});

describe('with the cookie and a signed-in answer', () => {
  it('reads each list once for every variant, in this order, and shows both lines with their controls', async () => {
    setXsrfCookie();
    const calls = await openCard({
      [ME]: jsonResponse(signedInMe),
      [WATCHLIST]: jsonResponse(watched),
      [PORTFOLIO]: jsonResponse(held),
    });
    const holo = rowOf(/^holo/);
    await within(holo).findByText('In your portfolio: 3.');
    expect(within(holo).getByText('On your watchlist.')).toBeTruthy();
    expect(calls.map((call) => call.key)).toEqual([
      FRESHNESS,
      CARD,
      ME,
      HISTORY,
      WATCHLIST,
      PORTFOLIO,
    ]);
    expect(controls(holo)).toEqual([
      'Remove holo, unlimited from your watchlist',
      'Edit how many holo, unlimited you hold',
      'Remove holo, unlimited from your portfolio',
    ]);
    expect(controls(rowOf(/^reverse/))).toEqual([
      'Add reverse, unlimited to your watchlist',
      'Add reverse, unlimited to your portfolio',
    ]);
    expect(within(holo).getByRole('link', { name: 'See your watchlist' })).toBeTruthy();
    expect(within(holo).getByRole('link', { name: 'See your portfolio' })).toBeTruthy();
    for (const word of FORBIDDEN_WORDS) expect(prices().textContent).not.toMatch(word);
  });

  it('keeps the lines on a reload of the same page: a fresh mount reads them again', async () => {
    setXsrfCookie();
    const routes = {
      [ME]: jsonResponse(signedInMe),
      [WATCHLIST]: jsonResponse(watched),
      [PORTFOLIO]: jsonResponse(held),
    };
    await openCard(routes);
    await within(rowOf(/^holo/)).findByText('In your portfolio: 3.');
    cleanup();
    const again = await openCard(routes);
    await within(rowOf(/^holo/)).findByText('In your portfolio: 3.');
    expect(within(rowOf(/^holo/)).getByText('On your watchlist.')).toBeTruthy();
    expect(again.map((call) => call.key)).toEqual([
      FRESHNESS,
      CARD,
      ME,
      HISTORY,
      WATCHLIST,
      PORTFOLIO,
    ]);
  });

  it('removes from the watchlist: one DELETE, the list read again, and focus on the Add button that returns', async () => {
    setXsrfCookie();
    const calls = await openCard({
      [ME]: jsonResponse(signedInMe),
      [WATCHLIST]: [jsonResponse(watched), jsonResponse(watchlistBody([]))],
      [PORTFOLIO]: jsonResponse(held),
      [WATCH_DELETE]: rawResponse(204, null),
    });
    const holo = rowOf(/^holo/);
    fireEvent.click(
      await within(holo).findByRole('button', {
        name: 'Remove holo, unlimited from your watchlist',
      }),
    );
    const add = await within(holo).findByRole('button', {
      name: 'Add holo, unlimited to your watchlist',
    });
    await waitFor(() => {
      expect(document.activeElement).toBe(add);
    });
    expect(within(holo).queryByText('On your watchlist.')).toBeNull();
    expect(within(holo).getByText('In your portfolio: 3.')).toBeTruthy();
    expect(calls.map((call) => call.key).slice(6)).toEqual([WATCH_DELETE, WATCHLIST]);
  });

  it('edits the quantity: the form opens on 3, PUT sends 5, and the line and the header estimate follow the list', async () => {
    setXsrfCookie();
    const after = portfolioBody([holdingOf({ ...unown, quantity: 5, value: '354.05' })], {
      total_value: '354.05',
      priced: 1,
      unpriced: 0,
      change: '7.25',
      change_pct: '2.09',
      count: 1,
      first_day: '2026-10-12',
      last_day: '2026-10-12',
    });
    const calls = await openCard({
      [ME]: jsonResponse({ data: { ...signedInMe.data, portfolio: estimateOf() } }),
      [WATCHLIST]: jsonResponse(watched),
      [PORTFOLIO]: [jsonResponse(held), jsonResponse(after)],
      [HOLD_PUT]: jsonResponse({ data: { variant_key: HOLO, quantity: 5 } }),
    });
    const holo = rowOf(/^holo/);
    const edit = await within(holo).findByRole('button', {
      name: 'Edit how many holo, unlimited you hold',
    });
    fireEvent.click(edit);
    expect(edit.getAttribute('aria-expanded')).toBe('true');
    const field = within(holo).getByLabelText<HTMLInputElement>('Quantity owned');
    expect(field.value).toBe('3');
    fireEvent.change(field, { target: { value: '5' } });
    fireEvent.click(within(holo).getByRole('button', { name: 'Save' }));
    await within(holo).findByText('In your portfolio: 5.');
    expect(sentBody(calls.find((call) => call.key === HOLD_PUT))).toEqual({ quantity: 5 });
    expect(calls.map((call) => call.key).slice(6)).toEqual([HOLD_PUT, PORTFOLIO]);
    expect(within(holo).queryByLabelText('Quantity owned')).toBeNull();
    const banner = screen.getByRole('banner');
    await within(banner).findByText('Portfolio estimate $354.05');
    expect(calls.filter((call) => call.key === ME)).toHaveLength(1);
  });

  it('removes from the portfolio: one DELETE, the list read again, focus on Add to portfolio', async () => {
    setXsrfCookie();
    const calls = await openCard({
      [ME]: jsonResponse(signedInMe),
      [WATCHLIST]: jsonResponse(watched),
      [PORTFOLIO]: [jsonResponse(held), jsonResponse(emptyPortfolioBody)],
      [HOLD_DELETE]: rawResponse(204, null),
    });
    const holo = rowOf(/^holo/);
    fireEvent.click(
      await within(holo).findByRole('button', {
        name: 'Remove holo, unlimited from your portfolio',
      }),
    );
    const add = await within(holo).findByRole('button', {
      name: 'Add holo, unlimited to your portfolio',
    });
    await waitFor(() => {
      expect(document.activeElement).toBe(add);
    });
    expect(within(holo).getByText('On your watchlist.')).toBeTruthy();
    expect(calls.map((call) => call.key).slice(6)).toEqual([HOLD_DELETE, PORTFOLIO]);
  });

  it('keeps the line and shows the problem when a removal fails, focus on its Remove', async () => {
    setXsrfCookie();
    await openCard({
      [ME]: jsonResponse(signedInMe),
      [WATCHLIST]: jsonResponse(watched),
      [PORTFOLIO]: jsonResponse(held),
      [WATCH_DELETE]: problemResponse(serverErrorProblem(`/v1/watchlist/${HOLO_PATH}`)),
    });
    const holo = rowOf(/^holo/);
    const remove = await within(holo).findByRole('button', {
      name: 'Remove holo, unlimited from your watchlist',
    });
    fireEvent.click(remove);
    await within(holo).findByText(/Could not change your watchlist/);
    expect(within(holo).getByText('On your watchlist.')).toBeTruthy();
    await waitFor(() => {
      expect(document.activeElement).toBe(
        within(holo).getByRole('button', { name: 'Remove holo, unlimited from your watchlist' }),
      );
    });
  });
});

describe('with the cookie and a 401 (an expired session)', () => {
  it('shows the signed-out page calmly: Add buttons, Sign in in the header, no problem block', async () => {
    setXsrfCookie();
    const calls = await openCard({ [ME]: problemResponse(unauthenticatedProblem) });
    expect(calls.map((call) => call.key)).toEqual([FRESHNESS, CARD, ME, HISTORY]);
    expect(controls(prices())).toHaveLength(4);
    expect(screen.queryByText(/Could not/)).toBeNull();
    expect(screen.queryByText(/Request ID/)).toBeNull();
    expect(within(screen.getByRole('banner')).getByRole('link', { name: 'Sign in' })).toBeTruthy();
  });
});

describe('the read-only demo opening the card page directly', () => {
  it('shows both lines and no control that writes: no Add, Remove, Edit or quantity field', async () => {
    setXsrfCookie();
    const calls = await openCard({
      [ME]: jsonResponse(demoMe),
      [WATCHLIST]: jsonResponse(watched),
      [PORTFOLIO]: jsonResponse(held),
    });
    const holo = rowOf(/^holo/);
    await within(holo).findByText('In your portfolio: 3.');
    expect(within(holo).getByText('On your watchlist.')).toBeTruthy();
    expect(within(prices()).queryAllByRole('button')).toHaveLength(0);
    expect(within(prices()).queryByLabelText('Quantity owned')).toBeNull();
    for (const word of FORBIDDEN_WORDS) expect(prices().textContent).not.toMatch(word);
    expect(calls.map((call) => call.key)).toEqual([
      FRESHNESS,
      CARD,
      ME,
      HISTORY,
      WATCHLIST,
      PORTFOLIO,
    ]);
  });
});
