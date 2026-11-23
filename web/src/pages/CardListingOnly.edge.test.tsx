// Adversarial cases for a card whose variants have no close but at least one listing: which variant opens
// (by default and by address), what the page does when a poll gives such a card a close, takes its listing
// away or takes every close from a priced one, what the history panel shows over an answer with no points
// and over a failing request, and that every listing on screen carries its source and day.
import { act, cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi, type MockInstance } from 'vitest';
import App from '../App';
import type { CardDetail } from '../api/client';
import { cardVariantPath } from '../routes';
import {
  FORBIDDEN_WORDS,
  answerMovers,
  cardNotFoundProblem,
  clearXsrfCookie,
  firstClosePrice,
  freshnessBody,
  historyBody,
  historyFor,
  isFreshnessUrl,
  isHistoryUrl,
  isMoversUrl,
  jsonResponse,
  pointOf,
  problemResponse,
  rawResponse,
  seriesOf,
  serverErrorProblem,
  stubAccountFetch,
  stubFetch,
  unownCard,
  upstreamUnavailableProblem,
  validationProblem,
  type FetchCall,
  type VariantPrice,
} from '../test/fixtures';
import { recorder, resetRecorder } from '../test/lightweightChartsFake';

// jsdom has no canvas: the chart library is replaced by its recording fake.
vi.mock('lightweight-charts', () => import('../test/lightweightChartsFake'));

type Variant = CardDetail['data']['variants'][number];

const UNLIMITED = 'base1-4:holo:unlimited:-';
const SHADOWLESS = 'base1-4:holo:shadowless:-';
const FIRST = 'base1-4:holo:1st:-';
const NORMAL = 'base1-4:normal:unlimited:-';

const ZERO_ELIGIBLE = 'No daily close is held for this card yet.';
const NO_PRICE = 'No price in the collected snapshots';

function variantOf(key: string, price: VariantPrice | null, listing?: Variant['listing']): Variant {
  const [, finish = '-', edition = '-', treatment = '-'] = key.split(':');
  return {
    variant_key: key,
    finish,
    edition,
    treatment,
    sources: [
      {
        source: 'tcgplayer',
        product_id: 42382,
        subtype: 'Holofoil',
        group_id: 604,
        series_epoch: 1,
        map_snapshot_day: '2026-10-11',
      },
    ],
    price,
    ...(listing === undefined ? {} : { listing }),
  };
}

function cardOf(...variants: Variant[]): CardDetail {
  return {
    data: { ...unownCard, id: 'base1-4', name: 'Charizard', number: '4', variants },
    meta: { degraded: false },
  };
}

function listingOf(lowest: string, day = '2026-10-13'): NonNullable<Variant['listing']> {
  return {
    source: 'tcgplayer',
    feed: 'tcgcsv',
    currency: 'USD',
    cadence: 'daily',
    lowest_listing: lowest,
    day,
    as_of: `${day}T20:05:44Z`,
  };
}

const closeOf300: VariantPrice = {
  ...firstClosePrice,
  close: '300.00',
  lowest_listing: '280.00',
  thin_market: false,
};
const closeOf2257: VariantPrice = { ...firstClosePrice, close: '2257.87' };

let consoleError: MockInstance<typeof console.error>;
let visibility: DocumentVisibilityState = 'visible';
let phase = 0;

interface Script {
  /** The card each phase answers (the last one repeats). */
  readonly cards: readonly CardDetail[];
  /** The history each phase answers; the history the API would send beside that card by default. */
  readonly history?: (phase: number, card: CardDetail) => Response | Error;
  readonly freshness?: Response;
}

/** The phase is moved by the test, so the card and its history change together at one poll. */
function serve(script: Script): FetchCall[] {
  phase = 0;
  const at = (): CardDetail => script.cards[Math.min(phase, script.cards.length - 1)] ?? cardOf();
  return stubFetch((url) => {
    if (isFreshnessUrl(url)) {
      return script.freshness ?? jsonResponse(freshnessBody({ within_slo: true, age_hours: 3.5 }));
    }
    if (isMoversUrl(url)) return answerMovers(url);
    if (isHistoryUrl(url)) {
      const card = at();
      return script.history === undefined
        ? jsonResponse(historyFor(card))
        : script.history(phase, card);
    }
    if (url === '/v1/cards/base1-4') return jsonResponse(at());
    return new Error(`no request expected: ${url}`);
  });
}

function visit(path: string): void {
  window.history.replaceState(null, '', path);
}

function address(): string {
  return window.location.pathname + window.location.search;
}

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

/** The hero: the block led by its "Market price · <variant>" label line. */
async function waitForHero(): Promise<HTMLElement> {
  const label = await screen.findByText(/^Market price · /);
  return heroOf(label);
}

function heroOf(label: HTMLElement): HTMLElement {
  const block = label.parentElement;
  if (block === null) throw new Error('the hero label has no block');
  return block;
}

