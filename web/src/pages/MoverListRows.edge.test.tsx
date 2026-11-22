// Edge cases of the short mover rows with card pictures, on the board and the home strip: each
// row's accessible name holds its price, the source and the "as of" date exactly once for a rise,
// a fall, no change and a change without a percent; every id an aria-labelledby names exists once
// in the document, also when one card is in both sub-lists; the stamp is printed once for zero,
// one and many rows; a row whose image_small is of the wrong kind gives the failure block, never a
// picture; and an unusual image string reaches a mover row's img exactly as it reaches a search
// result's img, so the lists are never weaker than the search page.
import { cleanup, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import App from '../App';
import {
  fallingMover,
  isMoversUrl,
  jsonResponse,
  moverOf,
  moversBody,
  risingMover,
  searchPage,
  stubFetch,
  type Mover,
  type MoversBody,
} from '../test/fixtures';
import { namedRows, visibleStamps } from '../test/rows';

const STAMP = 'as of 2026-10-12 from TCGCSV (daily close)';

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

function count(haystack: string, needle: string): number {
  return haystack.split(needle).length - 1;
}

/** Every id an aria-labelledby names in the document, each checked to exist exactly once. */
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

beforeEach(() => {
  vi.useFakeTimers({ toFake: ['Date'] });
  vi.setSystemTime(new Date('2031-02-03T04:05:06Z'));
});

afterEach(() => {
  cleanup();
  vi.useRealTimers();
  visit('/');
});

const flat = moverOf({
  rank: 2,
  variant_key: 'ex5-17:holo:-:-',
  finish: 'holo',
  close: '5.71',
  change: '0.00',
  change_pct: '0.00',
});
const third = moverOf({
  rank: 3,
  variant_key: 'ex5-17:normal:-:-',
  finish: 'normal',
  close: '1234.00',
  previous_close: '1000.00',
  change: '234.00',
  change_pct: '23.40',
});

describe.each([
  ['the board', '/movers'],
  ['the home strip', '/'],
])('row names on %s', (_where, path) => {
  it.each<[string, Mover, string]>([
    ['a rise', risingMover, '$26.34'],
    ['a fall', fallingMover, '$65.11'],
    ['no change', flat, '$5.71'],
  ])(
    'names %s with its price, the source and the as-of date exactly once',
    async (_case, mover, price) => {
      open(path, moversBody('up', [mover]));
      const list = await within(document.body).findByRole('list', { name: 'Rises' });
      const [only] = namedRows(list);
      if (only === undefined) throw new Error('one row expected');
      expect(count(only.name, `Market price ${price}`)).toBe(1);
      expect(count(only.name, 'TCGCSV')).toBe(1);
      expect(count(only.name, '2026-10-12')).toBe(1);
      expect(count(only.name, STAMP)).toBe(1);
      labelledbyIds();
    },
  );

  it('prints the stamp once for zero, one and many rows', async () => {
    for (const rows of [[], [risingMover], [risingMover, flat, third]]) {
      open(path, moversBody('up', rows), moversBody('down', rows.length > 0 ? [fallingMover] : []));
      await waitFor(() => {
        expect(screen.queryByRole('status')).toBeNull();
      });
      const scope = path === '/' ? strip() : screen.getByRole('main');
      expect(
        visibleStamps(scope).map((stamp) => stamp.textContent),
        `${String(rows.length)} rows`,
      ).toEqual([STAMP]);
      cleanup();
    }
  });
});

describe('row names on the home strip with one card in both sub-lists', () => {
  it('gives every labelled row ids that exist once, and names each row with the one shared stamp', async () => {
    const same = moverOf({ rank: 1 });
    open('/', moversBody('up', [same, flat]), moversBody('down', [same, fallingMover]));
    const rises = await within(strip()).findByRole('list', { name: 'Rises' });
    const falls = within(strip()).getByRole('list', { name: 'Falls' });
    const ids = labelledbyIds();
    expect(ids.length).toBeGreaterThanOrEqual(4 * 5);
    for (const row of [...namedRows(rises), ...namedRows(falls)]) {
      expect(count(row.name, STAMP)).toBe(1);
      expect(count(row.name, 'Market price')).toBe(1);
    }
    expect(visibleStamps(strip())).toHaveLength(1);
  });
});

describe('a mover whose image_small is of the wrong kind', () => {
  it.each([
    ['a number', 42],
    ['null', null],
    ['an object', { url: 'https://images.example.com/x.png' }],
  ])('shows the failure block with no picture and no row when it is %s', async (_case, image) => {
    const bad = moverOf({
      card: { name: 'Chimecho', set_name: 'Hidden Legends', number: '17', image_small: image },
    } as never);
    open('/movers', moversBody('up', [bad]));
    const alert = await screen.findByRole('alert');
    expect(alert.textContent).toContain('Could not load the movers');
    expect(document.querySelectorAll('img')).toHaveLength(0);
    expect(screen.queryByRole('list', { name: 'Rises' })).toBeNull();
  });
});

describe('an unusual image string on a mover row and on a search result', () => {
  it.each([
    ['plain http', 'http://images.example.com/x.png'],
    ['protocol-relative', '//images.example.net/x.png'],
    ['a foreign host', 'https://tracker.example.org/pixel.png?u=1'],
    ['a data URL', 'data:image/png;base64,AAAA'],
    ['a javascript URL', 'javascript:void(0)'],
    ['inner spaces', 'https://images.example.com/a b.png'],
    ['html', '"><img src=x onerror=alert(1)>'],
  ])('puts the same src on both for %s, and no second image', async (_case, image) => {
    const hit = searchPage.data[2];
    if (hit === undefined) throw new Error('a search hit expected');
    stubFetch(() =>
      jsonResponse({
        data: [{ ...hit, image_small: image }],
        meta: { ...searchPage.meta, total: 1 },
      }),
    );
    visit('/search?q=sprigatito');
    render(<App />);
    const name = await screen.findByText(hit.name);
    const result = name.closest('a');
    if (result === null) throw new Error('a result link expected');
    const searched = result.querySelector('img');
    expect(searched).not.toBeNull();
    const searchedSrc = searched?.getAttribute('src') ?? null;
    cleanup();

    open(
      '/movers',
      moversBody('up', [
        moverOf({
          card: { name: 'Chimecho', set_name: 'Hidden Legends', number: '17', image_small: image },
        }),
      ]),
    );
    const list = await within(document.body).findByRole('list', { name: 'Rises' });
    const imgs = list.querySelectorAll('img');
    expect(imgs).toHaveLength(1);
    expect(imgs[0]?.getAttribute('src') ?? null).toBe(searchedSrc);
    expect(imgs[0]?.getAttribute('alt')).toBe('');
    expect(imgs[0]?.getAttribute('loading')).toBe('lazy');
    expect(document.querySelectorAll('[onerror]')).toHaveLength(0);
    expect(document.querySelectorAll('script')).toHaveLength(0);
  });
});
