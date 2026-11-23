import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import App from '../App';
import type { CardDetail } from '../api/client';
import {
  filledOf,
  historyBody,
  isHistoryUrl,
  jsonResponse,
  pointOf,
  pricedUnownCard,
  risingPrice,
  seriesOf,
  stubFetch,
} from '../test/fixtures';

vi.mock('lightweight-charts', () => import('../test/lightweightChartsFake'));

const CARD_URL = '/v1/cards/ex10-%3F';
const HISTORY_1M = '/v1/cards/ex10-%3F/prices?range=1M';
const HISTORY_3M = '/v1/cards/ex10-%3F/prices?range=3M';
const HOLO = 'ex10-?:holo:unlimited:-';
const REVERSE = 'ex10-?:reverse:unlimited:-';
const STAMP = 'as of 2026-10-12 from TCGCSV (daily close)';
const BANNER =
  'History begins 2026-10-09. PokéPrices has no daily close for this variant before that day.';

const holoPoints = [
  pointOf('2026-10-09', '928.32'),
  pointOf('2026-10-10', '928.06'),
  filledOf('2026-10-11', '928.06', '2026-10-10'),
  pointOf('2026-10-12', '968.91'),
];

const twoPricedCard = {
  data: {
    ...pricedUnownCard,
    variants: pricedUnownCard.variants.map((variant) => ({ ...variant, price: risingPrice })),
  },
  meta: { degraded: false },
} satisfies CardDetail;

afterEach(() => {
  cleanup();
  window.history.replaceState(null, '', '/');
});

async function openPanel(card: CardDetail, history: ReturnType<typeof historyBody>) {
  const calls = stubFetch((url) => jsonResponse(isHistoryUrl(url) ? history : card));
  window.history.replaceState(null, '', '/card/ex10-%3F');
  render(<App />);
  const section = await screen.findByRole('region', { name: 'Price history' });
  await within(section).findByText(/^as of /);
  return { section, calls };
}

function follows(first: Node, second: Node): boolean {
  return (first.compareDocumentPosition(second) & Node.DOCUMENT_POSITION_FOLLOWING) !== 0;
}

describe('the Price history panel keeps its reading order and its one landmark (T02, plan 183-195)', () => {
  it('orders groups, data table toggle, stamp, note, plot, attribution, then the opened table', async () => {
    const { section } = await openPanel(
      twoPricedCard,
      historyBody([
        seriesOf(HOLO, holoPoints),
        seriesOf(REVERSE, [pointOf('2026-10-12', '70.81')], '2026-09-01'),
      ]),
    );
    const variant = within(section).getByRole('group', { name: 'Variant' });
    const ranges = within(section).getByRole('group', { name: 'Chart range' });
    const toggle = within(section).getByRole('button', { name: 'Show data table' });
    const stamp = within(section).getByText(STAMP);
    const note = within(section).getByRole('note');
    const plot = await within(section).findByRole('img');
    const link = await within(section).findByRole('link', {
      name: 'Chart by TradingView Lightweight Charts',
    });
    expect(note.textContent).toBe(BANNER);
    const order = [variant, ranges, toggle, stamp, note, plot, link];
    order.slice(1).forEach((node, index) => {
      expect(follows(order[index] as Node, node)).toBe(true);
    });
    expect(toggle.getAttribute('aria-expanded')).toBe('false');
    fireEvent.click(toggle);
    const table = within(section).getByRole('table');
    expect(follows(link, table)).toBe(true);
    expect(
      within(section)
        .getByRole('button', { name: 'Hide data table' })
        .getAttribute('aria-expanded'),
    ).toBe('true');
    expect(within(section).getAllByRole('heading')).toHaveLength(1);
    expect(within(section).getByRole('heading', { level: 2 }).textContent).toBe('Price history');
  });

  it('asks for nothing on a Variant click or a table toggle, once per new range, never twice', async () => {
    const { section, calls } = await openPanel(
      twoPricedCard,
      historyBody([
        seriesOf(HOLO, holoPoints),
        seriesOf(REVERSE, [pointOf('2026-10-12', '70.81')], '2026-09-01'),
      ]),
    );
    const urls = (): string[] => calls.map((call) => call.url);
    const click = (name: string): void => {
      fireEvent.click(within(section).getByRole('button', { name }));
    };
    click('Show data table');
    click('Hide data table');
    click('reverse, unlimited');
    click('holo, unlimited');
    expect(urls()).toEqual(['/v1/prices/freshness', CARD_URL, HISTORY_1M]);
    click('3M');
    await waitFor(() => {
      expect(urls()).toContain(HISTORY_3M);
    });
    await within(section).findByText(/^as of /);
    click('1M');
    click('3M');
    click('Show data table');
    await within(section).findByRole('table');
    expect(urls()).toEqual(['/v1/prices/freshness', CARD_URL, HISTORY_1M, HISTORY_3M]);
  });

  it('keeps the Variant group when the chosen variant has no close in the window, and charts the other', async () => {
    const { section } = await openPanel(
      twoPricedCard,
      historyBody([
        seriesOf(HOLO, [], '2026-10-09'),
        seriesOf(REVERSE, [pointOf('2026-10-12', '70.81')], '2026-09-01'),
      ]),
    );
    const group = within(section).getByRole('group', { name: 'Variant' });
    expect(within(group).getAllByRole('button')).toHaveLength(2);
    expect(within(section).queryByRole('button', { name: /data table/ })).toBeNull();
    expect(within(section).queryByRole('img')).toBeNull();
    fireEvent.click(within(group).getByRole('button', { name: 'reverse, unlimited' }));
    expect(within(section).getByRole('button', { name: 'Show data table' })).toBeTruthy();
    expect(await within(section).findByRole('img')).toBeTruthy();
    expect(within(section).getByText(STAMP)).toBeTruthy();
  });
});
