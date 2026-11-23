// Adversarial cases for the lowest listing and the variant a link names: a variant that gains or loses its
// close on a poll while the page shows it (what is on screen, and the address), a named variant with no close
// against a failing history request, a choice made away from it then Back and Forward, answers of the wrong kind
// from the API, amounts at the extremes, and the "as of" of a listing against that of a close.
import { act, cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi, type MockInstance } from 'vitest';
import App from '../App';
import type { CardDetail } from '../api/client';
import { cardVariantPath } from '../routes';
import {
  FORBIDDEN_WORDS,
  answerMovers,
  firstClosePrice,
  freshnessBody,
  historyFor,
  isFreshnessUrl,
  isHistoryUrl,
  isMoversUrl,
  jsonResponse,
  problemResponse,
  risingPrice,
  stubFetch,
  unownCard,
  upstreamUnavailableProblem,
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

const THIN_NOTE =
  'Thin market: the market price is from few or old sales; the lowest listing in the same snapshot is $2,500.00.';

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

const thinPrice: VariantPrice = {
  ...risingPrice,
  close: '1000.00',
  previous_close: '1000.00',
  change: '0.00',
  change_pct: '0.00',
  lowest_listing: '2500.00',
  thin_market: true,
};
const listedPrice: VariantPrice = {
  ...firstClosePrice,
  close: '300.00',
  lowest_listing: '280.00',
  thin_market: false,
};
const shadowlessPrice: VariantPrice = { ...firstClosePrice, close: '2257.87' };

/** Shadowless and unlimited priced, 1st Edition and normal not. */
function cardWith(
  unlimited: VariantPrice | null,
  first: VariantPrice | null,
  unlimitedListing?: Variant['listing'],
  firstListing?: Variant['listing'],
): CardDetail {
  return cardOf(
    variantOf(SHADOWLESS, shadowlessPrice),
    variantOf(UNLIMITED, unlimited, unlimitedListing),
    variantOf(FIRST, first, firstListing),
    variantOf(NORMAL, null),
  );
}

let visibility: DocumentVisibilityState = 'visible';

/** Answers the card from `cards` in order (the last repeats), its history from the card answered last. */
function serve(cards: readonly CardDetail[], historyStatus: 'ok' | 'down' = 'ok'): void {
  let asked = 0;
  let current: CardDetail | undefined = cards[0];
  stubFetch((url) => {
    if (isFreshnessUrl(url)) {
      return jsonResponse(freshnessBody({ within_slo: true, age_hours: 3.5 }));
    }
    if (isMoversUrl(url)) return answerMovers(url);
    if (isHistoryUrl(url)) {
      return historyStatus === 'down' || current === undefined
        ? problemResponse(upstreamUnavailableProblem)
        : jsonResponse(historyFor(current));
    }
    if (url === '/v1/cards/base1-4') {
      current = cards[Math.min(asked, cards.length - 1)];
      asked += 1;
      return jsonResponse(current);
    }
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
function hero(): HTMLElement {
  const label = screen.getByText(/^Market price · /);
  const block = label.parentElement;
  if (block === null) throw new Error('the hero label has no block');
  return block;
}

async function waitForHero(): Promise<HTMLElement> {
  const label = await screen.findByText(/^Market price · /);
  const block = label.parentElement;
  if (block === null) throw new Error('the hero label has no block');
  return block;
}

async function historySection(): Promise<HTMLElement> {
  const section = await screen.findByRole('region', { name: 'Price history' });
  await within(section).findByText(/^as of /);
  return section;
}

function pressed(section: HTMLElement): string[] {
  const group = within(section).getByRole('group', { name: 'Variant' });
  return within(group)
    .getAllByRole('button')
    .filter((button) => button.getAttribute('aria-pressed') === 'true')
    .map((button) => button.textContent);
}

/** The closes the chart was last given, as numbers. */
function drawnValues(): number[] {
  const data = recorder.charts.at(-1)?.series.at(-1)?.data.at(-1);
  if (!Array.isArray(data)) return [];
  return data.map((point: unknown) => (point as { value: number }).value);
}

function row(label: string): HTMLElement {
  const prices = screen.getByRole('region', { name: 'Prices' });
  const item = within(prices)
    .getAllByRole('listitem')
    .find((one) => one.querySelector('.capitalize')?.textContent === label);
  if (item === undefined) throw new Error(`no Prices row for ${label}`);
  return item;
}

let consoleError: MockInstance<typeof console.error>;

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

describe('a variant that gains or loses its close while the page shows it', () => {
  beforeEach(() => {
    vi.useFakeTimers({ toFake: ['setInterval', 'clearInterval', 'Date'] });
    vi.setSystemTime(new Date('2031-02-03T04:05:06Z'));
  });

  it("falls back to the default when the named variant loses its close, showing only the default's figures, and returns to it when its close comes back", async () => {
    const priced = cardWith(listedPrice, null);
    const lost = cardWith(null, null, listingOf('275.00', '2026-10-14'));
    serve([priced, lost, priced]);
    visit(cardVariantPath('base1-4', UNLIMITED));
    const length = window.history.length;
    render(<App />);
    await settle();
    expect(within(hero()).getByText('Market price · holo, unlimited')).toBeTruthy();
    expect(within(hero()).getByText('$300.00')).toBeTruthy();

    await advance(300_000);
    // The hero, the pressed segment and every figure on screen are the default variant's.
    const block = hero();
    expect(within(block).getByText('Market price · holo, shadowless')).toBeTruthy();
    expect(within(block).getByText('$2,257.87')).toBeTruthy();
    expect(block.textContent).not.toMatch(/\$300\.00|\$280\.00|\$275\.00|No recent sales/);
    // One priced variant is left: no Variant group, and the chart draws the default's close only.
    const section = await historySection();
    expect(within(section).queryByRole('group', { name: 'Variant' })).toBeNull();
    expect(drawnValues()).toEqual([2257.87]);
    // The variant that lost its close reads as a variant with a listing and no close, with its own day.
    const lostRow = within(row('holo, unlimited'));
    expect(lostRow.getByText('No recent sales; lowest listing $275.00')).toBeTruthy();
    expect(lostRow.getByText('Listing as of 2026-10-14 from TCGCSV (daily snapshot)')).toBeTruthy();
    expect(lostRow.queryByText(/\$300\.00/)).toBeNull();
    // The address was not rewritten by the poll, and no history step was added.
    expect(address()).toBe(cardVariantPath('base1-4', UNLIMITED));
    expect(window.history.length).toBe(length);

    await advance(300_000);
    // The address still names it, so it opens again with its own close, change and day.
    expect(within(hero()).getByText('Market price · holo, unlimited')).toBeTruthy();
    expect(within(hero()).getByText('$300.00')).toBeTruthy();
    expect(within(hero()).getByText('Lowest listing $280.00')).toBeTruthy();
    expect(consoleError).not.toHaveBeenCalled();
  });

  it('switches the hero from the listing sentence to the close, its listing, note and as-of line when the named variant gets a close', async () => {
    const before = cardWith(listedPrice, null, undefined, listingOf('2500.00'));
    const after = cardWith(listedPrice, thinPrice);
    serve([before, after]);
    visit(cardVariantPath('base1-4', FIRST));
    render(<App />);
    await settle();
    expect(within(hero()).getByText('No recent sales; lowest listing $2,500.00')).toBeTruthy();

    await advance(300_000);
    const block = hero();
    expect(within(block).getByText('Market price · holo, 1st')).toBeTruthy();
    expect(within(block).getByText('$1,000.00')).toBeTruthy();
    expect(within(block).getByText('Lowest listing $2,500.00')).toBeTruthy();
    expect(within(block).getByText(THIN_NOTE)).toBeTruthy();
    expect(within(block).getByText('as of 2026-10-12 from TCGCSV (daily close)')).toBeTruthy();
    expect(block.textContent).not.toMatch(/No recent sales|Listing as of/);
    expect(pressed(await historySection())).toEqual(['holo, 1st']);
    expect(within(row('holo, 1st')).queryByText(/No recent sales/)).toBeNull();
    expect(consoleError).not.toHaveBeenCalled();
  });

  it('drops the unlisted sentence for the listing sentence when a poll brings the named variant a listing, and back', async () => {
    const bare = cardWith(listedPrice, null);
    const listed = cardWith(listedPrice, null, undefined, listingOf('120000.00'));
    serve([bare, listed, bare]);
    visit(cardVariantPath('base1-4', FIRST));
    render(<App />);
    await settle();
    expect(within(hero()).getByText('No price in the collected snapshots')).toBeTruthy();
    await advance(300_000);
    expect(within(hero()).getByText('No recent sales; lowest listing $120,000.00')).toBeTruthy();
    expect(hero().textContent).not.toMatch(/No price in the collected snapshots/);
    await advance(300_000);
    expect(within(hero()).getByText('No price in the collected snapshots')).toBeTruthy();
    expect(hero().textContent).not.toMatch(/\$/);
  });
});

describe('a named variant with no close', () => {
  it('opens on first load with the address left as the link wrote it and no extra history step', async () => {
    serve([cardWith(listedPrice, null, undefined, listingOf('100000.00'))]);
    visit(cardVariantPath('base1-4', FIRST));
    const length = window.history.length;
    render(<App />);
    const block = await waitForHero();
    expect(within(block).getByText('Market price · holo, 1st')).toBeTruthy();
    expect(address()).toBe(cardVariantPath('base1-4', FIRST));
    expect(window.history.length).toBe(length);
  });

  it('keeps its own listing day and the snapshot wording, never the "daily close" line or a previous close', async () => {
    serve([cardWith(listedPrice, null, undefined, listingOf('100000.00', '2026-10-14'))]);
    visit(cardVariantPath('base1-4', FIRST));
    render(<App />);
    const block = await waitForHero();
    expect(
      within(block).getByText('Listing as of 2026-10-14 from TCGCSV (daily snapshot)'),
    ).toBeTruthy();
    expect(block.textContent).not.toMatch(/daily close|previous close|▲|▼|Market price \$/);
    for (const word of FORBIDDEN_WORDS) expect(document.body.textContent).not.toMatch(word);
  });

  it('keeps the hero on the named variant when the history request fails, without an alert or a crash', async () => {
    serve([cardWith(listedPrice, null, undefined, listingOf('100000.00'))], 'down');
    visit(cardVariantPath('base1-4', FIRST));
    render(<App />);
    const block = await waitForHero();
    expect(within(block).getByText('No recent sales; lowest listing $100,000.00')).toBeTruthy();
    expect(block.textContent).not.toMatch(/\$2,257\.87|\$300\.00/);
    await settle();
    expect(screen.queryByText(/NaN|undefined/)).toBeNull();
    expect(recorder.charts).toHaveLength(0);
    expect(hero()).toBe(block);
  });

  it('opens another variant on a choice, replaces the address with no history step, and Back and Forward follow the choice', async () => {
    serve([cardWith(listedPrice, null, undefined, listingOf('100000.00'))]);
    // The card is entered from the home page, so Back has somewhere to go.
    window.history.replaceState(null, '', '/');
    window.history.pushState(null, '', cardVariantPath('base1-4', FIRST));
    render(<App />);
    const section = await historySection();
    expect(pressed(section)).toEqual(['holo, 1st']);
    const length = window.history.length;
    const group = within(section).getByRole('group', { name: 'Variant' });
    fireEvent.click(within(group).getByRole('button', { name: 'holo, shadowless' }));
    expect(address()).toBe(cardVariantPath('base1-4', SHADOWLESS));
    expect(window.history.length).toBe(length);
    expect(within(hero()).getByText('Market price · holo, shadowless')).toBeTruthy();
    expect(within(hero()).getByText('$2,257.87')).toBeTruthy();
    expect(hero().textContent).not.toMatch(/No recent sales|\$100,000\.00/);
    expect(pressed(section)).toEqual(['holo, shadowless']);

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
      expect(address()).toBe(cardVariantPath('base1-4', SHADOWLESS));
    });
    const block = await waitForHero();
    expect(within(block).getByText('Market price · holo, shadowless')).toBeTruthy();
    expect(block.textContent).not.toMatch(/No recent sales/);
    expect(consoleError).not.toHaveBeenCalled();
  });

  it.each([
    ['an empty key', '/card/base1-4?variant='],
    [
      'a key of another card',
      `/card/base1-4?variant=${encodeURIComponent('ex10-?:holo:unlimited:-')}`,
    ],
    ['a key with a space around it', `/card/base1-4?variant=${encodeURIComponent(` ${FIRST} `)}`],
    ['a key in the wrong case', `/card/base1-4?variant=${encodeURIComponent(FIRST.toUpperCase())}`],
  ])('opens the default, without an error, for %s', async (...row: readonly [string, string]) => {
    const [, path] = row;
    serve([cardWith(listedPrice, null, undefined, listingOf('100000.00'))]);
    visit(path);
    render(<App />);
    const block = await waitForHero();
    expect(within(block).getByText('Market price · holo, shadowless')).toBeTruthy();
    expect(within(block).getByText('$2,257.87')).toBeTruthy();
    expect(block.textContent).not.toMatch(/No recent sales/);
    expect(screen.queryByRole('alert')).toBeNull();
    expect(consoleError).not.toHaveBeenCalled();
  });
});

describe('what the page does with a price block or listing of the wrong kind', () => {
  const cases: readonly (readonly [string, (card: CardDetail) => CardDetail])[] = [
    [
      'lowest_listing a number',
      () => cardOf(variantOf(UNLIMITED, { ...thinPrice, lowest_listing: 2500 as never })),
    ],
    [
      'thin_market a string',
      () => cardOf(variantOf(UNLIMITED, { ...thinPrice, thin_market: 'yes' as never })),
    ],
    [
      'thin_market a number',
      () => cardOf(variantOf(UNLIMITED, { ...thinPrice, thin_market: 1 as never })),
    ],
    ['listing null', () => cardOf(variantOf(UNLIMITED, null, null as never))],
    [
      'listing without its day',
      () => cardOf(variantOf(UNLIMITED, null, { ...listingOf('5.00'), day: undefined } as never)),
    ],
    [
      'listing amount a number',
      () =>
        cardOf(variantOf(UNLIMITED, null, { ...listingOf('5.00'), lowest_listing: 5 } as never)),
    ],
  ];

  it.each(cases)(
    'shows the problem block, no price, and no NaN or undefined for %s',
    async (...row: readonly [string, (card: CardDetail) => CardDetail]) => {
      const [, build] = row;
      serve([build(cardOf())]);
      visit('/card/base1-4');
      render(<App />);
      const alert = await screen.findByRole('alert');
      expect(alert.textContent).toContain('Could not load this card');
      expect(screen.queryByText(/^Market price/)).toBeNull();
      expect(document.body.textContent).not.toMatch(/NaN|undefined|\$1,000|\$2,500/);
    },
  );

  it('shows no note and no listing line when the API flags a thin market but names no listing', async () => {
    serve([cardOf(variantOf(FIRST, { ...thinPrice, lowest_listing: null }))]);
    visit('/card/base1-4');
    render(<App />);
    const block = await waitForHero();
    expect(within(block).getByText('$1,000.00')).toBeTruthy();
    expect(screen.queryByText(/^Thin market/)).toBeNull();
    expect(screen.queryByText(/Lowest listing/)).toBeNull();
    expect(document.body.textContent).not.toMatch(/NaN|undefined|\$null/);
  });

  it('shows the listing without a note when it is present and the API does not flag the price', async () => {
    serve([cardOf(variantOf(FIRST, { ...thinPrice, thin_market: false }))]);
    visit('/card/base1-4');
    render(<App />);
    const block = await waitForHero();
    expect(within(block).getByText('Lowest listing $2,500.00')).toBeTruthy();
    expect(screen.queryByText(/^Thin market/)).toBeNull();
  });
});

describe('amounts at the extremes', () => {
  it('groups the largest amount the columns hold and the smallest, in the listing line and the sentence', async () => {
    serve([
      cardOf(
        variantOf(UNLIMITED, {
          ...risingPrice,
          close: '9999999999.99',
          lowest_listing: '9999999999.99',
          thin_market: false,
        }),
        variantOf(FIRST, null, listingOf('0.01')),
        variantOf(NORMAL, null, listingOf('9999999999.99', '2026-10-14')),
      ),
    ]);
    visit('/card/base1-4');
    render(<App />);
    const block = await waitForHero();
    expect(within(block).getByText('Lowest listing $9,999,999,999.99')).toBeTruthy();
    expect(
      within(row('holo, 1st')).getByText('No recent sales; lowest listing $0.01'),
    ).toBeTruthy();
    expect(
      within(row('normal, unlimited')).getByText(
        'No recent sales; lowest listing $9,999,999,999.99',
      ),
    ).toBeTruthy();
    expect(document.body.textContent).not.toMatch(/NaN|undefined|e\+/);
  });

  it('writes the note with the listing it names and no word that calls the listing a price, a value or a sale', async () => {
    serve([cardOf(variantOf(FIRST, thinPrice))]);
    visit('/card/base1-4');
    render(<App />);
    const block = await waitForHero();
    const note = within(block).getByText(THIN_NOTE);
    // The listing is named a listing; the note says nothing is "worth" or "sold at" it.
    expect(note.textContent).toMatch(/lowest listing in the same snapshot is \$2,500\.00\.$/);
    expect(note.textContent).not.toMatch(/worth|value|sold|sells|price of \$2,500/i);
    for (const word of FORBIDDEN_WORDS) expect(note.textContent).not.toMatch(word);
  });
});