function hero(): HTMLElement {
  return heroOf(screen.getByText(/^Market price · /));
}

function row(label: string): HTMLElement {
  const prices = screen.getByRole('region', { name: 'Prices' });
  const item = within(prices)
    .getAllByRole('listitem')
    .find((one) => one.querySelector('.capitalize')?.textContent === label);
  if (item === undefined) throw new Error(`no Prices row for ${label}`);
  return item;
}

function pressedOf(section: HTMLElement): string[] {
  return within(section)
    .queryAllByRole('group', { name: 'Variant' })
    .flatMap((group) =>
      within(group)
        .getAllByRole('button')
        .filter((button) => button.getAttribute('aria-pressed') === 'true')
        .map((button) => button.textContent),
    );
}

/** Every dollar amount written anywhere on the page. */
function amounts(): string[] {
  return document.body.textContent.match(/\$[\d,.]+/g) ?? [];
}

/** Nothing on the page is a number that is not there, and nothing is drawn twice. */
function nothingBroken(): void {
  const text = document.body.textContent;
  expect(text).not.toMatch(/NaN|undefined|null|\$-|\$\s/);
  expect(screen.getAllByText(/^Market price · /)).toHaveLength(1);
  expect(screen.getAllByRole('region', { name: 'Prices' })).toHaveLength(1);
  expect(screen.getAllByRole('region', { name: 'Price history' })).toHaveLength(1);
  for (const word of FORBIDDEN_WORDS) expect(text).not.toMatch(word);
}

/** Every listing sentence on the page has its own source-and-day line right under it. */
function everyListingHasItsDay(): void {
  const sentences = screen.queryAllByText(/^No recent sales; lowest listing /);
  for (const sentence of sentences) {
    const next = sentence.nextElementSibling;
    expect(next?.textContent).toMatch(
      /^Listing as of \d{4}-\d{2}-\d{2} from TCGCSV \(daily snapshot\)$/,
    );
  }
}

beforeEach(() => {
  resetRecorder();
  consoleError = vi.spyOn(console, 'error');
  visibility = 'visible';
  Object.defineProperty(document, 'visibilityState', {
    configurable: true,
    get: () => visibility,
  });
});

afterEach(() => {
  cleanup();
  vi.useRealTimers();
  consoleError.mockRestore();
  Reflect.deleteProperty(document, 'visibilityState');
  visit('/');
});

