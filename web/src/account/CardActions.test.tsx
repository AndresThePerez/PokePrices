import { cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import App from '../App';
import { matchRoute } from '../router';
import {
  FORBIDDEN_WORDS,
  type AccountAnswer,
  accountValidationProblem,
  clearXsrfCookie,
  demoReadOnlyProblem,
  emptyPortfolioBody,
  historyFor,
  holdingOf,
  jsonResponse,
  lightOnlyClasses,
  portfolioBody,
  pricedCardBody,
  problemResponse,
  sentBody,
  serviceUnavailableProblem,
  shortControls,
  stubAccountFetch,
  unauthenticatedProblem,
} from '../test/fixtures';

// jsdom has no canvas: the chart library is replaced by its recording fake (T06 helper).
vi.mock('lightweight-charts', () => import('../test/lightweightChartsFake'));

const CARD = 'GET /v1/cards/ex10-%3F';
const HISTORY = 'GET /v1/cards/ex10-%3F/prices?range=1M';
const HOLO = 'ex10-?:holo:unlimited:-';
const WATCH_PUT = 'PUT /v1/watchlist/ex10-%3F%3Aholo%3Aunlimited%3A-';
const HOLDING_PUT = 'PUT /v1/portfolio/ex10-%3F%3Aholo%3Aunlimited%3A-';
const SIGN_IN_LINE = 'Sign in to keep a watchlist and a portfolio.';
const READ_ONLY_LINE = 'The demo account is read-only.';

function visit(path: string): void {
  window.history.replaceState(null, '', path);
}

afterEach(() => {
  cleanup();
  clearXsrfCookie();
  vi.useRealTimers();
  visit('/');
});

type Routes = Readonly<Record<string, AccountAnswer | readonly AccountAnswer[]>>;

/** The Unown card page with its holo (priced) and reverse holo (unpriced) rows. */
async function openCard(routes: Routes = {}): Promise<ReturnType<typeof stubAccountFetch>> {
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

function prices(): HTMLElement {
  return screen.getByRole('region', { name: 'Prices' });
}

function holoRow(): HTMLElement {
  const row = within(prices()).getByText('holo, unlimited').closest('li');
  if (row === null) throw new Error('no holo row');
  return row;
}

function addToWatchlist(): void {
  fireEvent.click(
    within(prices()).getByRole('button', { name: 'Add holo, unlimited to your watchlist' }),
  );
}

function addToPortfolio(): void {
  fireEvent.click(
    within(prices()).getByRole('button', { name: 'Add holo, unlimited to your portfolio' }),
  );
}

describe('the card-page buttons (T07 criterion 3, D10)', () => {
  it('shows both buttons on every Prices row and fetches nothing before a click', async () => {
    const calls = await openCard();
    for (const label of ['holo, unlimited', 'reverse, unlimited']) {
      const watch = within(prices()).getByRole('button', {
        name: `Add ${label} to your watchlist`,
      });
      const hold = within(prices()).getByRole('button', {
        name: `Add ${label} to your portfolio`,
      });
      expect((watch as HTMLInputElement).value).toBe('Add to watchlist');
      expect((hold as HTMLInputElement).value).toBe('Add to portfolio');
      expect(hold.getAttribute('aria-expanded')).toBe('false');
    }
    await new Promise((resolve) => setTimeout(resolve, 30));
    expect(calls.map((call) => call.key)).toEqual(['GET /v1/prices/freshness', CARD, HISTORY]);
    expect(within(prices()).queryByLabelText('Quantity owned')).toBeNull();
  });

  it('adds to the watchlist with one PUT and links to the watchlist', async () => {
    const calls = await openCard({ [WATCH_PUT]: jsonResponse({ data: { variant_key: HOLO } }) });
    addToWatchlist();
    const status = await within(holoRow()).findByText('On your watchlist.');
    expect(status.closest('[role="status"]')).not.toBeNull();
    const link = within(holoRow()).getByRole('link', { name: 'See your watchlist' });
    expect(link.getAttribute('href')).toBe('/watchlist');
    const keys = calls.map((call) => call.key);
    expect(keys.filter((key) => key === WATCH_PUT)).toHaveLength(1);
    expect(keys.slice(3)).toEqual(['GET /sanctum/csrf-cookie', WATCH_PUT]);
    expect(sentBody(calls.find((call) => call.key === WATCH_PUT))).toBeUndefined();
  });

  it('asks a signed-out visitor to sign in and comes back to this card', async () => {
    await openCard({ [WATCH_PUT]: problemResponse(unauthenticatedProblem) });
    addToWatchlist();
    expect(await within(holoRow()).findByText(SIGN_IN_LINE)).toBeTruthy();
    const link = within(holoRow()).getByRole('link', { name: 'Sign in' });
    const href = link.getAttribute('href') ?? '';
    expect(href).toBe('/sign-in?next=%2Fcard%2Fex10-%253F');
    const query = href.slice(href.indexOf('?'));
    expect(matchRoute('/sign-in', query)).toEqual({ name: 'sign-in', next: '/card/ex10-%3F' });
    expect(screen.queryByRole('alert')).toBeNull();
  });

  it('says the demo is read-only on a 403 demo-read-only', async () => {
    await openCard({ [WATCH_PUT]: problemResponse(demoReadOnlyProblem(WATCH_PUT.slice(4))) });
    addToWatchlist();
    expect(await within(holoRow()).findByText(READ_ONLY_LINE)).toBeTruthy();
    expect(screen.queryByRole('alert')).toBeNull();
  });

  it('shows the 422 for an unknown variant as the problem block', async () => {
    await openCard({
      [WATCH_PUT]: problemResponse(
        accountValidationProblem(WATCH_PUT.slice(4), {
          variant_key: ['PokéPrices has no variant with this key.'],
        }),
      ),
    });
    addToWatchlist();
    const alert = await within(holoRow()).findByRole('alert');
    expect(alert.textContent).toContain('Could not change your watchlist: check the fields below.');
    expect(alert.textContent).toContain('PokéPrices has no variant with this key.');
  });

  it('shows a 503 as the problem block with its request id', async () => {
    await openCard({ [WATCH_PUT]: problemResponse(serviceUnavailableProblem(WATCH_PUT.slice(4))) });
    addToWatchlist();
    const alert = await within(holoRow()).findByRole('alert');
    expect(alert.textContent).toContain('Could not change your watchlist: Service Unavailable');
    expect(alert.textContent).toContain('Request ID req-p4-unavailable-0010');
  });
});

describe('Add to portfolio on the card page (T07 criterion 3)', () => {
  it('fetches the portfolio once on opening and prefills the quantity held', async () => {
    const calls = await openCard({
      'GET /v1/portfolio': jsonResponse(
        portfolioBody(
          [holdingOf({ variant_key: HOLO, card_id: 'ex10-?', quantity: 7, value: '495.67' })],
          {
            total_value: '495.67',
            priced: 1,
            unpriced: 0,
            first_day: '2026-10-12',
            last_day: '2026-10-12',
          },
        ),
      ),
    });
    addToPortfolio();
    const field = await within(holoRow()).findByLabelText<HTMLInputElement>('Quantity owned');
    expect(await within(holoRow()).findByDisplayValue('7')).toBe(field);
    expect(field.getAttribute('min')).toBe('1');
    expect(field.getAttribute('max')).toBe('9999');
    expect(field.getAttribute('step')).toBe('1');
    expect(
      within(prices())
        .getByRole('button', { name: 'Add holo, unlimited to your portfolio' })
        .getAttribute('aria-expanded'),
    ).toBe('true');
    expect(calls.filter((call) => call.key === 'GET /v1/portfolio')).toHaveLength(1);
    expect(calls.map((call) => call.key)).toEqual([
      'GET /v1/prices/freshness',
      CARD,
      HISTORY,
      'GET /v1/portfolio',
    ]);
  });

  it('prefills 1 when the variant is not held, then saves and links to the portfolio', async () => {
    const calls = await openCard({
      'GET /v1/portfolio': jsonResponse(emptyPortfolioBody),
      [HOLDING_PUT]: jsonResponse({ data: { variant_key: HOLO, quantity: 4 } }),
    });
    addToPortfolio();
    const field = await within(holoRow()).findByDisplayValue<HTMLInputElement>('1');
    fireEvent.change(field, { target: { value: '4' } });
    fireEvent.click(within(holoRow()).getByRole('button', { name: 'Save' }));
    expect(await within(holoRow()).findByText('In your portfolio: 4.')).toBeTruthy();
    const link = within(holoRow()).getByRole('link', { name: 'See your portfolio' });
    expect(link.getAttribute('href')).toBe('/portfolio');
    const put = calls.filter((call) => call.key === HOLDING_PUT);
    expect(put).toHaveLength(1);
    expect(sentBody(put[0])).toEqual({ quantity: 4 });
    expect(within(holoRow()).queryByLabelText('Quantity owned')).toBeNull();
  });

  it('asks a signed-out visitor to sign in when the portfolio answers 401 on opening', async () => {
    const calls = await openCard({ 'GET /v1/portfolio': problemResponse(unauthenticatedProblem) });
    addToPortfolio();
    expect(await within(holoRow()).findByText(SIGN_IN_LINE)).toBeTruthy();
    expect(within(holoRow()).getByRole('link', { name: 'Sign in' }).getAttribute('href')).toBe(
      '/sign-in?next=%2Fcard%2Fex10-%253F',
    );
    expect(within(holoRow()).queryByLabelText('Quantity owned')).toBeNull();
    expect(calls.filter((call) => call.key.startsWith('PUT'))).toEqual([]);
  });

  it.each([
    ['401', problemResponse(unauthenticatedProblem), SIGN_IN_LINE],
    ['403', problemResponse(demoReadOnlyProblem(HOLDING_PUT.slice(4))), READ_ONLY_LINE],
  ] as const)('shows the %s result of a save', async (_status, answer, line) => {
    await openCard({
      'GET /v1/portfolio': jsonResponse(emptyPortfolioBody),
      [HOLDING_PUT]: answer,
    });
    addToPortfolio();
    await within(holoRow()).findByDisplayValue('1');
    fireEvent.click(within(holoRow()).getByRole('button', { name: 'Save' }));
    expect(await within(holoRow()).findByText(line)).toBeTruthy();
  });

  it('shows a quantity 422 under Quantity owned and keeps the form', async () => {
    await openCard({
      'GET /v1/portfolio': jsonResponse(emptyPortfolioBody),
      [HOLDING_PUT]: problemResponse(
        accountValidationProblem(HOLDING_PUT.slice(4), {
          quantity: ['The quantity field must be at least 1.'],
        }),
      ),
    });
    addToPortfolio();
    const field = await within(holoRow()).findByDisplayValue('1');
    fireEvent.change(field, { target: { value: '0' } });
    fireEvent.click(within(holoRow()).getByRole('button', { name: 'Save' }));
    const alert = await within(holoRow()).findByRole('alert');
    expect(alert.textContent).toContain('Could not change your portfolio: check the fields below.');
    const after = within(holoRow()).getByLabelText('Quantity owned');
    expect(after.getAttribute('aria-invalid')).toBe('true');
    expect(document.getElementById(after.getAttribute('aria-describedby') ?? '')?.textContent).toBe(
      'The quantity field must be at least 1.',
    );
  });

  it('shows a 422 for an unknown variant and a 503 as the problem block', async () => {
    await openCard({
      'GET /v1/portfolio': jsonResponse(emptyPortfolioBody),
      [HOLDING_PUT]: [
        problemResponse(
          accountValidationProblem(HOLDING_PUT.slice(4), {
            variant_key: ['PokéPrices has no variant with this key.'],
          }),
        ),
        problemResponse(serviceUnavailableProblem(HOLDING_PUT.slice(4))),
      ],
    });
    addToPortfolio();
    await within(holoRow()).findByDisplayValue('1');
    fireEvent.click(within(holoRow()).getByRole('button', { name: 'Save' }));
    const first = await within(holoRow()).findByRole('alert');
    expect(first.textContent).toContain('PokéPrices has no variant with this key.');
    fireEvent.click(within(holoRow()).getByRole('button', { name: 'Save' }));
    expect(
      await within(holoRow()).findByText('Could not change your portfolio: Service Unavailable'),
    ).toBeTruthy();
  });

  it('shows the problem block when the portfolio cannot be read on opening', async () => {
    await openCard({
      'GET /v1/portfolio': problemResponse(serviceUnavailableProblem('/v1/portfolio')),
    });
    addToPortfolio();
    const alert = await within(holoRow()).findByRole('alert');
    expect(alert.textContent).toContain('Could not load your portfolio: Service Unavailable');
  });

  it('closes the form when Add to portfolio is pressed again', async () => {
    await openCard({ 'GET /v1/portfolio': jsonResponse(emptyPortfolioBody) });
    addToPortfolio();
    await within(holoRow()).findByDisplayValue('1');
    addToPortfolio();
    expect(within(holoRow()).queryByLabelText('Quantity owned')).toBeNull();
  });
});

describe('card-page buttons: accessibility and honesty (T07 criterion 6)', () => {
  it.each([
    [
      'watchlist saved',
      { [WATCH_PUT]: jsonResponse({ data: { variant_key: HOLO } }) },
      'watch',
      'On your watchlist.',
    ],
    ['signed out', { [WATCH_PUT]: problemResponse(unauthenticatedProblem) }, 'watch', SIGN_IN_LINE],
    [
      'read-only',
      { [WATCH_PUT]: problemResponse(demoReadOnlyProblem('/v1/watchlist')) },
      'watch',
      READ_ONLY_LINE,
    ],
    [
      'failed',
      { [WATCH_PUT]: problemResponse(serviceUnavailableProblem('/v1/watchlist')) },
      'watch',
      /Could not change/,
    ],
    [
      'portfolio form',
      { 'GET /v1/portfolio': jsonResponse(emptyPortfolioBody) },
      'hold',
      'Quantity owned',
    ],
  ] as const)(
    '%s: dark counterparts, 44 px controls, no forbidden word',
    async (_n, routes, click, ready) => {
      vi.useFakeTimers({ toFake: ['Date'] });
      vi.setSystemTime(new Date('2031-02-03T04:05:06Z'));
      await openCard(routes);
      const main = screen.getByRole('main');
      const check = (): void => {
        expect(lightOnlyClasses(main)).toEqual([]);
        expect(shortControls(prices())).toEqual([]);
        for (const pattern of FORBIDDEN_WORDS) expect(main.textContent).not.toMatch(pattern);
        expect(main.textContent).not.toContain('2031');
        // The P2 $ carve-out still holds with the buttons and their results on the page.
        expect(main.textContent).not.toMatch(/\$(?!\d)/);
      };
      check();
      if (click === 'watch') addToWatchlist();
      else addToPortfolio();
      await within(prices()).findAllByText(ready);
      check();
    },
  );
});
