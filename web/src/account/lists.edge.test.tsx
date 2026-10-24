import { act, cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import App from '../App';
import { matchRoute, safeNext } from '../router';
import {
  FORBIDDEN_WORDS,
  accountValidationProblem,
  clearXsrfCookie,
  demoMe,
  demoReadOnlyProblem,
  emptyPortfolioBody,
  historyFor,
  holdingOf,
  jsonResponse,
  portfolioBody,
  pricedCardBody,
  problemResponse,
  rawResponse,
  sentBody,
  serviceUnavailableProblem,
  signedInMe,
  stubAccountFetch,
  unauthenticatedProblem,
  watchItemOf,
  watchlistBody,
} from '../test/fixtures';
import { FOCUS, QUIET_BUTTON } from './fields';

// jsdom has no canvas: the chart library is replaced by its recording fake (T06 helper).
vi.mock('lightweight-charts', () => import('../test/lightweightChartsFake'));

const CARD = 'GET /v1/cards/ex10-%3F';
const HISTORY = 'GET /v1/cards/ex10-%3F/prices?range=1M';
const WATCH_PUT = 'PUT /v1/watchlist/ex10-%3F%3Aholo%3Aunlimited%3A-';
const HOLDING_PUT = 'PUT /v1/portfolio/ex10-%3F%3Aholo%3Aunlimited%3A-';

function visit(path: string): void {
  window.history.replaceState(null, '', path);
}

afterEach(() => {
  cleanup();
  clearXsrfCookie();
  vi.useRealTimers();
  visit('/');
});

describe('T07 edge: nothing of one account shows under the next one (D8, D9, privacy)', () => {
  it('does not show the first account watchlist rows to the account that signs in after it', async () => {
    let me: Response = jsonResponse(signedInMe);
    let list: Response = jsonResponse(watchlistBody());
    stubAccountFetch({
      'GET /v1/me': () => me,
      'GET /v1/watchlist': () => list,
      'POST /logout': rawResponse(204, null),
      'POST /login': jsonResponse({ two_factor: false }),
    });
    visit('/watchlist');
    render(<App />);
    expect(await screen.findByRole('link', { name: 'Chimecho' })).toBeTruthy();

    // Account A signs out ...
    fireEvent.click(screen.getByRole('link', { name: 'Account' }));
    me = problemResponse(unauthenticatedProblem);
    fireEvent.click(await screen.findByRole('button', { name: 'Sign out' }));
    await screen.findByRole('heading', {
      level: 1,
      name: 'Pokémon card prices at the daily close',
    });

    // ... and account B (an empty watchlist) signs in, in the same tab.
    fireEvent.click(within(screen.getByRole('banner')).getByRole('link', { name: 'Sign in' }));
    fireEvent.change(await screen.findByLabelText('Email'), {
      target: { value: 'second@example.com' },
    });
    fireEvent.change(screen.getByLabelText('Password'), { target: { value: 'fake-pass-0000' } });
    me = jsonResponse({ data: { ...signedInMe.data, email: 'second@example.com' } });
    list = jsonResponse(watchlistBody([]));
    fireEvent.click(within(screen.getByRole('main')).getByRole('button', { name: 'Sign in' }));
    await screen.findByText('Signed in as second@example.com');

    fireEvent.click(screen.getByRole('link', { name: 'Watchlist' }));
    // B's own (empty) list is what shows; none of A's rows may be on screen at any time.
    await screen.findByText(
      'Your watchlist is empty. Open a card from Search and choose Add to watchlist.',
    );
    expect(screen.queryByRole('link', { name: 'Chimecho' })).toBeNull();
    expect(document.body.textContent).not.toContain('Charizard');
  });
});

describe('T07 edge: the card-page controls (R221, D10)', () => {
  async function openCard(
    routes: Parameters<typeof stubAccountFetch>[0] = {},
  ): Promise<ReturnType<typeof stubAccountFetch>> {
    const calls = stubAccountFetch({
      [CARD]: jsonResponse(pricedCardBody),
      [HISTORY]: jsonResponse(historyFor(pricedCardBody)),
      ...routes,
    });
    visit('/card/ex10-%3F');
    render(<App />);
    await screen.findByRole('heading', { level: 1, name: 'Unown' });
    return calls;
  }

  it('renders both controls as real 44px buttons with the P3 focus ring, the D10 words and labels', async () => {
    await openCard();
    const prices = screen.getByRole('region', { name: 'Prices' });
    const watch = within(prices).getByRole<HTMLInputElement>('button', {
      name: 'Add holo, unlimited to your watchlist',
    });
    const hold = within(prices).getByRole<HTMLInputElement>('button', {
      name: 'Add holo, unlimited to your portfolio',
    });
    for (const control of [watch, hold]) {
      expect(control.tagName).toBe('INPUT');
      expect(control.type).toBe('button');
      expect(control.disabled).toBe(false);
      const classes = control.className.split(/\s+/);
      expect(classes).toContain('min-h-11');
      expect(classes).toContain('min-w-11');
      for (const token of FOCUS.split(/\s+/)) expect(classes).toContain(token);
      for (const token of QUIET_BUTTON.split(/\s+/)) expect(classes).toContain(token);
    }
    expect(watch.value).toBe('Add to watchlist');
    expect(hold.value).toBe('Add to portfolio');
    // Opening the form: the labelled field with the browser limits as hints only.
    fireEvent.click(hold);
    // (no portfolio route stubbed on purpose: the fetch fails and the form shows the failure)
    expect(hold.getAttribute('aria-expanded')).toBe('true');
  });

  it('sends one PUT when the watchlist button is clicked twice before the answer', async () => {
    const calls = await openCard({ [WATCH_PUT]: jsonResponse({ data: {} }) });
    const button = screen.getAllByRole('button', {
      name: 'Add holo, unlimited to your watchlist',
    })[0] as HTMLElement;
    fireEvent.click(button);
    fireEvent.click(button);
    await screen.findByText('On your watchlist.');
    expect(calls.filter((call) => call.key === WATCH_PUT)).toHaveLength(1);
  });

  it('sends one PUT when the card-page Save is submitted twice before the answer', async () => {
    const calls = await openCard({
      'GET /v1/portfolio': jsonResponse(emptyPortfolioBody),
      [HOLDING_PUT]: jsonResponse({ data: { variant_key: 'x', quantity: 2 } }),
    });
    fireEvent.click(screen.getByRole('button', { name: 'Add holo, unlimited to your portfolio' }));
    const field = await screen.findByLabelText('Quantity owned');
    expect((field as HTMLInputElement).value).toBe('1');
    fireEvent.change(field, { target: { value: '2' } });
    const form = field.closest('form') as HTMLFormElement;
    fireEvent.submit(form);
    fireEvent.submit(form);
    await screen.findByText('In your portfolio: 2.');
    expect(calls.filter((call) => call.key === HOLDING_PUT)).toHaveLength(1);
    expect(sentBody(calls.find((call) => call.key === HOLDING_PUT))).toEqual({ quantity: 2 });
  });

  it.each([
    ['', null],
    ['1.5', null],
    ['abc', null],
    ['-3', null],
    ['10000', 10000],
    ['0', 0],
    ['0007', 7],
  ])('sends %j as %j and shows the API 422 under Quantity owned', async (typed, sent) => {
    const calls = await openCard({
      'GET /v1/portfolio': jsonResponse(emptyPortfolioBody),
      [HOLDING_PUT]: problemResponse(
        accountValidationProblem('/v1/portfolio/x', { quantity: ['The quantity is invalid.'] }),
      ),
    });
    fireEvent.click(screen.getByRole('button', { name: 'Add holo, unlimited to your portfolio' }));
    const field = await screen.findByLabelText<HTMLInputElement>('Quantity owned');
    expect(field.min).toBe('1');
    expect(field.max).toBe('9999');
    expect(field.step).toBe('1');
    fireEvent.change(field, { target: { value: typed } });
    fireEvent.submit(field.closest('form') as HTMLFormElement);
    await waitFor(() => {
      expect(field.getAttribute('aria-invalid')).toBe('true');
    });
    const message = document.getElementById(field.getAttribute('aria-describedby') ?? 'missing');
    expect(message?.textContent).toBe('The quantity is invalid.');
    expect(sentBody(calls.find((call) => call.key === HOLDING_PUT))).toEqual({ quantity: sent });
    // The form stays open for a correction.
    expect(screen.getByLabelText('Quantity owned')).toBe(field);
  });

  it('answers a demo write on the card page with the read-only line and nothing else', async () => {
    const calls = await openCard({
      'GET /v1/me': jsonResponse(demoMe),
      [WATCH_PUT]: problemResponse(demoReadOnlyProblem('/v1/watchlist/x')),
    });
    fireEvent.click(
      screen.getAllByRole('button', {
        name: 'Add holo, unlimited to your watchlist',
      })[0] as HTMLElement,
    );
    expect(await screen.findByText('The demo account is read-only.')).toBeTruthy();
    expect(screen.queryByRole('alert')).toBeNull();
    expect(calls.filter((call) => call.key === WATCH_PUT)).toHaveLength(1);
    // Nothing but the card and its history is read on the public page, besides the one write.
    expect(calls.map((call) => call.key).filter((key) => key.startsWith('GET /v1/me'))).toEqual([]);
  });
});

describe('T07 edge: money strings are printed as the API sent them (D9)', () => {
  it('prints 18-digit totals, trailing zeros and a negative change without any number rounding', async () => {
    stubAccountFetch({
      'GET /v1/me': jsonResponse(signedInMe),
      'GET /v1/portfolio': jsonResponse(
        portfolioBody(
          [
            holdingOf({
              quantity: 9999,
              value: '9999999999.99',
              price: {
                ...holdingOf().price,
                close: '1000000.00',
                previous_close: '1001234.50',
                previous_day: '2026-10-10',
                change: '-1234.50',
                change_pct: '-0.12',
              } as ReturnType<typeof holdingOf>['price'],
            }),
          ],
          {
            total_value: '123456789012345678.90',
            priced: 1,
            unpriced: 0,
            first_day: '2026-10-12',
            last_day: '2026-10-12',
          },
        ),
      ),
    });
    visit('/portfolio');
    render(<App />);
    expect(await screen.findByText('Total value $123,456,789,012,345,678.90')).toBeTruthy();
    expect(
      screen.getByText('as of 2026-10-12 from TCGCSV (daily close)', { selector: 'p' }),
    ).toBeTruthy();
    expect(screen.getByText('Value $9,999,999,999.99 (9999 × $1,000,000.00)')).toBeTruthy();
    expect(screen.getByText('Market price $1,000,000.00')).toBeTruthy();
    expect(screen.getByText('▼ -$1,234.50 (-0.12%) vs previous close (2026-10-10)')).toBeTruthy();
    expect(screen.queryByText(/unpriced|no price/i)).toBeNull();
  });
});

describe('T07 edge: a failed poll keeps the last good list (P3 D9)', () => {
  it('keeps the watchlist rows on screen when the five-minute poll answers 503', async () => {
    vi.useFakeTimers({ shouldAdvanceTime: true });
    const calls = stubAccountFetch({
      'GET /v1/me': jsonResponse(signedInMe),
      'GET /v1/watchlist': [
        jsonResponse(watchlistBody([watchItemOf()])),
        problemResponse(serviceUnavailableProblem('/v1/watchlist')),
      ],
    });
    visit('/watchlist');
    render(<App />);
    expect(await screen.findByText('Market price $968.91')).toBeTruthy();
    await act(async () => {
      await vi.advanceTimersByTimeAsync(300_001);
    });
    await waitFor(() => {
      expect(calls.filter((call) => call.key === 'GET /v1/watchlist')).toHaveLength(2);
    });
    expect(screen.getByText('Market price $968.91')).toBeTruthy();
    expect(screen.queryByText(/Could not load your watchlist/)).toBeNull();
    for (const word of FORBIDDEN_WORDS) expect(document.body.textContent).not.toMatch(word);
  });
});

describe('T07 edge: routes and next (D10)', () => {
  it('keeps trailing-slash and extra-segment variants of the new routes not found, and refuses them as next', () => {
    for (const path of [
      '/watchlist/',
      '/portfolio/',
      '/watchlist/x',
      '/portfolio//',
      '/Watchlist',
    ]) {
      expect(matchRoute(path, '')).toEqual({ name: 'not-found' });
      expect(safeNext(path)).toBe('/account');
    }
    expect(matchRoute('/watchlist', '')).toEqual({ name: 'watchlist' });
    expect(matchRoute('/portfolio', '')).toEqual({ name: 'portfolio' });
  });
});