describe('which variant a card with no close opens', () => {
  const cases: readonly (readonly [
    string,
    readonly (readonly [string, string | null])[],
    string,
    string,
  ])[] = [
    [
      'the first of three listed, the smallest amount last',
      [
        [SHADOWLESS, '5.00'],
        [UNLIMITED, '900000.00'],
        [FIRST, '0.01'],
      ],
      'holo, shadowless',
      '$5.00',
    ],
    [
      'the first of three listed when it holds the largest amount',
      [
        [SHADOWLESS, '9999999999.99'],
        [UNLIMITED, '0.01'],
        [FIRST, '5.00'],
      ],
      'holo, shadowless',
      '$9,999,999,999.99',
    ],
    [
      'the second when the first has neither a close nor a listing',
      [
        [SHADOWLESS, null],
        [UNLIMITED, '900000.00'],
        [FIRST, '0.01'],
      ],
      'holo, unlimited',
      '$900,000.00',
    ],
    [
      'the last when it is the only one listed',
      [
        [SHADOWLESS, null],
        [UNLIMITED, null],
        [NORMAL, '12.50'],
      ],
      'normal, unlimited',
      '$12.50',
    ],
    [
      'the first listed of two when a bare variant follows',
      [
        [FIRST, '0.00'],
        [NORMAL, null],
      ],
      'holo, 1st',
      '$0.00',
    ],
  ];

  it.each(cases)(
    'opens %s',
    async (
      ...row_: readonly [string, readonly (readonly [string, string | null])[], string, string]
    ) => {
      const [, listed, label, amount] = row_;
      const card = cardOf(
        ...listed.map(([key, lowest]) =>
          variantOf(key, null, lowest === null ? undefined : listingOf(lowest)),
        ),
      );
      serve({ cards: [card] });
      visit('/card/base1-4');
      render(<App />);
      const block = await waitForHero();
      expect(within(block).getByText(`Market price · ${label}`)).toBeTruthy();
      expect(within(block).getByText(`No recent sales; lowest listing ${amount}`)).toBeTruthy();
      expect(within(block).getByText(/^Listing as of 2026-10-13 from TCGCSV/)).toBeTruthy();
      // Every variant keeps its own row: its own sentence, or the no-price line.
      for (const [key, lowest] of listed) {
        const [, finish = '', edition = '', treatment = '-'] = key.split(':');
        const name = [finish, edition, treatment].filter((part) => part !== '-').join(', ');
        const own = within(row(name));
        if (lowest === null) expect(own.getByText(NO_PRICE)).toBeTruthy();
        else expect(own.getByText(/^No recent sales; lowest listing \$/)).toBeTruthy();
      }
      expect(screen.queryByText('No prices yet')).toBeNull();
      await screen.findByText(ZERO_ELIGIBLE);
      nothingBroken();
      everyListingHasItsDay();
      expect(recorder.charts).toHaveLength(0);
      expect(consoleError).not.toHaveBeenCalled();
    },
  );

  it('groups a listing of exactly 0.00, 0.01 and the largest amount, with no sign, exponent or NaN', async () => {
    serve({
      cards: [
        cardOf(
          variantOf(SHADOWLESS, null, listingOf('0.00')),
          variantOf(UNLIMITED, null, listingOf('0.01', '2026-10-12')),
          variantOf(FIRST, null, listingOf('9999999999.99', '2026-10-14')),
        ),
      ],
    });
    visit('/card/base1-4');
    render(<App />);
    await waitForHero();
    expect(
      within(row('holo, shadowless')).getByText('No recent sales; lowest listing $0.00'),
    ).toBeTruthy();
    expect(
      within(row('holo, unlimited')).getByText('No recent sales; lowest listing $0.01'),
    ).toBeTruthy();
    expect(
      within(row('holo, unlimited')).getByText(
        'Listing as of 2026-10-12 from TCGCSV (daily snapshot)',
      ),
    ).toBeTruthy();
    expect(
      within(row('holo, 1st')).getByText('No recent sales; lowest listing $9,999,999,999.99'),
    ).toBeTruthy();
    expect(
      within(row('holo, 1st')).getByText('Listing as of 2026-10-14 from TCGCSV (daily snapshot)'),
    ).toBeTruthy();
    expect(document.body.textContent).not.toMatch(/e\+|NaN/);
    nothingBroken();
    everyListingHasItsDay();
  });

  it('keeps "No prices yet" with no hero, no history and exactly the card and freshness requests for a card with no variant, and for bare variants', async () => {
    const cards = [cardOf(), cardOf(variantOf(SHADOWLESS, null), variantOf(FIRST, null))];
    for (const card of cards) {
      for (const path of ['/card/base1-4', cardVariantPath('base1-4', FIRST)]) {
        const calls = serve({ cards: [card] });
        visit(path);
        render(<App />);
        const prices = await screen.findByRole('region', { name: 'Prices' });
        await within(prices).findByText('No prices yet');
        await settle();
        expect(prices.textContent).toBe('PricesNo prices yet');
        expect(screen.queryByText(/^Market price · /)).toBeNull();
        expect(screen.queryByRole('region', { name: 'Price history' })).toBeNull();
        expect(calls.map((call) => call.url)).toEqual([
          '/v1/prices/freshness',
          '/v1/cards/base1-4',
        ]);
        expect(document.body.textContent).not.toMatch(/NaN|undefined|null/);
        cleanup();
      }
    }
  });

  const wrongKind: readonly (readonly [string, unknown])[] = [
    ['lowest_listing null', { ...listingOf('5.00'), lowest_listing: null }],
    ['lowest_listing missing', { ...listingOf('5.00'), lowest_listing: undefined }],
    ['lowest_listing an object', { ...listingOf('5.00'), lowest_listing: { amount: '5.00' } }],
    ['day a number', { ...listingOf('5.00'), day: 20261013 }],
    ['day missing', { ...listingOf('5.00'), day: undefined }],
    ['listing an empty list', []],
    ['listing a string', '5.00'],
    ['listing a number', 5],
  ];

  it.each(wrongKind)(
    'shows the problem block, no listing and no NaN, undefined or null for a listing with %s beside a good one',
    async (...row_: readonly [string, unknown]) => {
      const [, bad] = row_;
      const card = cardOf(variantOf(FIRST, null, listingOf('100000.00')), variantOf(NORMAL, null));
      const body = JSON.parse(JSON.stringify(card)) as CardDetail;
      (body.data.variants[1] as { listing?: unknown }).listing = bad;
      serve({ cards: [body] });
      visit('/card/base1-4');
      render(<App />);
      const alert = await screen.findByRole('alert');
      expect(alert.textContent).toContain('Could not load this card');
      expect(screen.queryByText(/^Market price/)).toBeNull();
      expect(screen.queryByText('No prices yet')).toBeNull();
      expect(document.body.textContent).not.toMatch(/NaN|undefined|\$null|100,000/);
    },
  );
});

