// The lowest listing on the card page: beside the market price, a thin-market note when the API
// flags the price, the "no recent sales" sentence for a variant with a listing and no close, and a
// link naming such a variant opening on it. Every amount, day and flag is the API's.
import { cleanup, render, screen, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi, type MockInstance } from 'vitest';
import App from '../App';
import type { CardDetail } from '../api/client';
import { cardVariantPath } from '../routes';
import {
  FORBIDDEN_WORDS,
  firstClosePrice,
  freshnessBody,
  historyFor,
  isFreshnessUrl,
  isHistoryUrl,
  jsonResponse,
  risingPrice,
  stubFetch,
  unownCard,
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

const THIN_NOTE =
  'Thin market: the market price is from few or old sales; the lowest listing in the same snapshot is $2,500.00.';
const NO_SALES = 'No recent sales; lowest listing $100,000.00';
const LISTING_AS_OF = 'Listing as of 2026-10-13 from TCGCSV (daily snapshot)';

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

const firstListing = {
  source: 'tcgplayer',
  feed: 'tcgcsv',
  currency: 'USD',
  cadence: 'daily',
  lowest_listing: '100000.00',
  day: '2026-10-13',
  as_of: '2026-10-13T20:05:44Z',
} satisfies NonNullable<Variant['listing']>;

/** 1st Edition held flat at 1000.00 against a 2500.00 lowest listing: flagged by the API. */
const thinPrice: VariantPrice = {
  ...risingPrice,
  close: '1000.00',
  previous_close: '1000.00',
  change: '0.00',
  change_pct: '0.00',
  lowest_listing: '2500.00',
  thin_market: true,
};

/** Unlimited at 300.00 with a 280.00 lowest listing: listed, not flagged. */
const listedPrice: VariantPrice = {
  ...firstClosePrice,
  close: '300.00',
  lowest_listing: '280.00',
  thin_market: false,
};

/** Shadowless at 2257.87, no listing in its snapshot. */
const shadowlessPrice: VariantPrice = { ...firstClosePrice, close: '2257.87' };

/** Charizard as dev holds it: two priced prints, 1st Edition listed with no close, one with neither. */
const unpricedFirstCard = cardOf(
  variantOf(SHADOWLESS, shadowlessPrice),
  variantOf(UNLIMITED, listedPrice),
  variantOf(FIRST, null, firstListing),
  variantOf(NORMAL, null),
);

function serve(card: CardDetail): FetchCall[] {
  return stubFetch((url) => {
    if (isFreshnessUrl(url))
      return jsonResponse(freshnessBody({ within_slo: true, age_hours: 3.5 }));
    if (isHistoryUrl(url)) return jsonResponse(historyFor(card));
    if (url === '/v1/cards/base1-4') return jsonResponse(card);
    return new Error(`no request expected: ${url}`);
  });
}

function visit(path: string): void {
  window.history.replaceState(null, '', path);
}

async function hero(): Promise<HTMLElement> {
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

function row(label: string): HTMLElement {
  const prices = screen.getByRole('region', { name: 'Prices' });
  const item = within(prices)
    .getAllByRole('listitem')
    .find((one) => one.querySelector('.capitalize')?.textContent === label);
  if (item === undefined) throw new Error(`no Prices row for ${label}`);
  return item;
}

function forbiddenIn(text: string): string[] {
  return FORBIDDEN_WORDS.filter((word) => word.test(text)).map(String);
}

let consoleError: MockInstance<typeof console.error>;

beforeEach(() => {
  resetRecorder();
  consoleError = vi.spyOn(console, 'error');
});

afterEach(() => {
  cleanup();
  consoleError.mockRestore();
  visit('/');
});

describe('the lowest listing beside the market price', () => {
  it('shows the lowest listing under the hero price and in its Prices row, with no note when not flagged', async () => {
    serve(cardOf(variantOf(UNLIMITED, listedPrice)));
    visit('/card/base1-4');
    render(<App />);
    const block = await hero();
    expect(within(block).getByText('$300.00')).toBeTruthy();
    expect(within(block).getByText('Lowest listing $280.00')).toBeTruthy();
    expect(within(block).getByText('as of 2026-10-12 from TCGCSV (daily close)')).toBeTruthy();
    expect(within(row('holo, unlimited')).getByText('Lowest listing $280.00')).toBeTruthy();
    expect(screen.queryByText(/^Thin market/)).toBeNull();
    expect(consoleError).not.toHaveBeenCalled();
  });

  it('adds the thin-market note only when the price is flagged, keeping the source and the as-of line', async () => {
    serve(cardOf(variantOf(FIRST, thinPrice)));
    visit('/card/base1-4');
    render(<App />);
    const block = await hero();
    expect(within(block).getByText('$1,000.00')).toBeTruthy();
    expect(within(block).getByText('Lowest listing $2,500.00')).toBeTruthy();
    expect(within(block).getByText(THIN_NOTE)).toBeTruthy();
    expect(within(block).getByText('as of 2026-10-12 from TCGCSV (daily close)')).toBeTruthy();
    const line = within(row('holo, 1st'));
    expect(line.getByText('Market price $1,000.00')).toBeTruthy();
    expect(line.getByText(THIN_NOTE)).toBeTruthy();
    expect(line.getByText('as of 2026-10-12 from TCGCSV (daily close)')).toBeTruthy();
    expect(
      screen.getByText('TCGplayer market prices via TCGCSV, collected once a day.'),
    ).toBeTruthy();
    expect(forbiddenIn(document.body.textContent)).toEqual([]);
  });

  it('shows no listing line and no note when the price has no lowest listing', async () => {
    serve(cardOf(variantOf(SHADOWLESS, shadowlessPrice)));
    visit('/card/base1-4');
    render(<App />);
    await hero();
    expect(screen.queryByText(/Lowest listing/i)).toBeNull();
    expect(screen.queryByText(/^Thin market/)).toBeNull();
  });
});

describe('a variant with a listing and no close', () => {
  it('reads "No recent sales; lowest listing $X" with its listing day and source in its Prices row', async () => {
    serve(unpricedFirstCard);
    visit('/card/base1-4');
    render(<App />);
    await hero();
    const first = within(row('holo, 1st'));
    expect(first.getByText(NO_SALES)).toBeTruthy();
    expect(first.getByText(LISTING_AS_OF)).toBeTruthy();
    expect(first.queryByText('No price in the collected snapshots')).toBeNull();
    // A variant with neither a close nor a listing keeps the no-price wording.
    expect(
      within(row('normal, unlimited')).getByText('No price in the collected snapshots'),
    ).toBeTruthy();
    expect(forbiddenIn(document.body.textContent)).toEqual([]);
  });
});

describe('a link naming a variant the card has opens it, priced or not', () => {
  it('opens the named listed variant with no close: its sentence in the hero, no other price, no chart', async () => {
    serve(unpricedFirstCard);
    visit(cardVariantPath('base1-4', FIRST));
    render(<App />);
    const block = await hero();
    expect(within(block).getByText('Market price · holo, 1st')).toBeTruthy();
    expect(within(block).getByText(NO_SALES)).toBeTruthy();
    expect(within(block).getByText(LISTING_AS_OF)).toBeTruthy();
    expect(block.textContent).not.toMatch(/\$2,257\.87|\$300\.00|previous close/);
    const section = await historySection();
    expect(pressed(section)).toEqual(['holo, 1st']);
    expect(
      within(section).getByText(
        'No daily close for this variant between 2026-09-13 and 2026-10-12.',
      ),
    ).toBeTruthy();
    expect(section.querySelector('p.sr-only')).toBeNull();
    expect(recorder.charts).toHaveLength(0);
    expect(screen.queryByRole('alert')).toBeNull();
    expect(forbiddenIn(document.body.textContent)).toEqual([]);
    expect(consoleError).not.toHaveBeenCalled();
  });

  it('opens the named variant with neither a close nor a listing on the no-price wording', async () => {
    serve(unpricedFirstCard);
    visit(cardVariantPath('base1-4', NORMAL));
    render(<App />);
    const block = await hero();
    expect(within(block).getByText('Market price · normal, unlimited')).toBeTruthy();
    expect(within(block).getByText('No price in the collected snapshots')).toBeTruthy();
    expect(block.textContent).not.toMatch(/\$/);
    const section = await historySection();
    expect(pressed(section)).toEqual(['normal, unlimited']);
    expect(section.querySelector('p.sr-only')).toBeNull();
    expect(consoleError).not.toHaveBeenCalled();
  });

  it('still opens the default for an unknown key and for no parameter', async () => {
    for (const path of [
      '/card/base1-4?variant=base1-4%3Aholo%3A2nd%3A-',
      '/card/base1-4?variant=nope',
      '/card/base1-4',
    ]) {
      serve(unpricedFirstCard);
      visit(path);
      render(<App />);
      const block = await hero();
      expect(within(block).getByText('Market price · holo, shadowless'), path).toBeTruthy();
      expect(within(block).getByText('$2,257.87'), path).toBeTruthy();
      expect(pressed(await historySection()), path).toEqual(['holo, shadowless']);
      cleanup();
    }
  });
});
