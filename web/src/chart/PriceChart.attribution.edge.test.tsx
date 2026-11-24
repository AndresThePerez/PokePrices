// Reviewer edge tests for the chart's attribution: the library's logo is off in every options object the chart
// is created or updated with, the credit is one ordinary link outside the chart container whenever a chart is
// drawn (first render, a range change, a variant change, a theme change, the data table shown), and nothing
// interactive ever sits inside the chart's role="img" box.
import { act, cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import App from '../App';
import type { CardDetail } from '../api/client';
import { setPalette, setTheme } from '../preferences';
import {
  freshnessBody,
  historyBody,
  isFreshnessUrl,
  isHistoryUrl,
  jsonResponse,
  pointOf,
  pricedUnownCard,
  seriesOf,
  stubFetch,
} from '../test/fixtures';
import { recorder, resetRecorder } from '../test/lightweightChartsFake';

vi.mock('lightweight-charts', () => import('../test/lightweightChartsFake'));

const HOLO = 'ex10-?:holo:unlimited:-';
const REVERSE = 'ex10-?:reverse:unlimited:-';
const ATTRIBUTION = 'Chart by TradingView Lightweight Charts';

beforeEach(() => {
  resetRecorder();
});

afterEach(() => {
  cleanup();
  setTheme('system');
  setPalette('green-red');
  window.history.replaceState(null, '', '/');
});

/** The unown card with a close on both variants, so the Variant group is offered. */
function bothPriced(): CardDetail {
  const [holo, reverse] = pricedUnownCard.variants;
  const price = holo?.price;
  if (holo === undefined || reverse === undefined || price === null || price === undefined) {
    throw new Error('the fixture lost a variant');
  }
  return {
    data: {
      ...pricedUnownCard,
      variants: [holo, { ...reverse, price: { ...price, close: '12.34' } }],
    },
    meta: { degraded: false },
  };
}

function manyPoints(key: string) {
  return seriesOf(key, [
    pointOf('2026-10-08', '10.00'),
    pointOf('2026-10-09', '11.00'),
    pointOf('2026-10-10', '12.00'),
    pointOf('2026-10-11', '11.50'),
    pointOf('2026-10-12', '13.00'),
  ]);
}

function serve(history: (url: string) => Response, card: CardDetail = bothPriced()): void {
  stubFetch((url) => {
    if (isFreshnessUrl(url)) return jsonResponse(freshnessBody({ within_slo: true }));
    return isHistoryUrl(url) ? history(url) : jsonResponse(card);
  });
}

async function openCard(): Promise<HTMLElement> {
  window.history.replaceState(null, '', '/card/ex10-%3F');
  render(<App />);
  return screen.findByRole('region', { name: 'Price history' });
}

/** The credit link must be there once, outside the chart box, and nothing interactive inside that box. */
function expectCreditedOnce(section: HTMLElement): void {
  const links = within(section).getAllByRole('link', { name: ATTRIBUTION });
  expect(links).toHaveLength(1);
  expect(screen.getAllByRole('link', { name: ATTRIBUTION })).toHaveLength(1);
  const link = links[0];
  expect(link?.getAttribute('href')).toBe('https://www.tradingview.com/');
  for (const chart of recorder.charts) {
    if (link !== undefined) expect(chart.container.contains(link)).toBe(false);
    expect(chart.container.querySelectorAll('a, button, input, select, [tabindex]')).toHaveLength(
      0,
    );
  }
  for (const image of section.querySelectorAll('[role="img"]')) {
    expect(image.querySelectorAll('a, button, input, select, [tabindex]')).toHaveLength(0);
  }
}

function expectLogoOffEverywhere(): void {
  expect(recorder.charts.length).toBeGreaterThan(0);
  for (const chart of recorder.charts) {
    expect(chart.options).toMatchObject({ layout: { attributionLogo: false } });
    expect(JSON.stringify(chart.options)).not.toContain('"attributionLogo":true');
    for (const applied of chart.applied) {
      expect(JSON.stringify(applied)).not.toContain('"attributionLogo":true');
    }
    for (const series of chart.series) {
      expect(JSON.stringify(series.options)).not.toContain('attributionLogo');
      for (const applied of series.applied) {
        expect(JSON.stringify(applied)).not.toContain('"attributionLogo":true');
      }
    }
  }
}

describe('the attribution in every chart state that draws a chart', () => {
  it('is one link outside the chart, logo off, through a range change, a variant change, a theme change and the data table', async () => {
    serve((url) =>
      jsonResponse(
        historyBody([manyPoints(HOLO), manyPoints(REVERSE)], {
          range: url.includes('range=1W') ? '1W' : '1M',
        }),
      ),
    );
    const section = await openCard();
    await within(section).findByRole('link', { name: ATTRIBUTION });
    expectCreditedOnce(section);
    expectLogoOffEverywhere();

    // A range change.
    fireEvent.click(within(section).getByRole('button', { name: '1W' }));
    await waitFor(() => {
      expect(recorder.charts.length).toBeGreaterThan(0);
    });
    await within(section).findByRole('link', { name: ATTRIBUTION });
    expectCreditedOnce(section);
    expectLogoOffEverywhere();

    // A variant change.
    const group = within(section).getByRole('group', { name: 'Variant' });
    const other = within(group)
      .getAllByRole('button')
      .find((button) => button.getAttribute('aria-pressed') !== 'true');
    if (other === undefined) throw new Error('no other variant');
    fireEvent.click(other);
    await waitFor(() => {
      expect(window.location.search).toContain('variant=');
    });
    await within(section).findByRole('link', { name: ATTRIBUTION });
    expectCreditedOnce(section);
    expectLogoOffEverywhere();

    // The data table shown.
    fireEvent.click(within(section).getByRole('button', { name: 'Show data table' }));
    expectCreditedOnce(section);

    // A theme and a palette change.
    act(() => {
      setTheme('dark');
    });
    act(() => {
      setPalette('blue-orange');
    });
    expectCreditedOnce(section);
    expectLogoOffEverywhere();
  });

  it('draws one chart with one credit for a single point', async () => {
    serve(() =>
      jsonResponse(
        historyBody([seriesOf(HOLO, [pointOf('2026-10-12', '13.00')]), manyPoints(REVERSE)]),
      ),
    );
    const section = await openCard();
    await within(section).findByRole('link', { name: ATTRIBUTION });
    expect(within(section).getAllByRole('link', { name: ATTRIBUTION })).toHaveLength(1);
    expectCreditedOnce(section);
    expectLogoOffEverywhere();
  });

  it('survives a strict remount without a second credit', async () => {
    serve(() => jsonResponse(historyBody([manyPoints(HOLO), manyPoints(REVERSE)])));
    const section = await openCard();
    await within(section).findByRole('link', { name: ATTRIBUTION });
    cleanup();
    resetRecorder();
    const again = await openCard();
    await within(again).findByRole('link', { name: ATTRIBUTION });
    expect(within(again).getAllByRole('link', { name: ATTRIBUTION })).toHaveLength(1);
    expectCreditedOnce(again);
    expectLogoOffEverywhere();
  });
});

describe('the attribution when no chart is drawn', () => {
  it('shows no credit twice and no logo link for an empty history', async () => {
    serve(() => jsonResponse(historyBody([seriesOf(HOLO, []), seriesOf(REVERSE, [])])));
    const section = await openCard();
    await within(section).findByText('No daily close is held for this card yet.');
    expect(recorder.charts).toHaveLength(0);
    expect(screen.queryAllByRole('link', { name: ATTRIBUTION }).length).toBeLessThanOrEqual(1);
    expect(section.querySelectorAll('[role="img"] a')).toHaveLength(0);
  });

  it('shows no credit twice and no chart when the history request fails', async () => {
    serve(() =>
      jsonResponse({ type: 'about:blank', title: 'Server Error', status: 500 }, { status: 500 }),
    );
    const section = await openCard();
    await waitFor(() => {
      expect(within(section).queryByRole('alert')).not.toBeNull();
    });
    expect(recorder.charts).toHaveLength(0);
    expect(screen.queryAllByRole('link', { name: ATTRIBUTION }).length).toBeLessThanOrEqual(1);
  });

  it('shows the plain sentence, never a second credit, when the chart cannot be drawn', async () => {
    recorder.failWith = new Error('no canvas');
    serve(() => jsonResponse(historyBody([manyPoints(HOLO), manyPoints(REVERSE)])));
    const section = await openCard();
    await within(section).findByText(
      'The chart could not be drawn. Show the data table to read every close.',
    );
    expect(screen.queryAllByRole('link', { name: ATTRIBUTION }).length).toBeLessThanOrEqual(1);
    expect(screen.getByRole('main').querySelectorAll('[role="img"] a')).toHaveLength(0);
  });
});