describe('the address on a card with no close', () => {
  function twoListed(): CardDetail {
    return cardOf(
      variantOf(SHADOWLESS, null, listingOf('50.00')),
      variantOf(UNLIMITED, null, listingOf('7.00', '2026-10-12')),
      variantOf(NORMAL, null),
    );
  }

  it('opens the listed variant the address names, not the first listed, and leaves the address and the history alone', async () => {
    serve({ cards: [twoListed()] });
    visit(cardVariantPath('base1-4', UNLIMITED));
    const length = window.history.length;
    render(<App />);
    const block = await waitForHero();
    expect(within(block).getByText('Market price · holo, unlimited')).toBeTruthy();
    expect(within(block).getByText('No recent sales; lowest listing $7.00')).toBeTruthy();
    expect(
      within(block).getByText('Listing as of 2026-10-12 from TCGCSV (daily snapshot)'),
    ).toBeTruthy();
    expect(block.textContent).not.toMatch(/\$50\.00/);
    await screen.findByText(ZERO_ELIGIBLE);
    // The page offers no Variant segment to choose from; if it ever does, only the shown variant is pressed.
    const section = screen.getByRole('region', { name: 'Price history' });
    const pressed = pressedOf(section);
    if (pressed.length > 0) expect(pressed).toEqual(['holo, unlimited']);
    expect(address()).toBe(cardVariantPath('base1-4', UNLIMITED));
    expect(window.history.length).toBe(length);
    nothingBroken();
    everyListingHasItsDay();
  });

  it('opens a variant with neither a close nor a listing when the address names it, with its own no-price line and nothing of a sibling listing in the hero', async () => {
    serve({ cards: [twoListed()] });
    visit(cardVariantPath('base1-4', NORMAL));
    render(<App />);
    const block = await waitForHero();
    expect(within(block).getByText('Market price · normal, unlimited')).toBeTruthy();
    expect(within(block).getByText(NO_PRICE)).toBeTruthy();
    expect(block.textContent).not.toMatch(/\$|No recent sales|Listing as of/);
    // The listed siblings still read as they do.
    expect(
      within(row('holo, shadowless')).getByText('No recent sales; lowest listing $50.00'),
    ).toBeTruthy();
    expect(
      within(row('holo, unlimited')).getByText('No recent sales; lowest listing $7.00'),
    ).toBeTruthy();
    await screen.findByText(ZERO_ELIGIBLE);
    nothingBroken();
    everyListingHasItsDay();
  });

  const keys: readonly (readonly [string, string])[] = [
    ['an unknown key', `/card/base1-4?variant=${encodeURIComponent('base1-4:holo:nope:-')}`],
    ['an empty key', '/card/base1-4?variant='],
    [
      'the key of another card',
      `/card/base1-4?variant=${encodeURIComponent('ex10-?:holo:unlimited:-')}`,
    ],
    ['a malformed percent escape', '/card/base1-4?variant=%E0%A4%A'],
    [
      'a key given twice, the first unknown',
      `/card/base1-4?variant=zzz&variant=${encodeURIComponent(NORMAL)}`,
    ],
    [
      'a key with a space around it',
      `/card/base1-4?variant=${encodeURIComponent(` ${UNLIMITED} `)}`,
    ],
  ];

  it.each(keys)(
    'opens the first listed variant without an error or a rewritten address for %s',
    async (...row_: readonly [string, string]) => {
      const [, path] = row_;
      serve({ cards: [twoListed()] });
      visit(path);
      const length = window.history.length;
      render(<App />);
      const block = await waitForHero();
      expect(within(block).getByText('Market price · holo, shadowless')).toBeTruthy();
      expect(within(block).getByText('No recent sales; lowest listing $50.00')).toBeTruthy();
      expect(screen.queryByRole('alert')).toBeNull();
      expect(window.history.length).toBe(length);
      expect(window.location.pathname).toBe('/card/base1-4');
      expect(consoleError).not.toHaveBeenCalled();
    },
  );

  it('reopens the named variant on Forward after a trip Back to the home page, with no history step added by the card', async () => {
    serve({ cards: [twoListed()] });
    window.history.replaceState(null, '', '/');
    window.history.pushState(null, '', cardVariantPath('base1-4', NORMAL));
    render(<App />);
    expect(within(await waitForHero()).getByText('Market price · normal, unlimited')).toBeTruthy();
    const length = window.history.length;
    act(() => {
      window.history.back();
    });
    await waitFor(() => {
      expect(window.location.pathname).toBe('/');
    });
    await screen.findByRole('link', { name: 'See all movers' });
    act(() => {
      window.history.forward();
    });
    await waitFor(() => {
      expect(address()).toBe(cardVariantPath('base1-4', NORMAL));
    });
    const block = await waitForHero();
    expect(within(block).getByText('Market price · normal, unlimited')).toBeTruthy();
    expect(within(block).getByText(NO_PRICE)).toBeTruthy();
    expect(window.history.length).toBe(length);
    nothingBroken();
    expect(consoleError).not.toHaveBeenCalled();
  });

  it('puts one hero, one Prices list, one history panel and one banner on a card with no close under a stale-prices answer', async () => {
    serve({ cards: [twoListed()], freshness: jsonResponse(freshnessBody()) });
    visit('/card/base1-4');
    render(<App />);
    await waitForHero();
    await screen.findByText(ZERO_ELIGIBLE);
    expect(screen.getAllByText(/^Prices are out of date: /)).toHaveLength(1);
    nothingBroken();
    everyListingHasItsDay();
  });
});

