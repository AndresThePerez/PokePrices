// Edge cases of the short watchlist and portfolio rows: each priced row's accessible name holds
// its price, the source and its "as of" date exactly once for a rise, a fall, no change and no
// previous close, an unpriced row names its missing line and no "as of"; every id an
// aria-labelledby names exists once, also with two variants of one card; the list prints at most
// one visible stamp; a row whose image_small is of the wrong kind gives the problem block with no
// picture; and no forbidden word shows while loading, empty, degraded or failed.
import { cleanup, render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import App from '../App';
import {
  FORBIDDEN_WORDS,
  charizardPrice,
  clearXsrfCookie,
  emptyPortfolioBody,
  holdingOf,
  jsonResponse,
  portfolioBody,
  problemResponse,
  serverErrorProblem,
  signedInMe,
  stubAccountFetch,
  watchItemOf,
  watchlistBody,
  type AccountAnswer,
  type WatchlistItem,
} from '../test/fixtures';
import { namedRows, visibleStamps } from '../test/rows';

type ListName = 'Watchlist' | 'Portfolio';

function visit(path: string): void {
  window.history.replaceState(null, '', path);
}

afterEach(() => {
  cleanup();
  clearXsrfCookie();
  visit('/');
});

function count(haystack: string, needle: string): number {
  return haystack.split(needle).length - 1;
}

function labelledbyIds(): string[] {
  const ids: string[] = [];
  for (const element of Array.from(document.querySelectorAll('[aria-labelledby]'))) {
    for (const id of (element.getAttribute('aria-labelledby') ?? '').split(/\s+/)) {
      if (id === '') continue;
      ids.push(id);
      expect(document.getElementById(id), id).not.toBeNull();
      expect(
        Array.from(document.querySelectorAll('[id]')).filter((node) => node.id === id),
        id,
      ).toHaveLength(1);
    }
  }
  return ids;
}

/** The answer a list page gets for its GET, over `rows` (fields of a watchlist item). */
function answerFor(list: ListName, rows: readonly Partial<WatchlistItem>[]): AccountAnswer {
  if (list === 'Watchlist') return jsonResponse(watchlistBody(rows.map((r) => watchItemOf(r))));
  const holdings = rows.map((r) => holdingOf(r));
  const days = holdings.flatMap((h) => (h.price === null ? [] : [h.price.day])).sort();
  return jsonResponse(
    portfolioBody(holdings, {
      first_day: days[0] ?? null,
      last_day: days[days.length - 1] ?? null,
      priced: days.length,
      unpriced: holdings.length - days.length,
    }),
  );
}

function open(list: ListName, answer: AccountAnswer): void {
  const path = list === 'Watchlist' ? '/watchlist' : '/portfolio';
  stubAccountFetch({ 'GET /v1/me': jsonResponse(signedInMe), [`GET /v1${path}`]: answer });
  visit(path);
  render(<App />);
}

const flatPrice = {
  ...charizardPrice,
  previous_close: '968.91',
  change: '0.00',
  change_pct: '0.00',
};
const firstPrice = {
  ...charizardPrice,
  previous_close: null,
  previous_day: null,
  change: null,
  change_pct: null,
};
const fallPrice = {
  ...charizardPrice,
  close: '900.00',
  previous_close: '950.00',
  change: '-50.00',
  change_pct: '-5.26',
};

describe.each<ListName>(['Watchlist', 'Portfolio'])('row names on the %s', (list) => {
  it.each([
    ['a rise', charizardPrice, '$968.91'],
    ['a fall', fallPrice, '$900.00'],
    ['no change', flatPrice, '$968.91'],
    ['no previous close', firstPrice, '$968.91'],
  ])(
    'names %s with its price, the source and the as-of date exactly once',
    async (_case, price, shown) => {
      open(list, answerFor(list, [{ price }]));
      const rows = namedRows(await screen.findByRole('list', { name: list }));
      const [only] = rows;
      if (only === undefined) throw new Error('one row expected');
      expect(count(only.name, `Market price ${shown}`)).toBe(1);
      expect(count(only.name, 'TCGCSV')).toBe(1);
      expect(count(only.name, '2026-10-12')).toBe(1);
      expect(count(only.name, 'as of 2026-10-12 from TCGCSV (daily close)')).toBe(1);
      labelledbyIds();
      expect(visibleStamps(screen.getByRole('main'))).toHaveLength(1);
    },
  );

  it('names an unpriced row by its missing line, with no price, source or as-of', async () => {
    open(list, answerFor(list, [{ price: null }]));
    const [only] = namedRows(await screen.findByRole('list', { name: list }));
    if (only === undefined) throw new Error('one row expected');
    expect(only.name).toContain('Charizard');
    expect(only.name).not.toContain('Market price');
    expect(only.name).not.toContain('as of');
    expect(only.name).not.toContain('TCGCSV');
    labelledbyIds();
  });

  it('keeps every labelled id unique with two variants of one card and rows of two days', async () => {
    open(
      list,
      answerFor(list, [
        { variant_key: 'base1-4:holo:unlimited:-' },
        { variant_key: 'base1-4:holo:1st:-', edition: '1st' },
        {
          variant_key: 'base1-4:holo:shadowless:-',
          edition: 'shadowless',
          price: { ...charizardPrice, day: '2026-10-11', previous_day: '2026-10-10' },
        },
        { variant_key: 'base1-4:holo:unlimited:stamped', treatment: 'stamped', price: null },
      ]),
    );
    const rows = namedRows(await screen.findByRole('list', { name: list }));
    expect(rows).toHaveLength(4);
    expect(labelledbyIds().length).toBeGreaterThanOrEqual(3 * 5 + 3);
    const [first, second, third] = rows;
    expect(count(first?.name ?? '', 'as of 2026-10-12')).toBe(1);
    expect(count(second?.name ?? '', 'as of 2026-10-12')).toBe(1);
    expect(count(third?.name ?? '', 'as of 2026-10-11')).toBe(1);
    expect(count(third?.name ?? '', '2026-10-12')).toBe(0);
    expect(visibleStamps(screen.getByRole('main')).map((s) => s.textContent)).toEqual([
      'Closes from 2026-10-11 to 2026-10-12 from TCGCSV (daily close)',
    ]);
  });

  it.each([
    ['a number', 42],
    ['null', null],
  ])(
    'shows the problem block and no picture when a row image_small is %s',
    async (_case, image) => {
      open(
        list,
        answerFor(list, [
          {
            card: { name: 'Charizard', set_name: 'Base', number: '4', image_small: image },
          } as never,
        ]),
      );
      const alert = await screen.findByRole('alert');
      expect(alert.textContent).not.toBe('');
      expect(document.querySelectorAll('img')).toHaveLength(0);
      expect(screen.queryByRole('list', { name: list })).toBeNull();
    },
  );

  it.each<[string, () => AccountAnswer]>([
    [
      'empty',
      () =>
        list === 'Watchlist' ? jsonResponse(watchlistBody([])) : jsonResponse(emptyPortfolioBody),
    ],
    [
      'degraded',
      () =>
        list === 'Watchlist'
          ? jsonResponse(watchlistBody([watchItemOf({ card: null })], { degraded: true }))
          : jsonResponse(portfolioBody([holdingOf({ card: null })], { degraded: true })),
    ],
    [
      'failed',
      () =>
        problemResponse(
          serverErrorProblem(list === 'Watchlist' ? '/v1/watchlist' : '/v1/portfolio'),
        ),
    ],
  ])('never shows a forbidden word, and at most one stamp, when %s', async (_case, reply) => {
    open(list, reply());
    const loading = document.body.textContent;
    for (const word of FORBIDDEN_WORDS) expect(loading).not.toMatch(word);
    await waitFor(() => {
      expect(screen.queryByRole('status')).toBeNull();
    });
    const text = document.body.textContent;
    for (const word of FORBIDDEN_WORDS) expect(text).not.toMatch(word);
    expect(visibleStamps(screen.getByRole('main')).length).toBeLessThanOrEqual(1);
  });
});
