// The chart credits TradingView with one ordinary link under the chart, outside the chart's own
// container: the library's logo link, which it would draw inside that container (an interactive
// element inside the role="img" box), is turned off.
import { act, cleanup, render, screen, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import App from '../App';
import { setTheme } from '../preferences';
import {
  historyBody,
  isHistoryUrl,
  jsonResponse,
  pointOf,
  pricedCardBody,
  seriesOf,
  stubFetch,
} from '../test/fixtures';
import { recorder, resetRecorder } from '../test/lightweightChartsFake';

vi.mock('lightweight-charts', () => import('../test/lightweightChartsFake'));

const HOLO = 'ex10-?:holo:unlimited:-';
const ATTRIBUTION = 'Chart by TradingView Lightweight Charts';

beforeEach(() => {
  resetRecorder();
});

afterEach(() => {
  cleanup();
  window.history.replaceState(null, '', '/');
});

describe('the chart attribution', () => {
  it('turns the library logo off and renders the credit once, as a link outside the chart container', async () => {
    stubFetch((url) =>
      isHistoryUrl(url)
        ? jsonResponse(
            historyBody([
              seriesOf(HOLO, [pointOf('2026-10-11', '928.06'), pointOf('2026-10-12', '968.91')]),
            ]),
          )
        : jsonResponse(pricedCardBody),
    );
    window.history.replaceState(null, '', '/card/ex10-%3F');
    render(<App />);
    const section = await screen.findByRole('region', { name: 'Price history' });
    const link = await within(section).findByRole('link', { name: ATTRIBUTION });
    expect(within(section).getAllByRole('link', { name: ATTRIBUTION })).toHaveLength(1);
    expect(recorder.charts).toHaveLength(1);
    const chart = recorder.charts[0];
    if (chart === undefined) throw new Error('no chart drawn');
    expect(chart.options).toMatchObject({ layout: { attributionLogo: false } });
    expect(chart.container.contains(link)).toBe(false);
    expect(link.closest('[role="img"]')).toBeNull();
    expect(link.getAttribute('href')).toBe('https://www.tradingview.com/');

    act(() => {
      setTheme('dark');
    });
    for (const applied of chart.applied) {
      expect(JSON.stringify(applied)).not.toContain('"attributionLogo":true');
    }
    act(() => {
      setTheme('system');
    });
  });
});