describe('a poll changes a card with no close, or takes every close from a priced one', () => {
  beforeEach(() => {
    vi.useFakeTimers({ toFake: ['setInterval', 'clearInterval', 'Date'] });
    vi.setSystemTime(new Date('2031-02-03T04:05:06Z'));
  });

  const gains: readonly (readonly [string, string])[] = [
    ['with no variant in the address', '/card/base1-4'],
    ['with the variant named in the address', cardVariantPath('base1-4', FIRST)],
  ];

  it.each(gains)(
    'switches the hero from the listing sentence to the close with its source, day and listing, draws the history, and leaves no sentence of "No recent sales" in the hero, %s',
    async (...row_: readonly [string, string]) => {
      const [, path] = row_;
      const before = cardOf(
        variantOf(FIRST, null, listingOf('100000.00')),
        variantOf(NORMAL, null),
      );
      const after = cardOf(variantOf(FIRST, closeOf300), variantOf(NORMAL, null));
      const calls = serve({ cards: [before, after] });
      visit(path);
      render(<App />);
      await settle();
      expect(within(hero()).getByText('No recent sales; lowest listing $100,000.00')).toBeTruthy();
      expect(recorder.charts).toHaveLength(0);
      const historyAsks = calls.filter((call) => isHistoryUrl(call.url)).length;

      phase = 1;
      await advance(300_000);
      const block = hero();
      expect(within(block).getByText('Market price · holo, 1st')).toBeTruthy();
      expect(within(block).getByText('$300.00')).toBeTruthy();
      expect(within(block).getByText('Lowest listing $280.00')).toBeTruthy();
      expect(within(block).getByText('as of 2026-10-12 from TCGCSV (daily close)')).toBeTruthy();
      expect(block.textContent).not.toMatch(/No recent sales|Listing as of|\$100,000/);
      expect(document.body.textContent).not.toMatch(/No recent sales|Listing as of|\$100,000/);
      // The chart asked for its history again and draws the close the hero shows.
      expect(calls.filter((call) => isHistoryUrl(call.url)).length).toBeGreaterThan(historyAsks);
      await waitFor(() => {
        expect(recorder.charts.length).toBeGreaterThan(0);
      });
      const data = recorder.charts.at(-1)?.series.at(-1)?.data.at(-1);
      expect(
        Array.isArray(data) ? data.map((point: { value: number }) => point.value) : [],
      ).toEqual([300]);
      expect(screen.queryByText(ZERO_ELIGIBLE)).toBeNull();
      expect(within(row('normal, unlimited')).getByText(NO_PRICE)).toBeTruthy();
      nothingBroken();
      expect(consoleError).not.toHaveBeenCalled();
    },
  );

  it('falls to "No prices yet", with no hero, no history panel and no amount, when the only listing goes, and comes back with it', async () => {
    const listed = cardOf(variantOf(FIRST, null, listingOf('100000.00')), variantOf(NORMAL, null));
    const bare = cardOf(variantOf(FIRST, null), variantOf(NORMAL, null));
    serve({ cards: [listed, bare, listed] });
    visit(cardVariantPath('base1-4', FIRST));
    render(<App />);
    await settle();
    expect(within(hero()).getByText('Market price · holo, 1st')).toBeTruthy();

    phase = 1;
    await advance(300_000);
    const prices = screen.getByRole('region', { name: 'Prices' });
    expect(prices.textContent).toBe('PricesNo prices yet');
    expect(screen.queryByText(/^Market price · /)).toBeNull();
    expect(screen.queryByRole('region', { name: 'Price history' })).toBeNull();
    expect(amounts()).toEqual([]);
    expect(document.body.textContent).not.toMatch(
      /No recent sales|Listing as of|NaN|undefined|null/,
    );

    phase = 2;
    await advance(300_000);
    expect(within(hero()).getByText('Market price · holo, 1st')).toBeTruthy();
    expect(within(hero()).getByText('No recent sales; lowest listing $100,000.00')).toBeTruthy();
    nothingBroken();
    expect(consoleError).not.toHaveBeenCalled();
  });

  const losesOne: readonly (readonly [string, string, string, string])[] = [
    ['no variant in the address', '/card/base1-4', 'holo, unlimited', '$7.00'],
    [
      'the variant that loses its listing named in the address',
      cardVariantPath('base1-4', SHADOWLESS),
      'holo, shadowless',
      NO_PRICE,
    ],
  ];

  it.each(losesOne)(
    'shows no leftover listing of the variant that loses it, the hero following the address else the next listed variant, with %s',
    async (...row_: readonly [string, string, string, string]) => {
      const [, path, label, text] = row_;
      const before = cardOf(
        variantOf(SHADOWLESS, null, listingOf('50.00')),
        variantOf(UNLIMITED, null, listingOf('7.00')),
      );
      const after = cardOf(
        variantOf(SHADOWLESS, null),
        variantOf(UNLIMITED, null, listingOf('7.00')),
      );
      serve({ cards: [before, after] });
      visit(path);
      render(<App />);
      await settle();
      phase = 1;
      await advance(300_000);
      const block = hero();
      expect(within(block).getByText(`Market price · ${label}`)).toBeTruthy();
      expect(block.textContent).toContain(text);
      expect(block.textContent).not.toMatch(/\$50\.00/);
      expect(document.body.textContent).not.toMatch(/\$50\.00/);
      expect(within(row('holo, shadowless')).getByText(NO_PRICE)).toBeTruthy();
      nothingBroken();
      everyListingHasItsDay();
      expect(consoleError).not.toHaveBeenCalled();
    },
  );

  const afterAllLost: readonly (readonly [string, 'empty' | 'fails'])[] = [
    ['the history answers again with no point', 'empty'],
    ['the history request of that poll fails and the last good answer stays', 'fails'],
  ];

  it.each(afterAllLost)(
    'falls to the first variant with a listing when a priced card loses every close, drawing no close of any variant and showing only that variant figures in the hero, while %s',
    async (...row_: readonly [string, 'empty' | 'fails']) => {
      const [, mode] = row_;
      const priced = cardOf(
        variantOf(SHADOWLESS, closeOf2257),
        variantOf(UNLIMITED, closeOf300),
        variantOf(FIRST, null, listingOf('100000.00')),
        variantOf(NORMAL, null),
      );
      const lost = cardOf(
        variantOf(SHADOWLESS, null),
        variantOf(UNLIMITED, null, listingOf('275.00', '2026-10-14')),
        variantOf(FIRST, null, listingOf('100000.00')),
        variantOf(NORMAL, null),
      );
      serve({
        cards: [priced, lost],
        history: (now, card) =>
          now === 1 && mode === 'fails'
            ? problemResponse(upstreamUnavailableProblem)
            : jsonResponse(historyFor(card)),
      });
      visit(cardVariantPath('base1-4', SHADOWLESS));
      render(<App />);
      await settle();
      expect(within(hero()).getByText('Market price · holo, shadowless')).toBeTruthy();
      await waitFor(() => {
        expect(recorder.charts.length).toBeGreaterThan(0);
      });

      phase = 1;
      await advance(300_000);
      // The address names a variant that was priced: the page falls to the first variant with a listing.
      const block = hero();
      expect(within(block).getByText('Market price · holo, unlimited')).toBeTruthy();
      expect(within(block).getByText('No recent sales; lowest listing $275.00')).toBeTruthy();
      expect(
        within(block).getByText('Listing as of 2026-10-14 from TCGCSV (daily snapshot)'),
      ).toBeTruthy();
      expect(block.textContent).not.toMatch(
        /\$2,257\.87|\$300\.00|Lowest listing|as of 2026-10-12/,
      );
      // Every dollar amount left is a listing, none a close of an earlier answer.
      expect(amounts().sort()).toEqual(['$100,000.00', '$275.00', '$275.00'].sort());
      expect(document.body.textContent).not.toMatch(/2,257|\$300|NaN|undefined|null/);
      // No chart is left drawing a close that is no longer held, and none is added.
      expect(recorder.charts.every((chart) => chart.removed)).toBe(true);
      expect(screen.queryByRole('button', { name: /data table/i })).toBeNull();
      const section = screen.getByRole('region', { name: 'Price history' });
      expect(within(section).queryByRole('group', { name: 'Variant' })).toBeNull();
      expect(within(row('holo, shadowless')).getByText(NO_PRICE)).toBeTruthy();
      expect(
        within(row('holo, 1st')).getByText('No recent sales; lowest listing $100,000.00'),
      ).toBeTruthy();
      nothingBroken();
      everyListingHasItsDay();
      expect(consoleError).not.toHaveBeenCalled();
    },
  );

  it('falls to "No prices yet" when a priced card loses every close and every listing, removes its chart, and shows the hero again when a close returns', async () => {
    const priced = cardOf(
      variantOf(SHADOWLESS, closeOf2257),
      variantOf(FIRST, null, listingOf('100000.00')),
    );
    const gone = cardOf(variantOf(SHADOWLESS, null), variantOf(FIRST, null));
    serve({ cards: [priced, gone, priced] });
    visit('/card/base1-4');
    render(<App />);
    await settle();
    await waitFor(() => {
      expect(recorder.charts.length).toBeGreaterThan(0);
    });

    phase = 1;
    await advance(300_000);
    expect(screen.getByRole('region', { name: 'Prices' }).textContent).toBe('PricesNo prices yet');
    expect(screen.queryByText(/^Market price · /)).toBeNull();
    expect(screen.queryByRole('region', { name: 'Price history' })).toBeNull();
    expect(recorder.charts.every((chart) => chart.removed)).toBe(true);
    expect(amounts()).toEqual([]);

    phase = 2;
    await advance(300_000);
    expect(within(hero()).getByText('Market price · holo, shadowless')).toBeTruthy();
    expect(within(hero()).getByText('$2,257.87')).toBeTruthy();
    nothingBroken();
    expect(consoleError).not.toHaveBeenCalled();
  });
});

