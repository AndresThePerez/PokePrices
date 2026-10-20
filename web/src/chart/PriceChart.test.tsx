import { act, cleanup, render, screen, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import App from '../App';
import { setPalette, setTheme } from '../preferences';
import {
  filledOf,
  historyBody,
  isHistoryUrl,
  jsonResponse,
  pointOf,
  pricedCardBody,
  seriesOf,
  stubFetch,
} from '../test/fixtures';
import { recorder, resetRecorder } from '../test/lightweightChartsFake';
import PriceChart from './PriceChart';
import { segments } from './series';

vi.mock('lightweight-charts', () => import('../test/lightweightChartsFake'));

const HOLO = 'ex10-?:holo:unlimited:-';
const CHART_FAILED = 'The chart could not be drawn. Show the data table to read every close.';
const ATTRIBUTION = 'Chart by TradingView Lightweight Charts';

/** The D8 light-theme hexes the chart falls back to when a custom property reads empty. */
const LIGHT = { line: '#44403c', filled: '#a8a29e', text: '#57534e', grid: '#ebe5dc' };
const DARK = { line: '#38bdf8', filled: '#64748b', text: '#94a3b8', grid: '#1e293b' };

/** A gap after 2026-10-02 and a mapping change on 2026-10-06: three segments (D6). */
const gapAndEpoch = [
  pointOf('2026-10-01', '10.00'),
  pointOf('2026-10-02', '12.50'),
  pointOf('2026-10-04', '11.00'),
  filledOf('2026-10-05', '11.00', '2026-10-04'),
  pointOf('2026-10-06', '1234.56', { series_epoch: 2 }),
];

/** The tokens of index.css as plain rules, for the theme tests (jsdom computes custom properties). */
const TOKENS_CSS = `
:root { --color-chart-line: ${LIGHT.line}; --color-chart-filled: ${LIGHT.filled};
  --color-chart-text: ${LIGHT.text}; --color-chart-grid: ${LIGHT.grid}; }
[data-theme='dark'] { --color-chart-line: ${DARK.line}; --color-chart-filled: ${DARK.filled};
  --color-chart-text: ${DARK.text}; --color-chart-grid: ${DARK.grid}; }
`;

function addStyle(css: string): void {
  const style = document.createElement('style');
  style.setAttribute('data-test', 'tokens');
  style.textContent = css;
  document.head.append(style);
}

function draw(points = gapAndEpoch) {
  return render(
    <>
      <p id="summary-1">summary</p>
      <PriceChart
        segments={segments(points)}
        label="holo, unlimited"
        range="1M"
        describedBy="summary-1"
      />
    </>,
  );
}

function onlyChart() {
  expect(recorder.charts).toHaveLength(1);
  const chart = recorder.charts[0];
  if (chart === undefined) throw new Error('no chart drawn');
  return chart;
}

function chartOptions(colours: typeof LIGHT) {
  return {
    autoSize: true,
    layout: {
      background: { type: 'solid', color: 'transparent' },
      textColor: colours.text,
      attributionLogo: true,
    },
    grid: { vertLines: { color: colours.grid }, horzLines: { color: colours.grid } },
    rightPriceScale: { borderColor: colours.grid },
    timeScale: { borderColor: colours.grid },
    localization: { dateFormat: 'yyyy-MM-dd' },
    handleScroll: false,
    handleScale: false,
  };
}

function seriesOptions(colours: typeof LIGHT) {
  return {
    color: colours.line,
    lineWidth: 2,
    pointMarkersVisible: true,
    priceLineVisible: false,
    lastValueVisible: false,
    priceFormat: { type: 'price', precision: 2, minMove: 0.01 },
  };
}

beforeEach(() => {
  resetRecorder();
});

afterEach(() => {
  cleanup();
  for (const style of document.head.querySelectorAll('style[data-test]')) style.remove();
  window.localStorage.clear();
  document.documentElement.removeAttribute('data-theme');
  document.documentElement.removeAttribute('data-palette');
  vi.restoreAllMocks();
});

describe('PriceChart (T06, D6)', () => {
  it('draws one line series per segment with { time, value } items and fits the content after', () => {
    draw();
    const chart = onlyChart();
    expect(chart.series).toHaveLength(3);
    for (const series of chart.series) expect(series.definition).toEqual({ type: 'Line' });
    expect(chart.series.map((series) => series.data)).toEqual([
      [
        [
          { time: '2026-10-01', value: 10 },
          { time: '2026-10-02', value: 12.5 },
        ],
      ],
      [
        [
          { time: '2026-10-04', value: 11 },
          { time: '2026-10-05', value: 11, color: LIGHT.filled },
        ],
      ],
      [[{ time: '2026-10-06', value: 1234.56 }]],
    ]);
    expect(recorder.calls).toEqual([
      'createChart',
      'addSeries',
      'setData',
      'addSeries',
      'setData',
      'addSeries',
      'setData',
      'fitContent',
    ]);
  });

  it('gives only filled points a colour', () => {
    draw();
    const items = onlyChart().series.flatMap((series) => series.data.flat()) as Record<
      string,
      unknown
    >[];
    expect(items.filter((item) => 'color' in item)).toEqual([
      { time: '2026-10-05', value: 11, color: LIGHT.filled },
    ]);
  });

  it('passes the D6 chart and series options verbatim, with light hexes when no stylesheet sets the tokens', () => {
    draw();
    const chart = onlyChart();
    expect(chart.options).toEqual(chartOptions(LIGHT));
    for (const series of chart.series) expect(series.options).toEqual(seriesOptions(LIGHT));
    expect(JSON.stringify(chart.options)).not.toContain('""');
    expect(JSON.stringify(chart.series.map((series) => series.options))).not.toContain('""');
  });

  it('treats a whitespace-only token as missing', () => {
    addStyle(':root { --color-chart-line: ; --color-chart-grid:   ; }');
    draw();
    const chart = onlyChart();
    expect(chart.options).toEqual(chartOptions(LIGHT));
    expect(chart.series[0]?.options).toEqual(seriesOptions(LIGHT));
  });

  it('turns off the price line, the last value label, scrolling and scaling, and dates as yyyy-MM-dd', () => {
    draw();
    const chart = onlyChart();
    expect(chart.options).toMatchObject({
      handleScroll: false,
      handleScale: false,
      localization: { dateFormat: 'yyyy-MM-dd' },
      layout: { attributionLogo: true },
    });
    expect(chart.series[0]?.options).toMatchObject({
      priceLineVisible: false,
      lastValueVisible: false,
    });
  });

  it('labels the container as an image of the chart, described by the summary', () => {
    draw();
    const image = screen.getByRole('img', { name: 'Daily close chart of holo, unlimited, 1M' });
    expect(image.getAttribute('aria-describedby')).toBe('summary-1');
    expect(onlyChart().container).toBe(image);
  });

  it('reads the dark tokens on a theme switch and re-applies them without drawing again', () => {
    addStyle(TOKENS_CSS);
    document.documentElement.setAttribute('data-theme', 'light');
    draw();
    const chart = onlyChart();
    expect(chart.options).toEqual(chartOptions(LIGHT));
    expect(chart.applied).toEqual([]);
    act(() => {
      setTheme('dark');
    });
    expect(recorder.charts).toHaveLength(1);
    expect(chart.removed).toBe(false);
    expect(chart.applied).toEqual([
      {
        layout: { textColor: DARK.text },
        grid: { vertLines: { color: DARK.grid }, horzLines: { color: DARK.grid } },
        rightPriceScale: { borderColor: DARK.grid },
        timeScale: { borderColor: DARK.grid },
      },
    ]);
    for (const series of chart.series) expect(series.applied).toEqual([{ color: DARK.line }]);
    act(() => {
      setPalette('blue-orange');
    });
    expect(chart.applied).toHaveLength(1);
    act(() => {
      setTheme('light');
    });
    expect(chart.applied.at(-1)).toMatchObject({ layout: { textColor: LIGHT.text } });
    for (const series of chart.series) expect(series.applied.at(-1)).toEqual({ color: LIGHT.line });
  });

  it('re-sets the data when the filled colour changes with the theme', () => {
    addStyle(`${TOKENS_CSS} [data-theme='dark'] { --color-chart-filled: #94a3b8; }`);
    draw();
    const chart = onlyChart();
    act(() => {
      setTheme('dark');
    });
    expect(chart.series[1]?.data.at(-1)).toEqual([
      { time: '2026-10-04', value: 11 },
      { time: '2026-10-05', value: 11, color: '#94a3b8' },
    ]);
    expect(recorder.calls.at(-1)).toBe('fitContent');
  });

  it('removes the chart on unmount', () => {
    const { unmount } = draw();
    const chart = onlyChart();
    expect(chart.removed).toBe(false);
    unmount();
    expect(chart.removed).toBe(true);
  });
});

describe('the chart on the card page (T06)', () => {
  const holoPoints = [
    pointOf('2026-10-09', '928.32'),
    pointOf('2026-10-10', '928.06'),
    filledOf('2026-10-11', '928.06', '2026-10-10'),
    pointOf('2026-10-12', '968.91'),
  ];

  function open(): void {
    stubFetch((url) =>
      isHistoryUrl(url)
        ? jsonResponse(historyBody([seriesOf(HOLO, holoPoints)]))
        : jsonResponse(pricedCardBody),
    );
    window.history.replaceState(null, '', '/card/ex10-%3F');
    render(<App />);
  }

  afterEach(() => {
    window.history.replaceState(null, '', '/');
  });

  it('draws the selected series under the summary with the TradingView attribution link', async () => {
    open();
    const section = await screen.findByRole('region', { name: 'Price history' });
    const link = await within(section).findByRole('link', { name: ATTRIBUTION });
    expect(link.getAttribute('href')).toBe('https://www.tradingview.com/');
    expect(link.className.split(/\s+/)).toContain('min-h-11');
    const image = within(section).getByRole('img', {
      name: 'Daily close chart of holo, unlimited, 1M',
    });
    const summary = section.querySelector('p.sr-only');
    expect(summary?.id).not.toBe('');
    expect(image.getAttribute('aria-describedby')).toBe(summary?.id);
    const chart = onlyChart();
    expect(chart.options).toMatchObject({ layout: { attributionLogo: true } });
    expect(chart.series).toHaveLength(1);
    expect(image.compareDocumentPosition(link) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
  });

  it('shows the boundary sentence when the chart cannot be drawn, and keeps the rest of the page', async () => {
    vi.spyOn(console, 'error').mockImplementation(() => undefined);
    recorder.failWith = new Error('no canvas');
    open();
    const section = await screen.findByRole('region', { name: 'Price history' });
    expect(await within(section).findByText(CHART_FAILED)).toBeTruthy();
    expect(within(section).queryByRole('link', { name: ATTRIBUTION })).toBeNull();
    expect(within(section).getByText('as of 2026-10-12 from TCGCSV (daily close)')).toBeTruthy();
    expect(section.querySelector('p.sr-only')?.textContent).toMatch(/^Holo, unlimited, 1M: /);
    expect(within(section).getByRole('button', { name: 'Show data table' })).toBeTruthy();
    expect(within(section).getAllByRole('button', { pressed: true })).toHaveLength(1);
    expect(screen.getByRole('heading', { level: 1 }).textContent).toBe('Unown');
    expect(screen.getByRole('region', { name: 'Prices' }).textContent).toContain(
      'Market price $70.81',
    );
  });
});
