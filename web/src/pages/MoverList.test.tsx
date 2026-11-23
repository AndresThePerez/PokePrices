import { act, cleanup, render, screen, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import App from '../App';
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

const UP_URL = '/v1/movers?direction=up&limit=5';
const DOWN_URL = '/v1/movers?direction=down&limit=5';
const SCOPE =
  'Ranked by change vs previous close among variants whose previous close was at least $2.00, that moved at least 5% and at least $1.00, had four different closes among their last five, and whose two closes each sat between half the lowest listing and 1.5 times the mid listing of their own day.';
const STAMP_1012 = 'as of 2026-10-12 from TCGCSV (daily close)';
const STAMP_1011 = 'as of 2026-10-11 from TCGCSV (daily close)';
const STAMP_1013 = 'as of 2026-10-13 from TCGCSV (daily close)';
const DEGRADED = 'Catalog data may be out of date: PokéSearch is unavailable';

type Reply = () => Response | Error;

/** The day-2026-10-13 rise a later poll brings. */
const nextDayRise = moverOf({
  card_id: 'base1-4',
  card: { name: 'Charizard', set_name: 'Base', number: '4' },
  variant_key: 'base1-4:holo:unlimited:-',
  finish: 'holo',
  edition: 'unlimited',
  close: '968.91',
  previous_close: '928.06',
  previous_day: '2026-10-12',
  change: '40.85',
  change_pct: '4.40',
});

let visibility: DocumentVisibilityState = 'visible';

/** Lets answers, renders and TanStack's zero-delay notifications settle (real timeouts). */
async function settle(): Promise<void> {
  for (let round = 0; round < 3; round += 1) {
    await act(async () => {
      await new Promise((resolve) => {
        setTimeout(resolve, 0);
      });
    });
  }
}

/** Moves the polling clock by ms, then lets everything settle. */
async function advance(ms: number): Promise<void> {
  await act(async () => {
    await vi.advanceTimersByTimeAsync(ms);
  });
  await settle();
}

function visit(path: string): void {
  window.history.replaceState(null, '', path);
}

/**
 * Opens the home page; each direction is answered by its next reply (the last one repeats).
 * Returns every request URL in order.
 */
async function openHome(up: readonly Reply[], down: readonly Reply[]): Promise<string[]> {
  const urls: string[] = [];
  let ups = 0;
  let downs = 0;
  stubFetch((url) => {
    urls.push(url);
    if (!isMoversUrl(url)) return new Error('no request expected');
    const replies = url.includes('direction=down') ? down : up;
    const index = url.includes('direction=down') ? downs++ : ups++;
    const reply = replies[Math.min(index, replies.length - 1)];
    if (reply === undefined) throw new Error('no reply configured');
    return reply();
  });
  visit('/');
  render(<App />);
  await settle();
  return urls;
}

function strip(): HTMLElement {
  return screen.getByRole('region', { name: 'Biggest moves vs previous close' });
}

/** The sub-list block under the h3 named `name` (the h3 and its list share one parent). */
function sublist(name: 'Rises' | 'Falls'): HTMLElement {
  const heading = within(strip()).getByRole('heading', { level: 3, name });
  const block = heading.parentElement;
  if (block === null) throw new Error('h3 without a parent');
  return block;
}

beforeEach(() => {
  vi.useFakeTimers({ toFake: ['setInterval', 'clearInterval', 'Date'] });
  vi.setSystemTime(new Date('2031-02-03T04:05:06Z'));
  visibility = 'visible';
  Object.defineProperty(document, 'visibilityState', {
    configurable: true,
    get: () => visibility,
  });
});

afterEach(() => {
  cleanup();
  vi.useRealTimers();
  Reflect.deleteProperty(document, 'visibilityState');
  visit('/');
});

describe('home strip (T10 criterion 1)', () => {
  it('sits under the unchanged intro with its h2, the two h3 sub-lists, one scope line and one stamp', async () => {
    const urls = await openHome(
      [() => jsonResponse(moversBody('up', [risingMover], { limit: 5 }))],
      [() => jsonResponse(moversBody('down', [fallingMover], { limit: 5 }))],
    );
    expect([...urls].sort()).toEqual([DOWN_URL, UP_URL, '/v1/prices/freshness']);
    const intro = screen.getByText(
      'Each price is a TCGplayer market price collected once a day through TCGCSV, shown with its source and the day it is as of.',
    );
    const section = strip();
    expect(intro.compareDocumentPosition(section) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
    expect(within(section).getByRole('heading', { level: 2 }).textContent).toBe(
      'Biggest moves vs previous close',
    );
    expect(
      within(section)
        .getAllByRole('heading', { level: 3 })
        .map((h) => h.textContent),
    ).toEqual(['Rises', 'Falls']);
    expect(within(section).getAllByText(SCOPE)).toHaveLength(1);
    expect(within(section).getAllByText(STAMP_1012)).toHaveLength(1);
    const rises = within(section).getByRole('list', { name: 'Rises' });
    expect(within(rises).getByRole('link', { name: 'Chimecho' }).getAttribute('href')).toBe(
      '/card/ex5-17?variant=ex5-17%3Areverse%3A-%3A-',
    );
    expect(
      within(rises).getByText('▲ +$20.63 (+361.30%) vs previous close (2026-10-11)'),
    ).toBeTruthy();
    const falls = within(section).getByRole('list', { name: 'Falls' });
    expect(
      within(falls).getByText('▼ -$99.89 (-60.54%) vs previous close (2026-10-11)'),
    ).toBeTruthy();
    expect(within(falls).getByText('Market price $65.11')).toBeTruthy();
    const all = within(section).getByRole('link', { name: 'See all movers' });
    expect(all.getAttribute('href')).toBe('/movers');
    expect(all.className.split(/\s+/)).toContain('min-h-11');
  });

  it('prints one stamp per sub-list, under its h3, when the two days differ', async () => {
    await openHome(
      [() => jsonResponse(moversBody('up', [risingMover], { limit: 5 }))],
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
    const section = strip();
    expect(within(section).getAllByText(/^as of /)).toHaveLength(2);
    expect(within(sublist('Rises')).getByText(STAMP_1012)).toBeTruthy();
    expect(within(sublist('Falls')).getByText(STAMP_1011)).toBeTruthy();
    expect(within(sublist('Rises')).queryByText(STAMP_1011)).toBeNull();
    expect(within(section).getAllByText(SCOPE)).toHaveLength(1);
  });

  it('asks for the top 5 of each direction and shows at most what came back', async () => {
    const five = [1, 2, 3, 4, 5].map((rank) =>
      moverOf({
        rank,
        card_id: `ex5-${String(rank)}`,
        variant_key: `ex5-${String(rank)}:reverse:-:-`,
      }),
    );
    const urls = await openHome(
      [() => jsonResponse(moversBody('up', five, { limit: 5 }))],
      [() => jsonResponse(moversBody('down', [fallingMover], { limit: 5 }))],
    );
    expect(urls.filter((url) => url === UP_URL)).toHaveLength(1);
    expect(urls.filter((url) => url === DOWN_URL)).toHaveLength(1);
    expect(
      within(within(strip()).getByRole('list', { name: 'Rises' })).getAllByRole('listitem'),
    ).toHaveLength(5);
    expect(within(strip()).getByText('#5')).toBeTruthy();
  });
});

describe('home strip states (T10 criterion 3)', () => {
  it('shows Loading movers… and no See all movers link before the answers arrive', () => {
    stubFetch(() => jsonResponse(moversBody('up', [risingMover])));
    visit('/');
    render(<App />);
    const section = strip();
    expect(within(section).getByRole('status').textContent).toBe('Loading movers…');
    expect(within(section).queryByRole('link', { name: 'See all movers' })).toBeNull();
    expect(within(section).queryByRole('list')).toBeNull();
  });

  it('gives each sub-list its own empty state', async () => {
    await openHome(
      [() => jsonResponse(moversBody('up', [risingMover], { limit: 5 }))],
      [() => jsonResponse(moversBody('down', [], { limit: 5 }))],
    );
    expect(within(sublist('Falls')).getByText('No falls ranked for 2026-10-12')).toBeTruthy();
    expect(within(sublist('Rises')).getByRole('link', { name: 'Chimecho' })).toBeTruthy();
    expect(within(strip()).getByRole('link', { name: 'See all movers' })).toBeTruthy();
  });

  it('says No movers yet in both sub-lists, with no stamp, before any movers day', async () => {
    await openHome(
      [() => jsonResponse(moversBody('up', [], { day: null, as_of: null }))],
      [() => jsonResponse(moversBody('down', [], { day: null, as_of: null }))],
    );
    expect(within(sublist('Rises')).getByText('No movers yet')).toBeTruthy();
    expect(within(sublist('Falls')).getByText('No movers yet')).toBeTruthy();
    expect(strip().textContent).not.toContain('as of');
  });

  it('shows the section Problem block when a direction fails before any answer', async () => {
    await openHome(
      [() => jsonResponse(moversBody('up', [risingMover], { limit: 5 }))],
      [() => problemResponse(upstreamUnavailableProblem)],
    );
    const alert = within(strip()).getByRole('alert');
    expect(alert.textContent).toContain('Could not load the movers: Upstream Unavailable');
    expect(alert.textContent).toContain('Request ID req-t46-upstream-0001');
    expect(within(strip()).queryByRole('link', { name: 'See all movers' })).toBeNull();
  });

  it('shows the degraded catalog line when either answer is degraded', async () => {
    await openHome(
      [() => jsonResponse(moversBody('up', [moverOf({ card: null })], { degraded: true }))],
      [() => jsonResponse(moversBody('down', [fallingMover]))],
    );
    expect(within(strip()).getByText(DEGRADED)).toBeTruthy();
    expect(within(strip()).getByRole('link', { name: 'Card ex5-17' })).toBeTruthy();
  });
});

describe('home strip is a static ticker (D3, D9; T10 criteria 2 and 3)', () => {
  it('has no marquee, animation or transition', async () => {
    await openHome(
      [() => jsonResponse(moversBody('up', [risingMover]))],
      [() => jsonResponse(moversBody('down', [fallingMover]))],
    );
    const section = strip();
    expect(section.querySelectorAll('marquee, blink')).toHaveLength(0);
    for (const element of [section, ...Array.from(section.querySelectorAll('*'))]) {
      const classes = element.getAttribute('class') ?? '';
      expect(classes).not.toMatch(/animate-|transition|marquee|scroll/);
    }
  });

  it('replaces a list and its stamp only when a poll brings a new day, every 300,000 ms', async () => {
    const urls = await openHome(
      [
        () => jsonResponse(moversBody('up', [risingMover])),
        () =>
          jsonResponse(
            moversBody('up', [nextDayRise], { day: '2026-10-13', as_of: '2026-10-13T20:05:44Z' }),
          ),
      ],
      [() => jsonResponse(moversBody('down', [fallingMover]))],
    );
    expect(urls).toHaveLength(3);
    expect(within(strip()).getAllByText(STAMP_1012)).toHaveLength(1);
    await advance(299_999);
    expect(urls).toHaveLength(3);
    await advance(1);
    expect(urls).toHaveLength(6);
    const rises = within(strip()).getByRole('list', { name: 'Rises' });
    expect(within(rises).getByRole('link', { name: 'Charizard' })).toBeTruthy();
    expect(within(rises).queryByRole('link', { name: 'Chimecho' })).toBeNull();
    expect(within(sublist('Rises')).getByText(STAMP_1013)).toBeTruthy();
    expect(within(sublist('Falls')).getByText(STAMP_1012)).toBeTruthy();
    expect(strip().textContent).not.toMatch(/\bnew\b|updated/i);
  });

  it('keeps the strip when a later poll fails, with no alert', async () => {
    const urls = await openHome(
      [
        () => jsonResponse(moversBody('up', [risingMover])),
        () => problemResponse(upstreamUnavailableProblem),
      ],
      [() => jsonResponse(moversBody('down', [fallingMover])), () => new TypeError('network down')],
    );
    await advance(300_000);
    expect(urls).toHaveLength(6);
    expect(within(strip()).getByRole('link', { name: 'Chimecho' })).toBeTruthy();
    expect(within(strip()).getByRole('link', { name: 'Cacturne ex' })).toBeTruthy();
    expect(within(strip()).getByText(STAMP_1012)).toBeTruthy();
    expect(within(strip()).queryByRole('alert')).toBeNull();
  });
});

describe('home strip honesty (D1, D14; Both criterion 2)', () => {
  it.each([
    ['rows', [() => jsonResponse(moversBody('up', [risingMover]))]],
    ['degraded', [() => jsonResponse(moversBody('up', [risingMover], { degraded: true }))]],
    ['empty', [() => jsonResponse(moversBody('up', []))]],
    ['no movers', [() => jsonResponse(moversBody('up', [], { day: null, as_of: null }))]],
    ['failure', [() => problemResponse(upstreamUnavailableProblem)]],
  ] as const)('never shows a forbidden word or the faked year: %s', async (_, up) => {
    await openHome(up, [() => jsonResponse(moversBody('down', [fallingMover]))]);
    expect(within(strip()).queryByRole('status')).toBeNull();
    const text = document.body.textContent;
    for (const pattern of FORBIDDEN_WORDS) expect(text).not.toMatch(pattern);
    expect(text).not.toContain('2031');
  });

  it('never shows a forbidden word while loading', () => {
    stubFetch(() => jsonResponse(moversBody('up', [risingMover])));
    visit('/');
    render(<App />);
    const text = document.body.textContent;
    for (const pattern of FORBIDDEN_WORDS) expect(text).not.toMatch(pattern);
    expect(text).not.toContain('2031');
  });
});