describe('the history panel of a card with a listing and no close', () => {
  const card = cardOf(variantOf(FIRST, null, listingOf('100000.00')), variantOf(NORMAL, null));
  const emptySeries = [seriesOf(FIRST, [], null), seriesOf(NORMAL, [], null)];

  const calm: readonly (readonly [string, Response, string | null])[] = [
    [
      'series with no point and a window ending on a day',
      jsonResponse(historyBody(emptySeries, { to: '2026-10-13', from: '2026-09-13' })),
      'as of 2026-10-13 from TCGCSV (daily close)',
    ],
    [
      'series with no point and no window',
      jsonResponse(historyBody(emptySeries, { to: null, from: null })),
      null,
    ],
    [
      'no series at all and a window ending on a day',
      jsonResponse(historyBody([], { to: '2026-10-13', from: '2026-09-13' })),
      'as of 2026-10-13 from TCGCSV (daily close)',
    ],
    [
      'no series at all and no window',
      jsonResponse(historyBody([], { to: null, from: null })),
      null,
    ],
    ['a JSON null', jsonResponse(null), null],
    ['data that is not a list', jsonResponse({ data: 'none', meta: {} }), null],
  ];

  it.each(calm)(
    'says only that no close is held, draws no chart and keeps the hero and the rows, for %s',
    async (...row_: readonly [string, Response, string | null]) => {
      const [, answer, stamp] = row_;
      serve({ cards: [card], history: () => answer.clone() });
      visit(cardVariantPath('base1-4', FIRST));
      render(<App />);
      const section = await screen.findByRole('region', { name: 'Price history' });
      await within(section).findByText(ZERO_ELIGIBLE);
      if (stamp === null) expect(within(section).queryByText(/^as of /)).toBeNull();
      else expect(within(section).getByText(stamp)).toBeTruthy();
      expect(recorder.charts).toHaveLength(0);
      expect(within(section).queryByRole('button', { name: /data table/i })).toBeNull();
      expect(within(section).queryByRole('alert')).toBeNull();
      expect(within(hero()).getByText('No recent sales; lowest listing $100,000.00')).toBeTruthy();
      expect(within(row('normal, unlimited')).getByText(NO_PRICE)).toBeTruthy();
      expect(amounts()).toEqual(amounts().map(() => '$100,000.00'));
      nothingBroken();
      everyListingHasItsDay();
      expect(consoleError).not.toHaveBeenCalled();
    },
  );

  it('draws no chart and prints no close from a history series the card answer calls unpriced, saying no close is held for this variant', async () => {
    const series = seriesOf(FIRST, [
      pointOf('2026-10-01', '321.00'),
      pointOf('2026-10-02', '322.00'),
    ]);
    serve({
      cards: [card],
      history: () =>
        jsonResponse(historyBody([series, seriesOf(NORMAL, [], null)], { to: '2026-10-12' })),
    });
    visit(cardVariantPath('base1-4', FIRST));
    render(<App />);
    const section = await screen.findByRole('region', { name: 'Price history' });
    await within(section).findByText(
      'No daily close for this variant between 2026-09-13 and 2026-10-12.',
    );
    expect(recorder.charts).toHaveLength(0);
    expect(document.body.textContent).not.toMatch(/321|322/);
    expect(within(hero()).getByText('No recent sales; lowest listing $100,000.00')).toBeTruthy();
    expect(amounts()).toEqual(amounts().map(() => '$100,000.00'));
    nothingBroken();
  });

  const failing: readonly (readonly [string, () => Response | Error])[] = [
    ['a 500 problem', () => problemResponse(serverErrorProblem('/v1/cards/base1-4/prices'))],
    ['a 503 problem', () => problemResponse(upstreamUnavailableProblem)],
    ['a 404 problem', () => problemResponse(cardNotFoundProblem)],
    ['a 422 problem', () => problemResponse(validationProblem)],
    ['an HTML 502', () => rawResponse(502, '<html>Bad gateway</html>', 'text/html')],
    ['a request that reaches no server', () => new Error('network down')],
  ];

  it.each(failing)(
    'keeps the page, shows one problem notice in the history panel and draws no chart for %s',
    async (...row_: readonly [string, () => Response | Error]) => {
      const [, answer] = row_;
      serve({ cards: [card], history: () => answer() });
      visit(cardVariantPath('base1-4', FIRST));
      render(<App />);
      const section = await screen.findByRole('region', { name: 'Price history' });
      const alert = await within(section).findByRole('alert');
      expect(alert.textContent).toContain('Could not load the price history');
      expect(screen.getAllByRole('alert')).toHaveLength(1);
      expect(screen.queryByText(/Could not load this card/)).toBeNull();
      expect(recorder.charts).toHaveLength(0);
      expect(within(hero()).getByText('No recent sales; lowest listing $100,000.00')).toBeTruthy();
      expect(
        within(hero()).getByText('Listing as of 2026-10-13 from TCGCSV (daily snapshot)'),
      ).toBeTruthy();
      expect(
        within(row('holo, 1st')).getByText('No recent sales; lowest listing $100,000.00'),
      ).toBeTruthy();
      expect(screen.getByRole('region', { name: 'Sources' })).toBeTruthy();
      expect(document.body.textContent).not.toMatch(/NaN|undefined|\$null/);
      fireEvent.click(within(section).getByRole('button', { name: '1Y' }));
      expect(screen.getAllByText(/^Market price · /)).toHaveLength(1);
    },
  );
});

