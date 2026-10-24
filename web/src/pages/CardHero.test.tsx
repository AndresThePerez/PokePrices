import { cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import App from '../App';
import type { CardDetail } from '../api/client';
import {
  cardBody,
  fallingPrice,
  historyBody,
  isHistoryUrl,
  jsonResponse,
  pointOf,
  pricedCardBody,
  pricedUnownCard,
  risingPrice,
  seriesOf,
  stubFetch,
} from '../test/fixtures';

// jsdom has no canvas: the chart library is replaced by its recording fake (T06 helper).
vi.mock('lightweight-charts', () => import('../test/lightweightChartsFake'));

const CARD_URL = '/v1/cards/ex10-%3F';
const HISTORY_1M = '/v1/cards/ex10-%3F/prices?range=1M';
const HISTORY_3M = '/v1/cards/ex10-%3F/prices?range=3M';
const HOLO = 'ex10-?:holo:unlimited:-';
const REVERSE = 'ex10-?:reverse:unlimited:-';
const HOLO_CHANGE = '▲ +$1.45 (+2.09%) vs previous close (2026-10-11)';
const HOLO_AS_OF = 'as of 2026-10-12 from TCGCSV (daily close)';
const REVERSE_CHANGE = '▼ -$1.30 (-1.87%) vs previous close (2026-10-08)';
const REVERSE_AS_OF = 'as of 2026-10-09 from TCGCSV (daily close)';
const NO_CLOSE = 'No daily close for this variant between 2026-09-13 and 2026-10-12.';

/** Holo rising on 2026-10-12, reverse falling on 2026-10-09: both priced, in that card order. */
const twoPricedCard = {
  data: {
    ...pricedUnownCard,
    variants: pricedUnownCard.variants.map((variant) => ({
      ...variant,
      price:
        variant.variant_key === HOLO
          ? risingPrice
          : { ...fallingPrice, day: '2026-10-09', previous_day: '2026-10-08' },
    })),
  },
  meta: { degraded: false },
} satisfies CardDetail;

const holoSeries = seriesOf(HOLO, [pointOf('2026-10-11', '69.36'), pointOf('2026-10-12', '70.81')]);
const reverseSeries = seriesOf(REVERSE, [
  pointOf('2026-10-08', '69.36'),
  pointOf('2026-10-09', '68.06'),
]);

function visit(path: string): void {
  window.history.replaceState(null, '', path);
}

function serve(card: CardDetail, reply: (url: string) => Response): string[] {
  const urls: string[] = [];
  stubFetch((url) => {
    urls.push(url);
    return isHistoryUrl(url) ? reply(url) : jsonResponse(card);
  });
  return urls;
}

/** Opens the card and waits for the history section to settle on its stamp line. */
async function open(): Promise<HTMLElement> {
  visit('/card/ex10-%3F');
  render(<App />);
  const section = await screen.findByRole('region', { name: 'Price history' });
  await within(section).findByText(/^as of /);
  return section;
}

/** The hero: the block led by its "Market price · <variant>" label line. */
function hero(): HTMLElement {
  const label = screen.getByText(/^Market price · /);
  const block = label.parentElement;
  if (block === null) throw new Error('the hero label has no block');
  return block;
}

function summary(section: HTMLElement): string {
  return section.querySelector('p.sr-only')?.textContent ?? '';
}

afterEach(() => {
  cleanup();
  visit('/');
});

describe('Card hero driven by the one variant choice', () => {
  it('heads the page with the first eligible priced variant: label, figure alone, chip and as-of, outside Prices', async () => {
    const urls = serve(twoPricedCard, () => jsonResponse(historyBody([holoSeries, reverseSeries])));
    const section = await open();
    const block = hero();
    expect(within(block).getByText('Market price · holo, unlimited')).toBeTruthy();
    const figure = within(block).getByText('$70.81');
    expect(figure.childNodes).toHaveLength(1);
    expect(within(block).getByText(HOLO_CHANGE)).toBeTruthy();
    expect(within(block).getByText(HOLO_AS_OF)).toBeTruthy();
    const prices = screen.getByRole('region', { name: 'Prices' });
    expect(prices.contains(block)).toBe(false);
    expect(section.contains(block)).toBe(false);
    expect(summary(section)).toMatch(/^Holo, unlimited, 1M: /);
    expect(urls).toEqual([CARD_URL, HISTORY_1M]);
  });

  it('moves the hero figure, chip and as-of with the chart when another Variant segment is pressed, asking nothing new', async () => {
    const urls = serve(twoPricedCard, () => jsonResponse(historyBody([holoSeries, reverseSeries])));
    const section = await open();
    const variants = within(section).getByRole('group', { name: 'Variant' });
    fireEvent.click(within(variants).getByRole('button', { name: 'reverse, unlimited' }));
    const block = hero();
    expect(within(block).getByText('Market price · reverse, unlimited')).toBeTruthy();
    expect(within(block).getByText('$68.06')).toBeTruthy();
    expect(within(block).getByText(REVERSE_CHANGE)).toBeTruthy();
    expect(within(block).getByText(REVERSE_AS_OF)).toBeTruthy();
    expect(within(block).queryByText('$70.81')).toBeNull();
    expect(within(block).queryByText(HOLO_CHANGE)).toBeNull();
    expect(summary(section)).toMatch(/^Reverse, unlimited, 1M: /);
    expect(urls).toEqual([CARD_URL, HISTORY_1M]);
  });

  it('never draws another variant than the hero: a range without the chosen series shows the no-close line', async () => {
    const urls = serve(twoPricedCard, (url) =>
      jsonResponse(
        url.endsWith('=3M')
          ? historyBody([holoSeries, seriesOf(REVERSE, [], null)])
          : historyBody([holoSeries, reverseSeries]),
      ),
    );
    const section = await open();
    const variants = within(section).getByRole('group', { name: 'Variant' });
    fireEvent.click(within(variants).getByRole('button', { name: 'reverse, unlimited' }));
    fireEvent.click(within(section).getByRole('button', { name: '3M' }));
    await within(section).findByText(NO_CLOSE);
    expect(summary(section)).toBe('');
    expect(within(hero()).getByText('$68.06')).toBeTruthy();
    expect(urls).toEqual([CARD_URL, HISTORY_1M, HISTORY_3M]);
  });

  it('offers no segment for an eligible variant that has no price', async () => {
    serve(pricedCardBody, () =>
      jsonResponse(
        historyBody([
          holoSeries,
          seriesOf(REVERSE, [pointOf('2026-10-12', '12.00')], '2026-09-01'),
        ]),
      ),
    );
    const section = await open();
    expect(within(section).queryByRole('group', { name: 'Variant' })).toBeNull();
    expect(within(section).queryByRole('button', { name: 'reverse, unlimited' })).toBeNull();
    expect(summary(section)).toMatch(/^Holo, unlimited, 1M: /);
    expect(within(hero()).getByText('$70.81')).toBeTruthy();
  });

  it('keeps the hero on a priced variant whose series is null and shows the no-close line, never another series', async () => {
    serve(pricedCardBody, () =>
      jsonResponse(
        historyBody([
          seriesOf(HOLO, [], null),
          seriesOf(REVERSE, [pointOf('2026-10-12', '12.00')]),
        ]),
      ),
    );
    const section = await open();
    expect(within(section).getByText(NO_CLOSE)).toBeTruthy();
    expect(summary(section)).toBe('');
    expect(section.textContent).not.toContain('12.00');
    const block = hero();
    expect(within(block).getByText('Market price · holo, unlimited')).toBeTruthy();
    expect(within(block).getByText('$70.81')).toBeTruthy();
  });

  it('shows no hero when no variant is priced', async () => {
    serve(cardBody, () => jsonResponse(historyBody([])));
    visit('/card/ex10-%3F');
    render(<App />);
    await screen.findByRole('heading', { level: 1, name: 'Unown' });
    expect(screen.queryByText(/^Market price/)).toBeNull();
    expect(screen.getByRole('main').textContent).not.toContain('$');
  });
});
