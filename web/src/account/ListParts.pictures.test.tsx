// The watchlist and the portfolio with card pictures and short rows: each row shows the card's
// small image (48 px, lazy, explicit width and height) or the card-back tile, and the price with
// the change but without "vs previous close (day)" or its own "as of" line. The row's accessible
// name keeps the full sentence with its own source and "as of"; each list prints its stamp once.
import { cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import App from '../App';
import {
  FORBIDDEN_WORDS,
  chimechoPrice,
  clearXsrfCookie,
  holdingOf,
  jsonResponse,
  portfolioBody,
  signedInMe,
  stubAccountFetch,
  watchItemOf,
  watchlistBody,
  watchlistItems,
} from '../test/fixtures';
import { namedRows, visibleStamps, visibleText } from '../test/rows';

const IMAGE = 'https://images.example.com/base1-4.png';
const CHARIZARD = { name: 'Charizard', set_name: 'Base', number: '4' };
const STAMP_1012 = 'as of 2026-10-12 from TCGCSV (daily close)';
const SPAN = 'Closes from 2026-10-11 to 2026-10-12 from TCGCSV (daily close)';

function visit(path: string): void {
  window.history.replaceState(null, '', path);
}

afterEach(() => {
  cleanup();
  clearXsrfCookie();
  visit('/');
});

type ListName = 'Watchlist' | 'Portfolio';

/** Opens /watchlist or /portfolio signed in, over rows built by `row` from item fields. */
async function open(list: ListName, rows: readonly Parameters<typeof watchItemOf>[0][]) {
  if (list === 'Watchlist') {
    stubAccountFetch({
      'GET /v1/me': jsonResponse(signedInMe),
      'GET /v1/watchlist': jsonResponse(watchlistBody(rows.map((fields) => watchItemOf(fields)))),
    });
    visit('/watchlist');
  } else {
    const holdings = rows.map((fields) => holdingOf(fields));
    const days = holdings.flatMap((holding) => (holding.price === null ? [] : [holding.price.day]));
    stubAccountFetch({
      'GET /v1/me': jsonResponse(signedInMe),
      'GET /v1/portfolio': jsonResponse(
        portfolioBody(holdings, {
          first_day: [...days].sort()[0] ?? null,
          last_day: [...days].sort().reverse()[0] ?? null,
        }),
      ),
    });
    visit('/portfolio');
  }
  render(<App />);
  return screen.findByRole('list', { name: list });
}

const pictured = { card: { ...CHARIZARD, image_small: IMAGE } };
const chimechoRow = {
  variant_key: 'ex5-17:reverse:-:-',
  card_id: 'ex5-17',
  finish: 'reverse',
  edition: '-',
  card: { name: 'Chimecho', set_name: 'Hidden Legends', number: '17' },
  price: chimechoPrice,
};

describe.each<ListName>(['Watchlist', 'Portfolio'])('card pictures on the %s', (name) => {
  it('shows the small image at a fixed 48 px size, lazy, with explicit width and height', async () => {
    const list = await open(name, [pictured]);
    const img = list.querySelector('img');
    if (img === null) throw new Error('an image expected');
    expect(img.getAttribute('src')).toBe(IMAGE);
    expect(img.getAttribute('loading')).toBe('lazy');
    expect(img.getAttribute('width')).toBe('63');
    expect(img.getAttribute('height')).toBe('88');
    expect(img.getAttribute('alt')).toBe('');
    expect(img.className.split(/\s+/)).toEqual(expect.arrayContaining(['w-12', 'shrink-0']));
  });

  it('swaps a picture that fails to load for the card-back tile, and draws the tile from the start without an image', async () => {
    const list = await open(name, [pictured, chimechoRow]);
    const img = list.querySelector('img');
    if (img === null) throw new Error('an image expected');
    expect(list.querySelectorAll('[data-initial]')).toHaveLength(1);
    fireEvent.error(img);
    expect(list.querySelector('img')).toBeNull();
    const initials = Array.from(list.querySelectorAll('[data-initial]')).map((tile) =>
      tile.getAttribute('data-initial'),
    );
    expect(initials).toEqual(['C', 'C']);
  });

  it('keeps the full price sentence with its own "as of" in the row name; the row shows the short form', async () => {
    const list = await open(name, [pictured]);
    const [only] = namedRows(list);
    if (only === undefined) throw new Error('one row expected');
    expect(only.name).toContain('Charizard');
    expect(only.name).toContain('Base No. 4 · Holo, unlimited');
    expect(only.name).toContain('Market price $968.91');
    expect(only.name).toContain('▲ +$18.91 (+1.99%) vs previous close (2026-10-11)');
    expect(only.name).toContain(STAMP_1012);
    const seen = visibleText(only.row);
    expect(seen).toContain('$968.91');
    expect(seen).toContain('▲ +1.99%');
    expect(seen).not.toContain('vs previous close');
    expect(seen).not.toContain('Market price');
    expect(seen).not.toContain('as of');
    const chip = within(only.row).getByText('▲ +1.99%');
    expect(chip.className.split(/\s+/)).toContain('text-up');
    for (const word of FORBIDDEN_WORDS)
      expect(screen.getByRole('main').textContent).not.toMatch(word);
  });

  it('prints the stamp once for rows of one day', async () => {
    await open(name, [pictured, { variant_key: 'base1-4:holo:1st:-', edition: '1st' }]);
    expect(visibleStamps(screen.getByRole('main')).map((stamp) => stamp.textContent)).toEqual([
      STAMP_1012,
    ]);
  });

  it('prints the stamp once for rows of several days, and each row its own day', async () => {
    const list = await open(name, [pictured, chimechoRow]);
    expect(visibleStamps(screen.getByRole('main')).map((stamp) => stamp.textContent)).toEqual([
      SPAN,
    ]);
    const [charizard, chimecho] = namedRows(list);
    expect(visibleText(charizard?.row ?? list)).toContain('Close of 2026-10-12');
    expect(visibleText(chimecho?.row ?? list)).toContain('Close of 2026-10-11');
    expect(chimecho?.name).toContain('as of 2026-10-11 from TCGCSV (daily close)');
    expect(chimecho?.name).toContain('▼ -$0.66 (-2.44%) vs previous close (2026-10-10)');
  });
});

describe('the watchlist stamp', () => {
  it('prints no stamp when no row has a price', async () => {
    await open('Watchlist', [{ price: null }]);
    expect(visibleStamps(screen.getByRole('main'))).toEqual([]);
    expect(screen.getByRole('main').textContent).not.toContain('as of');
  });

  it("prints the demo's three rows with one stamp", async () => {
    stubAccountFetch({
      'GET /v1/me': jsonResponse(signedInMe),
      'GET /v1/watchlist': jsonResponse(watchlistBody(watchlistItems)),
    });
    visit('/watchlist');
    render(<App />);
    await screen.findByRole('list', { name: 'Watchlist' });
    expect(visibleStamps(screen.getByRole('main'))).toHaveLength(1);
  });
});