describe('the quiet actions on a card with a listing and no close', () => {
  const KEY = 'base1-4%3Aholo%3A1st%3A-';
  const card = cardOf(variantOf(FIRST, null, listingOf('100000.00')), variantOf(NORMAL, null));

  afterEach(() => {
    clearXsrfCookie();
  });

  it('offers both buttons on the listed and the bare row, sends nothing before a click, and a watchlist add leaves the hero and the listing as they were', async () => {
    const calls = stubAccountFetch({
      'GET /v1/prices/freshness': jsonResponse(freshnessBody({ within_slo: true, age_hours: 3.5 })),
      'GET /v1/cards/base1-4': jsonResponse(card),
      'GET /v1/cards/base1-4/prices?range=1M': jsonResponse(historyFor(card)),
      [`PUT /v1/watchlist/${KEY}`]: jsonResponse({ data: { variant_key: FIRST } }),
    });
    visit('/card/base1-4');
    render(<App />);
    const block = await waitForHero();
    const prices = screen.getByRole('region', { name: 'Prices' });
    for (const label of ['holo, 1st', 'normal, unlimited']) {
      expect(
        within(prices).getByRole('button', { name: `Add ${label} to your watchlist` }),
      ).toBeTruthy();
      expect(
        within(prices).getByRole('button', { name: `Add ${label} to your portfolio` }),
      ).toBeTruthy();
    }
    await settle();
    expect(calls.map((call) => call.key)).toEqual([
      'GET /v1/prices/freshness',
      'GET /v1/cards/base1-4',
      'GET /v1/cards/base1-4/prices?range=1M',
    ]);

    fireEvent.click(
      within(prices).getByRole('button', { name: 'Add holo, 1st to your watchlist' }),
    );
    expect(await within(row('holo, 1st')).findByText('On your watchlist.')).toBeTruthy();
    expect(within(block).getByText('No recent sales; lowest listing $100,000.00')).toBeTruthy();
    expect(
      within(row('holo, 1st')).getByText('No recent sales; lowest listing $100,000.00'),
    ).toBeTruthy();
    expect(calls.filter((call) => call.key === `PUT /v1/watchlist/${KEY}`)).toHaveLength(1);
    nothingBroken();
    everyListingHasItsDay();
  });
});

