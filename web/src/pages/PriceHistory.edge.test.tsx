import { cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import App from '../App';
import type { CardDetail } from '../api/client';
import {
  filledOf,
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
const HOLO = 'ex10-?:holo:unlimited:-';
const REVERSE = 'ex10-?:reverse:unlimited:-';
const ZERO_ELIGIBLE = 'No daily close is held for this card yet.';

const twoPricedCard = {
  data: {
    ...pricedUnownCard,
    variants: pricedUnownCard.variants.map((variant) => ({ ...variant, price: risingPrice })),
  },
  meta: { degraded: false },
} satisfies CardDetail;

const holoPoints = [
  pointOf('2026-10-09', '928.32'),
  filledOf('2026-10-10', '928.32', '2026-10-09'),
  pointOf('2026-10-12', '968.91'),
];

function visit(path: string): void {
  window.history.replaceState(null, '', path);
}

function serve(reply: (url: string) => Response | Error, card: CardDetail = pricedCardBody) {
  return stubFetch((url) => (isHistoryUrl(url) ? reply(url) : jsonResponse(card)));
}

async function openSection(): Promise<HTMLElement> {
  visit('/card/ex10-%3F');
  render(<App />);
  return screen.findByRole('region', { name: 'Price history' });
}

function pressed(group: HTMLElement): string[] {
  return within(group)
    .getAllByRole('button')
    .filter((button) => button.getAttribute('aria-pressed') === 'true')
    .map((button) => button.textContent);
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

describe('Price history edge cases (T05)', () => {
  it('survives a JSON null as the history 200 body and keeps the card page', async () => {
    serve(() => jsonResponse(null));
    const section = await openSection();
    await within(section).findByText(ZERO_ELIGIBLE);
    expect(screen.getByRole('heading', { level: 1 }).textContent).toBe('Unown');
    expect(within(section).getAllByRole('button')).toHaveLength(5);
  });

  it('survives a null entry in the history series list', async () => {
    serve(() => jsonResponse({ data: [null], meta: historyBody([]).meta }));
    const section = await openSection();
    await within(section).findByText(/^as of /);
    expect(screen.getByRole('heading', { level: 1 }).textContent).toBe('Unown');
  });

  it('survives a history series that lacks its points list', async () => {
    serve(() =>
      jsonResponse({
        data: [{ variant_key: HOLO, history_begins: '2026-10-09' }],
        meta: historyBody([]).meta,
      }),
    );
    const section = await openSection();
    await within(section).findByText(/^as of /);
    expect(screen.getByRole('heading', { level: 1 }).textContent).toBe('Unown');
  });

  it('omits the data-table toggle when the chosen series has no point in the window', async () => {
    serve(() =>
      jsonResponse(historyBody([seriesOf(HOLO, [pointOf('2026-08-01', '1.00')])], { range: '1W' })),
    );
    const section = await openSection();
    await within(section).findByText(
      'No daily close for this variant between 2026-09-13 and 2026-10-12.',
    );
    expect(within(section).queryByRole('button', { name: /data table/ })).toBeNull();
    expect(section.querySelector('p.sr-only')).toBeNull();
  });

  it('shows the banner one day after meta.from at a month boundary and hides it one day before', async () => {
    for (const [begins, from, shown] of [
      ['2026-10-01', '2026-09-30', true],
      ['2026-09-30', '2026-10-01', false],
    ] as const) {
      serve(() =>
        jsonResponse(
          historyBody([seriesOf(HOLO, [pointOf('2026-10-02', '1.00')], begins)], { from }),
        ),
      );
      const section = await openSection();
      await within(section).findByText(/^as of /);
      expect(within(section).queryByRole('note') !== null).toBe(shown);
      cleanup();
      vi.unstubAllGlobals();
    }
  });

  it('requests only history URLs for range and variant clicks and never refetches the card', async () => {
    const twoSeries = [
      seriesOf(HOLO, holoPoints),
      seriesOf(REVERSE, [pointOf('2026-10-12', '70.81')], '2026-10-12'),
    ];
    const calls = serve(
      (url) => jsonResponse(historyBody(twoSeries, { range: url.endsWith('=3M') ? '3M' : '1M' })),
      twoPricedCard,
    );
    const section = await openSection();
    await within(section).findByText(/^as of /);
    const ranges = () => within(section).getByRole('group', { name: 'Chart range' });
    const variants = () => within(section).getByRole('group', { name: 'Variant' });
    fireEvent.click(within(ranges()).getByRole('button', { name: '3M' }));
    await within(section).findByText(/^Holo, unlimited, 3M: /);
    fireEvent.click(within(variants()).getByRole('button', { name: 'reverse, unlimited' }));
    expect(pressed(variants())).toEqual(['reverse, unlimited']);
    expect(pressed(ranges())).toEqual(['3M']);
    fireEvent.click(within(ranges()).getByRole('button', { name: '1M' }));
    expect(pressed(ranges())).toEqual(['1M']);
    expect(pressed(variants())).toEqual(['reverse, unlimited']);
    const urls = calls.map((call) => call.url);
    expect(urls.filter((url) => !isHistoryUrl(url))).toEqual(['/v1/prices/freshness', CARD_URL]);
    expect(urls.filter(isHistoryUrl)).toEqual([
      `${CARD_URL}/prices?range=1M`,
      `${CARD_URL}/prices?range=3M`,
    ]);
  });

  it('lets the summary sentence through the card page $ carve-out scan', async () => {
    serve(() => jsonResponse(historyBody([seriesOf(HOLO, holoPoints)])));
    const section = await openSection();
    await within(section).findByText(/^as of /);
    const main = screen.getByRole('main');
    const dollarLines = Array.from(main.querySelectorAll('*'))
      .filter((node) => node.children.length === 0 && node.textContent.includes('$'))
      .map((node) => node.textContent);
    const summary = dollarLines.find((line) => line.startsWith('Holo, unlimited, 1M: '));
    expect(summary).toBeDefined();
    for (const line of dollarLines) {
      expect(line).toMatch(
        /^Market price \$\d|^\$\d[\d,]*\.\d{2}$|^[▲▼] [+-]\$\d.* vs previous close \(|^[A-Z][^:]*, (?:1W|1M|3M|YTD|1Y): /,
      );
    }
    expect(main.textContent).not.toMatch(/\$(?!\d)/);
    expect(main.textContent).not.toContain('2031');
  });

  it('keeps the Prices list byte-identical while the history loads, fails and changes range', async () => {
    const calls = serve((url) =>
      url.endsWith('=1W')
        ? new TypeError('offline')
        : jsonResponse(historyBody([seriesOf(HOLO, holoPoints)])),
    );
    const section = await openSection();
    await within(section).findByText(/^as of /);
    const before = screen.getByRole('region', { name: 'Prices' }).innerHTML;
    fireEvent.click(within(section).getByRole('button', { name: '1W' }));
    await within(section).findByRole('alert');
    expect(screen.getByRole('region', { name: 'Prices' }).innerHTML).toBe(before);
    expect(calls.filter((call) => !isHistoryUrl(call.url))).toHaveLength(2);
    expect(pressed(within(section).getByRole('group', { name: 'Chart range' }))).toEqual(['1W']);
  });
});
