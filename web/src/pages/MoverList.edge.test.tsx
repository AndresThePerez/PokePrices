import { act, cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import App from '../App';
import { matchRoute } from '../router';
import {
  FORBIDDEN_WORDS,
  fallingMover,
  isMoversUrl,
  jsonResponse,
  moverOf,
  moversBody,
  problemResponse,
  risingMover,
  stubFetch,
  upstreamUnavailableProblem,
} from '../test/fixtures';

const SCOPE =
  'Ranked by change vs previous close among variants whose previous close was at least $2.00.';
const STAMP_1011 = 'as of 2026-10-11 from TCGCSV (daily close)';
const STAMP_1012 = 'as of 2026-10-12 from TCGCSV (daily close)';
const STAMP_1013 = 'as of 2026-10-13 from TCGCSV (daily close)';
const DEGRADED = 'Catalog data may be out of date: PokéSearch is unavailable';

type Reply = () => Response | Error;

const newDay = { day: '2026-10-13', as_of: '2026-10-13T20:05:44Z' };
const nextDayFall = moverOf({
  card_id: 'base1-2',
  card: { name: 'Blastoise', set_name: 'Base', number: '2' },
  variant_key: 'base1-2:holo:unlimited:-',
  finish: 'holo',
  edition: 'unlimited',
  close: '300.00',
  previous_close: '350.00',
  change: '-50.00',
  change_pct: '-14.29',
});

async function settle(): Promise<void> {
  for (let round = 0; round < 3; round += 1) {
    await act(async () => {
      await new Promise((resolve) => {
        setTimeout(resolve, 0);
      });
    });
  }
}

async function advance(ms: number): Promise<void> {
  await act(async () => {
    await vi.advanceTimersByTimeAsync(ms);
  });
  await settle();
}

function visit(path: string): void {
  window.history.replaceState(null, '', path);
}

/** Opens `path`; each direction is answered by its next reply (the last one repeats). */
async function open(path: string, up: readonly Reply[], down: readonly Reply[]): Promise<string[]> {
  const urls: string[] = [];
  let ups = 0;
  let downs = 0;
  stubFetch((url) => {
    urls.push(url);
    if (!isMoversUrl(url)) return new Error('no request expected');
    const isDown = url.includes('direction=down');
    const replies = isDown ? down : up;
    const index = isDown ? downs++ : ups++;
    const reply = replies[Math.min(index, replies.length - 1)];
    if (reply === undefined) throw new Error('no reply configured');
    return reply();
  });
  visit(path);
  render(<App />);
  await settle();
  return urls;
}

function strip(): HTMLElement {
  return screen.getByRole('region', { name: 'Biggest moves vs previous close' });
}

function sublist(name: 'Rises' | 'Falls'): HTMLElement {
  const heading = within(strip()).getByRole('heading', { level: 3, name });
  const block = heading.parentElement;
  if (block === null) throw new Error('h3 without a parent');
  return block;
}

function pressed(): string[] {
  return within(screen.getByRole('group', { name: 'Direction' }))
    .getAllByRole('button')
    .filter((button) => button.getAttribute('aria-pressed') === 'true')
    .map((button) => button.textContent);
}

beforeEach(() => {
  vi.useFakeTimers({ toFake: ['setInterval', 'clearInterval', 'Date'] });
  vi.setSystemTime(new Date('2031-02-03T04:05:06Z'));
});

afterEach(() => {
  cleanup();
  vi.useRealTimers();
  vi.unstubAllGlobals();
  visit('/');
});

describe('movers route edge cases (T09 criterion 1)', () => {
  it('reads only an exact, lower-case direction=down and never matches other paths', () => {
    expect(matchRoute('/movers', '?direction=%64own')).toEqual({
      name: 'movers',
      direction: 'down',
    });
    for (const search of [
      '?Direction=down',
      '?direction=UP',
      '?direction=down%00',
      '?direction= down',
    ]) {
      expect(matchRoute('/movers', search)).toEqual({ name: 'movers', direction: 'up' });
    }
    for (const path of ['/Movers', '/movers//', '/movers.', '/mover', '/movers/down/']) {
      expect(matchRoute(path, '')).toEqual({ name: 'not-found' });
    }
  });
});

describe('home strip stamp rule edge cases (F-P3-05)', () => {
  it('invents no stamp, no list and no See all movers while one direction is still loading', async () => {
    vi.stubGlobal('fetch', (input: string | URL): Promise<Response> => {
      const url = String(input);
      return url.includes('direction=up')
        ? Promise.resolve(jsonResponse(moversBody('up', [risingMover], { limit: 5 })))
        : new Promise<Response>(() => undefined);
    });
    visit('/');
    render(<App />);
    await settle();
    const section = strip();
    expect(within(section).getByRole('status').textContent).toBe('Loading movers…');
    expect(section.textContent).not.toContain('as of');
    expect(within(section).queryByRole('list')).toBeNull();
    expect(within(section).queryByRole('link', { name: 'See all movers' })).toBeNull();
  });

  it('shows one stamp, two empty states and See all movers on a day that ranked nothing', async () => {
    await open(
      '/',
      [() => jsonResponse(moversBody('up', [], { limit: 5 }))],
      [() => jsonResponse(moversBody('down', [], { limit: 5 }))],
    );
    const section = strip();
    expect(within(section).getAllByText(STAMP_1012)).toHaveLength(1);
    expect(within(sublist('Rises')).getByText('No rises ranked for 2026-10-12')).toBeTruthy();
    expect(within(sublist('Falls')).getByText('No falls ranked for 2026-10-12')).toBeTruthy();
    expect(within(section).queryByRole('list')).toBeNull();
    expect(
      within(section).queryByText('TCGplayer market prices via TCGCSV, collected once a day.'),
    ).toBeNull();
    expect(within(section).getByRole('link', { name: 'See all movers' })).toBeTruthy();
  });

  it('flips to two stamps when only the falls poll lands a new day, leaving the rise rows untouched, then back to one', async () => {
    const urls = await open(
      '/',
      [
        () => jsonResponse(moversBody('up', [risingMover], { limit: 5 })),
        () => jsonResponse(moversBody('up', [risingMover], { limit: 5 })),
        () => jsonResponse(moversBody('up', [risingMover], { limit: 5, ...newDay })),
      ],
      [
        () => jsonResponse(moversBody('down', [fallingMover], { limit: 5 })),
        () => jsonResponse(moversBody('down', [nextDayFall], { limit: 5, ...newDay })),
      ],
    );
    expect(urls).toHaveLength(2);
    expect(within(strip()).getAllByText(STAMP_1012)).toHaveLength(1);
    const riseRow = within(sublist('Rises')).getByRole('listitem');

    await advance(300_000);
    const section = strip();
    expect(within(section).getAllByText(/^as of /)).toHaveLength(2);
    expect(within(sublist('Rises')).getByText(STAMP_1012)).toBeTruthy();
    expect(within(sublist('Falls')).getByText(STAMP_1013)).toBeTruthy();
    expect(within(sublist('Falls')).getByRole('link', { name: 'Blastoise' })).toBeTruthy();
    expect(within(sublist('Rises')).getByRole('listitem')).toBe(riseRow);
    expect(within(section).getAllByText(SCOPE)).toHaveLength(1);

    await advance(300_000);
    expect(within(strip()).getAllByText(/^as of /)).toHaveLength(1);
    expect(within(strip()).getAllByText(STAMP_1013)).toHaveLength(1);
    expect(within(sublist('Rises')).queryByText(STAMP_1013)).toBeNull();
  });

  it('puts the stamp only on the sub-list that has a day when the other has no movers yet', async () => {
    await open(
      '/',
      [() => jsonResponse(moversBody('up', [], { day: null, as_of: null }))],
      [() => jsonResponse(moversBody('down', [fallingMover], { limit: 5 }))],
    );
    expect(within(sublist('Rises')).getByText('No movers yet')).toBeTruthy();
    expect(within(sublist('Rises')).queryByText(/^as of /)).toBeNull();
    expect(within(sublist('Falls')).getByText(STAMP_1012)).toBeTruthy();
    expect(within(strip()).getAllByText(/^as of /)).toHaveLength(1);
  });

  it('shows the mixed-degraded line once and keeps D14 and D1 clean across a two-stamp strip', async () => {
    await open(
      '/',
      [() => jsonResponse(moversBody('up', [moverOf({ card: null })], { degraded: true }))],
      [
        () =>
          jsonResponse(
            moversBody('down', [fallingMover], {
              day: '2026-10-11',
              as_of: '2026-10-11T20:05:44Z',
            }),
          ),
      ],
    );
    expect(within(strip()).getAllByText(DEGRADED)).toHaveLength(1);
    expect(within(sublist('Falls')).getByText(STAMP_1011)).toBeTruthy();
    const text = document.body.textContent;
    for (const pattern of FORBIDDEN_WORDS) expect(text).not.toMatch(pattern);
    expect(text).not.toContain('2031');
  });
});

describe('shared MoverList purity and board polling (T09 criteria 3, 4; D9)', () => {
  it('renders byte-identical row markup on the board and on the home strip for one fixture', async () => {
    const odd = moverOf({
      card_id: 'ex10-?',
      card: null,
      variant_key: 'ex10-?:reverse:-:-',
      close: '214398.51',
      previous_close: '214398.50',
      change: '0.01',
      change_pct: '0.00',
    });
    await open(
      '/movers',
      [() => jsonResponse(moversBody('up', [odd, risingMover]))],
      [() => jsonResponse(moversBody('down', [fallingMover]))],
    );
    const boardRows = within(screen.getByRole('list', { name: 'Rises' }))
      .getAllByRole('listitem')
      .map((row) => row.outerHTML);
    cleanup();
    await open(
      '/',
      [() => jsonResponse(moversBody('up', [odd, risingMover]))],
      [() => jsonResponse(moversBody('down', [fallingMover]))],
    );
    const stripRows = within(within(strip()).getByRole('list', { name: 'Rises' }))
      .getAllByRole('listitem')
      .map((row) => row.outerHTML);
    expect(boardRows).toHaveLength(2);
    expect(stripRows).toEqual(boardRows);
    expect(stripRows[0]).toContain('▲ +$0.01 (+0.00%) vs previous close (2026-10-11)');
    expect(stripRows[0]).toContain('Market price $214,398.51');
  });

  it('keeps the board rows after a failed poll and replaces them with the new day after a good one', async () => {
    const urls = await open(
      '/movers',
      [
        () => jsonResponse(moversBody('up', [risingMover])),
        () => problemResponse(upstreamUnavailableProblem),
        () => jsonResponse(moversBody('up', [moverOf({ close: '30.00' })], newDay)),
      ],
      [() => jsonResponse(moversBody('down', [fallingMover]))],
    );
    expect(urls).toHaveLength(1);
    await advance(300_000);
    expect(urls).toHaveLength(2);
    expect(screen.getByRole('link', { name: 'Chimecho' })).toBeTruthy();
    expect(screen.getByText(STAMP_1012)).toBeTruthy();
    expect(screen.queryByRole('alert')).toBeNull();
    await advance(300_000);
    expect(urls).toHaveLength(3);
    expect(screen.getByText(STAMP_1013)).toBeTruthy();
    expect(screen.getByText('Market price $30.00')).toBeTruthy();
    expect(screen.queryByText(STAMP_1012)).toBeNull();
  });

  it('keeps exactly one direction pressed as the URL changes under it (popstate)', async () => {
    await open(
      '/movers',
      [() => jsonResponse(moversBody('up', [risingMover]))],
      [() => jsonResponse(moversBody('down', [fallingMover]))],
    );
    expect(pressed()).toEqual(['Rises']);
    fireEvent.click(screen.getByRole('button', { name: 'Falls' }));
    expect(pressed()).toEqual(['Falls']);
    visit('/movers');
    act(() => {
      window.dispatchEvent(new PopStateEvent('popstate'));
    });
    expect(pressed()).toEqual(['Rises']);
    visit('/movers?direction=down');
    act(() => {
      window.dispatchEvent(new PopStateEvent('popstate'));
    });
    expect(pressed()).toEqual(['Falls']);
    await settle();
    expect(screen.getByRole('link', { name: 'Cacturne ex' })).toBeTruthy();
  });

  it('shows the degraded line above an empty day and no footnote or list', async () => {
    await open(
      '/movers',
      [() => jsonResponse(moversBody('up', [], { degraded: true }))],
      [() => jsonResponse(moversBody('down', []))],
    );
    const line = screen.getByText(DEGRADED);
    const empty = screen.getByText('No rises ranked for 2026-10-12');
    expect(line.compareDocumentPosition(empty) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
    expect(screen.queryByRole('list', { name: 'Rises' })).toBeNull();
    expect(screen.queryByText(/collected once a day/)).toBeNull();
  });

  it('prints money and signs from the strings: thousands, a -0.01 fall, a true zero, a big floor', async () => {
    await open(
      '/movers',
      [
        () =>
          jsonResponse(
            moversBody(
              'up',
              [
                moverOf({
                  rank: 1,
                  variant_key: 'a:holo:-:-',
                  close: '1234567.80',
                  previous_close: '1000000.00',
                  change: '234567.80',
                  change_pct: '23.46',
                }),
                moverOf({
                  rank: 2,
                  variant_key: 'b:holo:-:-',
                  close: '99.99',
                  previous_close: '100.00',
                  change: '-0.01',
                  change_pct: '-0.00',
                }),
                moverOf({
                  rank: 3,
                  variant_key: 'c:holo:-:-',
                  close: '5.00',
                  previous_close: '5.00',
                  change: '0.00',
                  change_pct: '0.00',
                }),
              ],
              { min_previous_close: '1000.00' },
            ),
          ),
      ],
      [() => jsonResponse(moversBody('down', []))],
    );
    expect(
      screen.getByText(
        'Ranked by change vs previous close among variants whose previous close was at least $1,000.00.',
      ),
    ).toBeTruthy();
    expect(screen.getByText('Market price $1,234,567.80')).toBeTruthy();
    expect(
      screen.getByText('▲ +$234,567.80 (+23.46%) vs previous close (2026-10-11)'),
    ).toBeTruthy();
    expect(screen.getByText('▼ -$0.01 (-0.00%) vs previous close (2026-10-11)')).toBeTruthy();
    expect(screen.getByText('No change vs previous close (2026-10-11)')).toBeTruthy();
  });

  it('renders a card name as text, never as markup, and lets a long unbroken name wrap', async () => {
    const hostile = '<img src=x onerror=alert(1)>' + 'W'.repeat(200);
    await open(
      '/movers',
      [
        () =>
          jsonResponse(
            moversBody('up', [
              moverOf({ card: { name: hostile, set_name: '<b>Set</b>', number: '1' } }),
            ]),
          ),
      ],
      [() => jsonResponse(moversBody('down', []))],
    );
    const main = screen.getByRole('main');
    expect(main.querySelector('img')).toBeNull();
    expect(main.querySelector('b')).toBeNull();
    const link = screen.getByRole('link', { name: hostile });
    expect(link.parentElement?.className).toContain('wrap-anywhere');
    expect(link.parentElement?.className).toContain('min-w-0');
  });
});
