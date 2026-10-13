import { cleanup, render, screen, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import App from '../App';
import type { CardDetail } from '../api/client';
import {
  cardWithPrices,
  fallingPrice,
  firstClosePrice,
  historyFor,
  isHistoryUrl,
  jsonResponse,
  risingPrice,
  stubFetch,
  unchangedPrice,
  type VariantPrice,
} from '../test/fixtures';

// jsdom has no canvas: the chart library is replaced by its recording fake (T06 helper).
vi.mock('lightweight-charts', () => import('../test/lightweightChartsFake'));

const FOOTNOTE = 'TCGplayer market prices via TCGCSV, collected once a day.';

function visit(path: string): void {
  window.history.replaceState(null, '', path);
}

async function prices(body: unknown): Promise<HTMLElement> {
  stubFetch((url) => jsonResponse(isHistoryUrl(url) ? historyFor(body as CardDetail) : body));
  visit('/card/ex10-%3F');
  render(<App />);
  await screen.findByRole('heading', { level: 1, name: 'Unown' });
  return screen.getByRole('region', { name: 'Prices' });
}

function items(section: HTMLElement): HTMLElement[] {
  return within(section).getAllByRole('listitem');
}

function itemText(section: HTMLElement, index: number): string {
  return items(section)[index]?.textContent ?? '';
}

function priceWith(fields: Partial<VariantPrice>): VariantPrice {
  return { ...risingPrice, ...fields };
}

afterEach(() => {
  cleanup();
  visit('/');
});

describe('Card page prices, edge cases', () => {
  it('renders each documented string character for character, with ASCII hyphen-minus and no dash look-alikes', async () => {
    const section = await prices(
      cardWithPrices(
        risingPrice,
        fallingPrice,
        unchangedPrice,
        firstClosePrice,
        priceWith({ close: '0.25', previous_close: '0.00', change: '0.25', change_pct: null }),
        priceWith({ close: '0.75', previous_close: '1.00', change: '-0.25', change_pct: null }),
        priceWith({ close: '0.00', previous_close: '0.00', change: '0.00', change_pct: null }),
        null,
      ),
    );
    const asOf = 'as of 2026-10-12 from TCGCSV (daily close)';
    const expected = [
      `finish0, unlimitedMarket price $70.81▲ +$1.45 (+2.09%) vs previous close (2026-10-11)${asOf}`,
      `finish1, unlimitedMarket price $68.06▼ -$1.30 (-1.87%) vs previous close (2026-10-11)${asOf}`,
      `finish2, unlimitedMarket price $2,257.87No change vs previous close (2026-10-11)${asOf}`,
      `finish3, unlimitedMarket price $70.81No previous close yet${asOf}`,
      `finish4, unlimitedMarket price $0.25▲ +$0.25 vs previous close (2026-10-11)${asOf}`,
      `finish5, unlimitedMarket price $0.75▼ -$0.25 vs previous close (2026-10-11)${asOf}`,
      `finish6, unlimitedMarket price $0.00No change vs previous close (2026-10-11)${asOf}`,
      'finish7, unlimitedNo price in the collected snapshots',
    ];
    expect(items(section).map((li) => li.textContent)).toEqual(expected);
    expect(section.textContent).not.toMatch(/[−–—]/);
    expect(section.textContent.split(FOOTNOTE).length).toBe(2);
    expect(section.textContent).not.toContain('No prices yet');
  });

  it('groups thousands on the string alone for 3, 4, 6 and 7 integer digits and keeps leading zeros and cents untouched', async () => {
    const section = await prices(
      cardWithPrices(
        priceWith({ close: '999.99' }),
        priceWith({ close: '1000.00' }),
        priceWith({ close: '123456.78' }),
        priceWith({ close: '1234567.89' }),
        priceWith({ close: '00.07' }),
        priceWith({ close: '0.10' }),
        priceWith({ close: '12' }),
      ),
    );
    const marketLines = items(section).map(
      (li) => /Market price \$[^▲▼NA]*?(?=▲|▼|No |as of)/.exec(li.textContent)?.[0] ?? '',
    );
    expect(marketLines.map((line) => line.replace(/^.*?Market/, 'Market'))).toEqual([
      'Market price $999.99',
      'Market price $1,000.00',
      'Market price $123,456.78',
      'Market price $1,234,567.89',
      'Market price $00.07',
      'Market price $0.10',
      'Market price $12',
    ]);
  });

  it('treats -0.00, +0.00 and 0.0 as no change and prints a plus-prefixed positive change with a single plus', async () => {
    const section = await prices(
      cardWithPrices(
        priceWith({ change: '-0.00', change_pct: '-0.00' }),
        priceWith({ change: '+0.00', change_pct: '+0.00' }),
        priceWith({ change: '0.0', change_pct: '0.0' }),
        priceWith({ change: '+0.10', change_pct: '+0.14' }),
        priceWith({ change: '0.001', change_pct: '0.00' }),
      ),
    );
    const text = (index: number): string => itemText(section, index);
    for (const index of [0, 1, 2]) {
      expect(text(index)).toContain('No change vs previous close (2026-10-11)');
      expect(text(index)).not.toMatch(/[▲▼]/);
    }
    expect(text(3)).toContain('▲ +$0.10 (+0.14%) vs previous close (2026-10-11)');
    expect(text(3)).not.toContain('++');
    expect(text(4)).toContain('▲ +$0.001 (+0.00%)');
  });

  it('does not crash, regroup or re-round malformed money strings and still shows the footnote once', async () => {
    const malformed = ['1234.5', '968.910', '', 'NaN', '-5.00', '1e6', ' 12.00', '1,234.50'];
    const section = await prices(cardWithPrices(...malformed.map((close) => priceWith({ close }))));
    const rows = items(section).map((li) => li.textContent);
    expect(rows).toHaveLength(malformed.length);
    expect(rows[0]).toContain('Market price $1,234.5▲');
    expect(rows[1]).toContain('Market price $968.910▲');
    expect(rows[2]).toContain('Market price $▲');
    expect(rows[3]).toContain('Market price $NaN▲');
    expect(rows[4]).toContain('Market price $-5.00▲');
    expect(rows[5]).toContain('Market price $1e6▲');
    expect(rows[6]).toContain('Market price $ 12.00▲');
    expect(rows[7]).toContain('Market price $1,234.50▲');
    expect(section.textContent.split(FOOTNOTE).length).toBe(2);
  });

  it('gives each variant only its own day and previous day, and the footnote once, around an unpriced variant', async () => {
    const section = await prices(
      cardWithPrices(
        priceWith({ day: '2026-10-12', previous_day: '2026-10-11' }),
        null,
        priceWith({
          day: '2026-10-08',
          previous_day: '2026-10-05',
          as_of: '2026-10-08T20:05:44Z',
        }),
        priceWith({
          day: '2026-10-09',
          previous_close: null,
          previous_day: null,
          change: null,
          change_pct: null,
        }),
      ),
    );
    const first = itemText(section, 0);
    const second = itemText(section, 1);
    const third = itemText(section, 2);
    const fourth = itemText(section, 3);
    expect(first).toContain('as of 2026-10-12 from');
    expect(first).toContain('(2026-10-11)');
    expect(first).not.toMatch(/2026-10-0[589]/);
    expect(second).not.toMatch(/\d{4}-\d{2}-\d{2}/);
    expect(third).toContain('as of 2026-10-08 from');
    expect(third).toContain('(2026-10-05)');
    expect(third).not.toMatch(/2026-10-1[12]/);
    expect(fourth).toContain('as of 2026-10-09 from');
    expect(fourth).not.toMatch(/2026-10-(05|08|11|12)/);
    expect(section.textContent.split(FOOTNOTE).length).toBe(2);
  });

  it('keeps direction readable from text alone: no aria-hidden or presentation role, and colour classes only on up and down', async () => {
    const section = await prices(
      cardWithPrices(risingPrice, fallingPrice, unchangedPrice, firstClosePrice),
    );
    expect(section.querySelector('[aria-hidden]')).toBeNull();
    expect(section.querySelector('[role="presentation"], [role="none"]')).toBeNull();
    const rows = items(section);
    const changeSpan = (index: number): Element => {
      const spans = rows[index]?.querySelectorAll('span') ?? [];
      const found = spans[2];
      if (found === undefined) throw new Error('no change span');
      return found;
    };
    expect(changeSpan(0).textContent).toMatch(/^▲ \+\$/);
    expect(changeSpan(0).className).toContain('text-up');
    expect(changeSpan(0).className).not.toContain('text-down');
    expect(changeSpan(1).textContent).toMatch(/^▼ -\$/);
    expect(changeSpan(1).className).toContain('text-down');
    expect(changeSpan(1).className).not.toContain('text-up');
    for (const index of [2, 3]) {
      expect(changeSpan(index).className).toContain('text-flat');
      expect(changeSpan(index).className).not.toMatch(/text-up|text-down/);
    }
  });

  it('renders markup in price and day strings as plain text nodes, never as elements', async () => {
    const hostile = '<img src="x-edge-probe" onerror="alert(1)">';
    const section = await prices(
      cardWithPrices(
        priceWith({
          close: hostile,
          day: `<b>2026-10-12</b>`,
          change: hostile,
          change_pct: '<i>1</i>',
          previous_day: '<u>d</u>',
        }),
      ),
    );
    expect(section.querySelector('img, b, i, u, script')).toBeNull();
    expect(section.textContent).toContain(hostile);
    expect(section.textContent).toContain('as of <b>2026-10-12</b> from TCGCSV (daily close)');
  });

  it('shows "No prices yet" and no footnote or per-variant line for no variants and for all-null variants, and never both states', async () => {
    for (const body of [cardWithPrices(), cardWithPrices(null), cardWithPrices(null, null, null)]) {
      const section = await prices(body);
      expect(section.textContent).toBe('PricesNo prices yet');
      cleanup();
    }
    const section = await prices(cardWithPrices(null, risingPrice));
    expect(section.textContent).not.toContain('No prices yet');
    expect(section.textContent.split('No price in the collected snapshots').length).toBe(2);
  });
});
