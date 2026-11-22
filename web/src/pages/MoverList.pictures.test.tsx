// The movers board and the home strip with card pictures and short rows: each row shows the
// card's small image (48 px, lazy, explicit width and height) or the card-back tile, the price and
// the change without "vs previous close (day)", while its accessible name keeps the full sentence
// with the list's source and "as of" stamp, which each list prints once.
import { cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import App from '../App';
import {
  FORBIDDEN_WORDS,
  fallingMover,
  isMoversUrl,
  jsonResponse,
  moverOf,
  moversBody,
  risingMover,
  stubFetch,
  type MoversBody,
} from '../test/fixtures';
import { namedRows, visibleStamps, visibleText } from '../test/rows';

const IMAGE = 'https://images.example.com/ex5-17.png';
const STAMP_1012 = 'as of 2026-10-12 from TCGCSV (daily close)';
const STAMP_1011 = 'as of 2026-10-11 from TCGCSV (daily close)';

const pictured = moverOf({
  card: { name: 'Chimecho', set_name: 'Hidden Legends', number: '17', image_small: IMAGE },
});

function visit(path: string): void {
  window.history.replaceState(null, '', path);
}

function open(path: string, up: MoversBody, down: MoversBody = moversBody('down', [])): void {
  stubFetch((url) => {
    if (!isMoversUrl(url)) return new Error('no request expected');
    return jsonResponse(url.includes('direction=down') ? down : up);
  });
  visit(path);
  render(<App />);
}

function strip(): HTMLElement {
  return screen.getByRole('region', { name: 'Biggest moves vs previous close' });
}

beforeEach(() => {
  vi.useFakeTimers({ toFake: ['Date'] });
  vi.setSystemTime(new Date('2031-02-03T04:05:06Z'));
});

afterEach(() => {
  cleanup();
  vi.useRealTimers();
  visit('/');
});

describe.each([
  ['the board', '/movers', () => screen.getByRole('main')],
  ['the home strip', '/', strip],
])('card pictures on %s', (_where, path, scope) => {
  it('shows the small image at a fixed 48 px size, lazy, with explicit width and height', async () => {
    open(path, moversBody('up', [pictured]));
    const list = await within(document.body).findByRole('list', { name: 'Rises' });
    const [row] = within(list).getAllByRole('listitem');
    if (row === undefined) throw new Error('one row expected');
    const img = row.querySelector('img');
    if (img === null) throw new Error('an image expected');
    expect(img.getAttribute('src')).toBe(IMAGE);
    expect(img.getAttribute('loading')).toBe('lazy');
    expect(img.getAttribute('width')).toBe('63');
    expect(img.getAttribute('height')).toBe('88');
    expect(img.getAttribute('alt')).toBe('');
    expect(img.className.split(/\s+/)).toEqual(expect.arrayContaining(['w-12', 'shrink-0']));
    // The picture sits between the rank and the name.
    const rank = within(row).getByText('#1');
    const link = within(row).getByRole('link', { name: 'Chimecho' });
    expect(rank.compareDocumentPosition(img) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
    expect(img.compareDocumentPosition(link) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
    expect(scope().querySelectorAll('img')).toHaveLength(1);
  });

  it('swaps a picture that fails to load for the card-back tile', async () => {
    open(path, moversBody('up', [pictured]));
    const list = await within(document.body).findByRole('list', { name: 'Rises' });
    const img = list.querySelector('img');
    if (img === null) throw new Error('an image expected');
    fireEvent.error(img);
    expect(list.querySelector('img')).toBeNull();
    const tile = list.querySelector('[data-initial]');
    expect(tile?.getAttribute('data-initial')).toBe('C');
    expect(tile?.className.split(/\s+/)).toEqual(expect.arrayContaining(['w-12', 'shrink-0']));
  });

  it('shows the card-back tile from the start for a row with no image_small', async () => {
    open(
      path,
      moversBody('up', [
        risingMover,
        moverOf({ card_id: 'zz-1', card: null, variant_key: 'zz-1:holo:-:-' }),
      ]),
    );
    const list = await within(document.body).findByRole('list', { name: 'Rises' });
    expect(list.querySelectorAll('img')).toHaveLength(0);
    const initials = Array.from(list.querySelectorAll('[data-initial]')).map((tile) =>
      tile.getAttribute('data-initial'),
    );
    expect(initials).toEqual(['C', '']);
  });

  it('keeps the full price sentence and the stamp in the row name while the row shows the short form', async () => {
    open(path, moversBody('up', [pictured]), moversBody('down', [fallingMover]));
    const list = await within(document.body).findByRole('list', { name: 'Rises' });
    const [only] = namedRows(list);
    if (only === undefined) throw new Error('one row expected');
    expect(only.name).toContain('Chimecho');
    expect(only.name).toContain('Hidden Legends No. 17 · Reverse');
    expect(only.name).toContain('Market price $26.34');
    expect(only.name).toContain('▲ +$20.63 (+361.30%) vs previous close (2026-10-11)');
    expect(only.name).toContain(STAMP_1012);
    const seen = visibleText(only.row);
    expect(seen).toContain('$26.34');
    expect(seen).toContain('▲ +361.30%');
    expect(seen).not.toContain('vs previous close');
    expect(seen).not.toContain('Market price');
    expect(seen).not.toContain('as of');
    const chip = within(only.row).getByText('▲ +361.30%');
    expect(chip.className.split(/\s+/)).toContain('text-up');
    for (const word of FORBIDDEN_WORDS) expect(scope().textContent).not.toMatch(word);
  });

  it('prints the source and "as of" stamp once', async () => {
    const second = moverOf({ rank: 2, variant_key: 'ex5-17:holo:-:-', finish: 'holo' });
    open(path, moversBody('up', [pictured, second]), moversBody('down', [fallingMover]));
    await within(document.body).findByRole('list', { name: 'Rises' });
    expect(visibleStamps(scope()).map((stamp) => stamp.textContent)).toEqual([STAMP_1012]);
  });
});

describe('the home strip on two days', () => {
  it('names each row with its own sub-list stamp and prints each stamp once', async () => {
    open(
      '/',
      moversBody('up', [pictured]),
      moversBody('down', [fallingMover], { day: '2026-10-11' }),
    );
    const rises = await within(strip()).findByRole('list', { name: 'Rises' });
    const falls = within(strip()).getByRole('list', { name: 'Falls' });
    expect(namedRows(rises)[0]?.name).toContain(STAMP_1012);
    expect(namedRows(falls)[0]?.name).toContain(STAMP_1011);
    expect(namedRows(falls)[0]?.name).not.toContain(STAMP_1012);
    expect(visibleStamps(strip()).map((stamp) => stamp.textContent)).toEqual([
      STAMP_1012,
      STAMP_1011,
    ]);
  });
});
