import { cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import App from '../App';
import {
  type AccountAnswer,
  clearXsrfCookie,
  deoxysCard,
  deoxysCardBody,
  historyFor,
  holdingOf,
  jsonResponse,
  portfolioBody,
  signedInMe,
  stubAccountFetch,
  watchItemOf,
  watchlistBody,
  watchlistItems,
  workedHoldings,
} from '../test/fixtures';

vi.mock('lightweight-charts', () => import('../test/lightweightChartsFake'));

const REVERSE_LINK = '/card/ex13-3?variant=ex13-3%3Areverse%3A-%3A-';
const deoxysRow = {
  variant_key: 'ex13-3:reverse:-:-',
  card_id: 'ex13-3',
  finish: 'reverse',
  edition: '-',
  treatment: '-',
  card: { name: 'Deoxys δ', set_name: 'Holon Phantoms', number: '3' },
  price: deoxysCard.variants.find((variant) => variant.finish === 'reverse')?.price ?? null,
};

function visit(path: string): void {
  window.history.replaceState(null, '', path);
}

afterEach(() => {
  cleanup();
  clearXsrfCookie();
  visit('/');
});

/** The routes a signed-in visitor's list page and the card page it opens ask for. */
function routes(
  extra: Readonly<Record<string, AccountAnswer>>,
): Readonly<Record<string, AccountAnswer>> {
  return {
    'GET /v1/me': () => jsonResponse(signedInMe),
    'GET /v1/cards/ex13-3': () => jsonResponse(deoxysCardBody),
    'GET /v1/cards/ex13-3/prices?range=1M': () => jsonResponse(historyFor(deoxysCardBody)),
    ...extra,
  };
}

function hrefs(list: HTMLElement): (string | null)[] {
  return within(list)
    .getAllByRole('listitem')
    .map((row) => within(row).getAllByRole('link')[0]?.getAttribute('href') ?? null);
}

async function opensReverse(list: HTMLElement): Promise<void> {
  fireEvent.click(within(list).getByRole('link', { name: 'Deoxys δ' }));
  expect(window.location.pathname + window.location.search).toBe(REVERSE_LINK);
  const label = await screen.findByText(/^Market price · /);
  expect(label.textContent).toBe('Market price · reverse');
  const block = label.parentElement;
  if (block === null) throw new Error('the hero label has no block');
  expect(within(block).getByText('$752.00')).toBeTruthy();
}

describe('watchlist and portfolio rows link to their own variant', () => {
  it('gives each watchlist row the link of its variant, two editions of one card apart', async () => {
    const items = [...watchlistItems, watchItemOf({ ...deoxysRow })];
    stubAccountFetch(
      routes({
        'GET /v1/watchlist': () => jsonResponse(watchlistBody(items)),
        'GET /v1/portfolio': () => jsonResponse(portfolioBody([])),
      }),
    );
    visit('/watchlist');
    render(<App />);
    const list = await screen.findByRole('list', { name: 'Watchlist' });
    expect(hrefs(list)).toEqual([
      '/card/base1-4?variant=base1-4%3Aholo%3A1st%3A-',
      '/card/base1-4?variant=base1-4%3Aholo%3Aunlimited%3A-',
      '/card/ex5-17?variant=ex5-17%3Areverse%3A-%3A-',
      REVERSE_LINK,
    ]);
    await opensReverse(list);
  });

  it('gives each portfolio row the link of its variant and opens that variant', async () => {
    const holdings = [...workedHoldings, holdingOf({ ...deoxysRow, quantity: 1, value: '752.00' })];
    stubAccountFetch(
      routes({
        'GET /v1/portfolio': () => jsonResponse(portfolioBody(holdings)),
        'GET /v1/watchlist': () => jsonResponse(watchlistBody([])),
      }),
    );
    visit('/portfolio');
    render(<App />);
    const list = await screen.findByRole('list', { name: 'Portfolio' });
    expect(hrefs(list)).toEqual([
      '/card/base1-4?variant=base1-4%3Aholo%3A1st%3A-',
      '/card/base1-4?variant=base1-4%3Aholo%3Aunlimited%3A-',
      '/card/ex5-17?variant=ex5-17%3Areverse%3A-%3A-',
      REVERSE_LINK,
    ]);
    await opensReverse(list);
  });
});
