// The watchlist and the portfolio say, in visible text and once per list, that each row's change
// is against the previous close: the short row's chip is only an arrow and a percent, so the base
// is named beside the list's one stamp, for every kind of session (the demo, an unverified and a
// verified account). It is not a second stamp, and the portfolio's line cannot read as a gain on
// the holding.
import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import App from '../App';
import {
  FORBIDDEN_WORDS,
  chimechoPrice,
  clearXsrfCookie,
  demoMe,
  holdingOf,
  jsonResponse,
  portfolioBody,
  signedInMe,
  stubAccountFetch,
  unverifiedMe,
  watchItemOf,
  watchlistBody,
} from '../test/fixtures';
import { visibleStamps, visibleText } from '../test/rows';

const WATCH_BASE = 'Change vs previous close.';
const HOLD_BASE = "Change is each card's close vs its previous close, not a gain on your holding.";

function visit(path: string): void {
  window.history.replaceState(null, '', path);
}

afterEach(() => {
  cleanup();
  clearXsrfCookie();
  visit('/');
});

const chimecho = {
  variant_key: 'ex5-17:reverse:-:-',
  card_id: 'ex5-17',
  finish: 'reverse',
  edition: '-',
  card: { name: 'Chimecho', set_name: 'Hidden Legends', number: '17' },
  price: chimechoPrice,
};

type Fields = Parameters<typeof watchItemOf>[0];

const SESSIONS = [
  ['the demo', demoMe],
  ['an unverified account', unverifiedMe],
  ['a verified account', signedInMe],
] as const;

const ROWS: readonly (readonly [string, readonly Fields[]])[] = [
  ['rows of one day', [{}]],
  ['rows of several days', [{}, chimecho]],
];

async function openWatchlist(me: unknown, rows: readonly Fields[]): Promise<HTMLElement> {
  stubAccountFetch({
    'GET /v1/me': jsonResponse(me),
    'GET /v1/watchlist': jsonResponse(watchlistBody(rows.map((fields) => watchItemOf(fields)))),
  });
  visit('/watchlist');
  render(<App />);
  if (rows.length > 0) await screen.findByRole('list', { name: 'Watchlist' });
  else await screen.findByText(/Your watchlist is empty/);
  return screen.getByRole('main');
}

async function openPortfolio(me: unknown, rows: readonly Fields[]): Promise<HTMLElement> {
  const holdings = rows.map((fields) => holdingOf(fields));
  const days = holdings.flatMap((holding) => (holding.price === null ? [] : [holding.price.day]));
  stubAccountFetch({
    'GET /v1/me': jsonResponse(me),
    'GET /v1/portfolio': jsonResponse(
      portfolioBody(holdings, {
        first_day: [...days].sort()[0] ?? null,
        last_day: [...days].sort().reverse()[0] ?? null,
      }),
    ),
  });
  visit('/portfolio');
  render(<App />);
  if (rows.length > 0) await screen.findByRole('list', { name: 'Portfolio' });
  else await screen.findByText(/Your portfolio is empty/);
  return screen.getByRole('main');
}

function count(text: string, part: string): number {
  return text.split(part).length - 1;
}

const LISTS = [
  ['watchlist', openWatchlist, WATCH_BASE],
  ['portfolio', openPortfolio, HOLD_BASE],
] as const;

describe.each(LISTS)('the %s names the base of the change', (_list, open, base) => {
  for (const [session, me] of SESSIONS) {
    it.each(ROWS)(`once, in visible text, for ${session} with %s`, async (_rows, rows) => {
      const main = await open(me, rows);
      expect(count(visibleText(main), base)).toBe(1);
      expect(visibleStamps(main)).toHaveLength(1);
      for (const word of FORBIDDEN_WORDS) expect(main.textContent).not.toMatch(word);
    });
  }

  it('follows the stamp: none for an empty list', async () => {
    const main = await open(signedInMe, []);
    expect(visibleStamps(main)).toHaveLength(0);
    expect(visibleText(main)).not.toContain(base);
  });

  it('follows the stamp: none when no row has a price', async () => {
    const main = await open(signedInMe, [{ price: null }]);
    expect(visibleStamps(main)).toHaveLength(0);
    expect(visibleText(main)).not.toContain(base);
  });
});
