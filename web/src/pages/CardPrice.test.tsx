import { cleanup, render, screen, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import App from '../App';
import type { CardDetail } from '../api/client';
import {
  cardBody,
  cardWithPrices,
  centsPrice,
  fallingPrice,
  firstClosePrice,
  flatAtZeroPrice,
  downWithoutPctPrice,
  historyFor,
  isHistoryUrl,
  jsonResponse,
  millionPrice,
  pricedCardBody,
  risingPrice,
  stubFetch,
  thousandsPrice,
  unchangedPrice,
  unmappedCardBody,
  upFromZeroPrice,
} from '../test/fixtures';

// jsdom has no canvas: the chart library is replaced by its recording fake (T06 helper).
vi.mock('lightweight-charts', () => import('../test/lightweightChartsFake'));

const FOOTNOTE = 'TCGplayer market prices via TCGCSV, collected once a day.';
const AS_OF_1012 = 'as of 2026-10-12 from TCGCSV (daily close)';

function visit(path: string): void {
  window.history.replaceState(null, '', path);
}

/** Renders the card page over one canned body and returns the Prices section. */
async function prices(body: unknown): Promise<HTMLElement> {
  stubFetch((url) => jsonResponse(isHistoryUrl(url) ? historyFor(body as CardDetail) : body));
  visit('/card/ex10-%3F');
  render(<App />);
  await screen.findByRole('heading', { level: 1, name: 'Unown' });
  return screen.getByRole('region', { name: 'Prices' });
}

/** The list item of one variant in the Prices section, found by its label. */
function variantItem(section: HTMLElement, label: string): HTMLElement {
  const item = within(section).getByText(label).closest('li');
  if (item === null) throw new Error(`no list item for ${label}`);
  return item;
}

afterEach(() => {
  cleanup();
  visit('/');
});

describe('Card page prices', () => {
  it('shows the market price, an up change with its percentage, and the as-of day verbatim', async () => {
    const section = await prices(cardWithPrices(risingPrice));
    const item = variantItem(section, 'finish0, unlimited');
    expect(within(item).getByText('Market price $70.81')).toBeTruthy();
    const change = within(item).getByText('▲ +$1.45 (+2.09%) vs previous close (2026-10-11)');
    expect(change.className).toContain('text-up');
    expect(within(item).getByText(AS_OF_1012)).toBeTruthy();
    expect(section.textContent).not.toContain('No prices yet');
  });

  it('shows a down change with an arrow and an ASCII minus on both numbers, in red', async () => {
    const section = await prices(cardWithPrices(fallingPrice));
    expect(within(section).getByText('Market price $68.06')).toBeTruthy();
    const change = within(section).getByText('▼ -$1.30 (-1.87%) vs previous close (2026-10-11)');
    expect(change.className).toContain('text-down');
    expect(change.className).not.toContain('text-up');
  });

  it('says "No change" in neutral slate, with no arrow and no sign, for a zero change', async () => {
    const section = await prices(cardWithPrices(unchangedPrice));
    expect(within(section).getByText('Market price $2,257.87')).toBeTruthy();
    const change = within(section).getByText('No change vs previous close (2026-10-11)');
    expect(change.className).toContain('text-flat');
    expect(change.className).not.toMatch(/text-up|text-down/);
    expect(section.textContent).not.toMatch(/[▲▼]|\+\$|-\$|%/);
  });

  it('says "No previous close yet" in neutral slate when there is no previous close', async () => {
    const section = await prices(cardWithPrices(firstClosePrice));
    expect(within(section).getByText('Market price $70.81')).toBeTruthy();
    const change = within(section).getByText('No previous close yet');
    expect(change.className).toContain('text-flat');
    expect(section.textContent).not.toContain('vs previous close');
    expect(within(section).getByText(AS_OF_1012)).toBeTruthy();
  });

  it('omits the percentage, never computing it, when the previous close is 0.00', async () => {
    const section = await prices(cardWithPrices(upFromZeroPrice, flatAtZeroPrice));
    const up = within(variantItem(section, 'finish0, unlimited')).getByText(
      '▲ +$0.25 vs previous close (2026-10-11)',
    );
    expect(up.className).toContain('text-up');
    expect(
      within(variantItem(section, 'finish1, unlimited')).getByText(
        'No change vs previous close (2026-10-11)',
      ),
    ).toBeTruthy();
    expect(section.textContent).not.toMatch(/%|Infinity|NaN/);
  });

  it('omits the percentage on a down change whose change_pct is null', async () => {
    const section = await prices(cardWithPrices(downWithoutPctPrice));
    const down = within(section).getByText('▼ -$0.25 vs previous close (2026-10-11)');
    expect(down.className).toContain('text-down');
    expect(section.textContent).not.toContain('%');
  });

  it('marks an unpriced variant beside a priced one and drops "No prices yet"', async () => {
    const section = await prices(pricedCardBody);
    const holo = variantItem(section, 'holo, unlimited');
    expect(within(holo).getByText('Market price $70.81')).toBeTruthy();
    const reverse = variantItem(section, 'reverse, unlimited');
    expect(reverse.textContent).toBe('reverse, unlimitedNo price in the collected snapshots');
    expect(section.textContent).not.toContain('No prices yet');
  });

  it('shows exactly "No prices yet" and no footnote when no variant is priced, or there is none', async () => {
    for (const body of [cardBody, unmappedCardBody, cardWithPrices(null, null)]) {
      const section = await prices(body);
      expect(section.textContent).toBe('PricesNo prices yet');
      expect(screen.queryByText(FOOTNOTE)).toBeNull();
      cleanup();
    }
  });

  it('copies money digits verbatim with thousands separators, never re-rounding', async () => {
    const section = await prices(cardWithPrices(thousandsPrice, centsPrice, millionPrice));
    const thousands = variantItem(section, 'finish0, unlimited');
    expect(within(thousands).getByText('Market price $1,234.50')).toBeTruthy();
    expect(
      within(thousands).getByText('▼ -$0.07 (-0.01%) vs previous close (2026-10-11)'),
    ).toBeTruthy();
    const cents = variantItem(section, 'finish1, unlimited');
    expect(within(cents).getByText('Market price $0.07')).toBeTruthy();
    expect(
      within(cents).getByText('▼ -$1,234.50 (-99.99%) vs previous close (2026-10-11)'),
    ).toBeTruthy();
    const million = variantItem(section, 'finish2, unlimited');
    expect(within(million).getByText('Market price $1,000,000.00')).toBeTruthy();
    expect(
      within(million).getByText('▲ +$0.10 (+0.00%) vs previous close (2026-10-11)'),
    ).toBeTruthy();
    expect(section.textContent).not.toMatch(/\$1000000|\$0\.1(?!0)|\$1e/);
  });

  it('gives each variant its own day, taken from day and never from as_of or the clock', async () => {
    const section = await prices(
      cardWithPrices(risingPrice, {
        ...fallingPrice,
        day: '2026-10-09',
        as_of: '2026-10-10T01:30:00Z',
        previous_day: '2026-10-08',
      }),
    );
    expect(within(variantItem(section, 'finish0, unlimited')).getByText(AS_OF_1012)).toBeTruthy();
    const older = variantItem(section, 'finish1, unlimited');
    expect(within(older).getByText('as of 2026-10-09 from TCGCSV (daily close)')).toBeTruthy();
    expect(
      within(older).getByText('▼ -$1.30 (-1.87%) vs previous close (2026-10-08)'),
    ).toBeTruthy();
    expect(section.textContent).not.toContain('2026-10-10');
    expect(section.textContent).not.toContain('01:30');
  });

  it('shows the footnote once, as the first line of Sources, however many variants are priced', async () => {
    const section = await prices(cardWithPrices(risingPrice, null, unchangedPrice));
    const sources = screen.getByRole('region', { name: 'Sources' });
    expect(within(sources).getAllByText(FOOTNOTE)).toHaveLength(1);
    expect(screen.getAllByText(FOOTNOTE)).toHaveLength(1);
    expect(sources.textContent.startsWith(`Sources${FOOTNOTE}`)).toBe(true);
    expect(within(section).queryByText(FOOTNOTE)).toBeNull();
    expect(within(section).getAllByText(/^Market price /)).toHaveLength(2);
    expect(within(section).getAllByText('No price in the collected snapshots')).toHaveLength(1);
  });
});