describe('a priced card is read as before', () => {
  it('keeps the first priced variant as the default and the thin-market note beside a variant with only a listing', async () => {
    const thin: VariantPrice = {
      ...closeOf300,
      close: '1000.00',
      previous_close: '1000.00',
      previous_day: '2026-10-11',
      change: '0.00',
      change_pct: '0.00',
      lowest_listing: '2500.00',
      thin_market: true,
    };
    serve({
      cards: [
        cardOf(
          variantOf(FIRST, null, listingOf('100000.00')),
          variantOf(UNLIMITED, thin),
          variantOf(NORMAL, null),
        ),
      ],
    });
    visit('/card/base1-4');
    render(<App />);
    const block = await waitForHero();
    expect(within(block).getByText('Market price · holo, unlimited')).toBeTruthy();
    expect(within(block).getByText('$1,000.00')).toBeTruthy();
    expect(within(block).getByText('Lowest listing $2,500.00')).toBeTruthy();
    expect(
      within(block).getByText(
        'Thin market: the market price is from few or old sales; the lowest listing in the same snapshot is $2,500.00.',
      ),
    ).toBeTruthy();
    expect(block.textContent).not.toMatch(/No recent sales|\$100,000/);
    expect(
      within(row('holo, 1st')).getByText('No recent sales; lowest listing $100,000.00'),
    ).toBeTruthy();
    expect(within(row('normal, unlimited')).getByText(NO_PRICE)).toBeTruthy();
    nothingBroken();
  });
});
