import { act, cleanup, fireEvent, render, screen, within } from '@testing-library/react';
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
const FORBIDDEN = [
  /\blive\b/i,
  /real-?time/i,
  /as of today/i,
  /\btoday\b/i,
  /\bnow\b/i,
  /\b1D\b/i,
  /\b24 ?h/i,
  /20:05/,
  /2031/,
];

// The stylesheet is read from disk (the Vitest CSS pipeline returns '' for ?raw). tsconfig.app.json
// carries no Node types, hence the untyped specifier and the narrow cast (as preferences.test.ts).
const NODE_FS = 'node:fs';
const { readFileSync } = (await import(/* @vite-ignore */ NODE_FS)) as {
  readonly readFileSync: (path: string, encoding: 'utf8') => string;
};
const testFile = import.meta.url;
const indexCss = readFileSync(new URL('../index.css', testFile).pathname, 'utf8');

/** The `--color-chart-*` declarations of the first rule that starts with `header` in index.css. */
function chartTokens(header: string): Record<'line' | 'filled' | 'text' | 'grid', string> {
  const css = indexCss;
  const start = css.indexOf(header);
  if (start < 0) throw new Error(`index.css has no ${header}`);
  const body = css.slice(start, css.indexOf('}', start));
  const read = (name: string): string => {
    const match = new RegExp(`--color-chart-${name}:\\s*(#[0-9a-fA-F]{6})`).exec(body);
    if (match?.[1] === undefined) throw new Error(`index.css ${header} has no chart-${name}`);
    return match[1];
  };
  return { line: read('line'), filled: read('filled'), text: read('text'), grid: read('grid') };
}

function declarations(tokens: ReturnType<typeof chartTokens>): string {
  return (
    `--color-chart-line: ${tokens.line}; --color-chart-filled: ${tokens.filled}; ` +
    `--color-chart-text: ${tokens.text}; --color-chart-grid: ${tokens.grid};`
  );
}

function addStyle(css: string): void {
  const style = document.createElement('style');
  style.setAttribute('data-test', 'tokens');
  style.textContent = css;
  document.head.append(style);
}

const run = [
  pointOf('2026-10-01', '10.00'),
  filledOf('2026-10-02', '10.00', '2026-10-01'),
  pointOf('2026-10-03', '12.00'),
];

function props(points = run) {
  return {
    segments: segments(points),
    label: 'holo, unlimited',
    range: '1M' as const,
    describedBy: 'summary-1',
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
  window.history.replaceState(null, '', '/');
  vi.restoreAllMocks();
});

describe('PriceChart token fallback against the real stylesheet (F-P3-02)', () => {
  it('falls back to exactly the light tokens index.css declares, never a drifted copy', () => {
    const light = chartTokens('@theme static');
    render(<PriceChart {...props()} />);
    const chart = recorder.charts[0];
    expect(chart?.options).toMatchObject({
      layout: { textColor: light.text },
      grid: { vertLines: { color: light.grid }, horzLines: { color: light.grid } },
      rightPriceScale: { borderColor: light.grid },
      timeScale: { borderColor: light.grid },
    });
    expect(chart?.series[0]?.options).toMatchObject({ color: light.line });
    const items = (chart?.series[0]?.data[0] ?? []) as Record<string, unknown>[];
    expect(items.find((item) => 'color' in item)).toMatchObject({ color: light.filled });
  });

  it('reads the real dark tokens of index.css on a switch to dark, not the fallback', () => {
    const light = chartTokens('@theme static');
    const dark = chartTokens("[data-theme='dark'] {");
    addStyle(`:root { ${declarations(light)} } [data-theme='dark'] { ${declarations(dark)} }`);
    document.documentElement.setAttribute('data-theme', 'light');
    render(<PriceChart {...props()} />);
    const chart = recorder.charts[0];
    act(() => {
      setTheme('dark');
    });
    expect(chart?.applied.at(-1)).toMatchObject({ layout: { textColor: dark.text } });
    expect(chart?.series[0]?.applied.at(-1)).toEqual({ color: dark.line });
    expect(dark.line).not.toBe(light.line);
  });
});

describe('PriceChart life cycle', () => {
  it('removes the old chart and draws one new chart when the segments change (no leak)', () => {
    const { rerender, unmount } = render(<PriceChart {...props()} />);
    rerender(<PriceChart {...props([pointOf('2026-10-05', '20.00')])} />);
    expect(recorder.charts).toHaveLength(2);
    expect(recorder.charts.map((chart) => chart.removed)).toEqual([true, false]);
    expect(recorder.charts[1]?.series).toHaveLength(1);
    unmount();
    expect(recorder.charts.map((chart) => chart.removed)).toEqual([true, true]);
    expect(recorder.calls.filter((call) => call === 'remove')).toHaveLength(2);
  });

  it('never re-applies options to a chart that was unmounted, and survives later switches', () => {
    const { unmount } = render(<PriceChart {...props()} />);
    const chart = recorder.charts[0];
    unmount();
    const before = recorder.calls.length;
    act(() => {
      setTheme('dark');
      setPalette('blue-orange');
    });
    expect(chart?.applied).toEqual([]);
    expect(chart?.series[0]?.applied).toEqual([]);
    expect(recorder.calls.length).toBe(before);
  });

  it('ends on the last choice after rapid theme switches, one applyOptions per real change', () => {
    const light = chartTokens('@theme static');
    const dark = chartTokens("[data-theme='dark'] {");
    addStyle(`:root { ${declarations(light)} } [data-theme='dark'] { ${declarations(dark)} }`);
    act(() => {
      setTheme('light');
    });
    render(<PriceChart {...props()} />);
    const chart = recorder.charts[0];
    for (const choice of ['dark', 'light', 'dark', 'light'] as const) {
      act(() => {
        setTheme(choice);
      });
    }
    expect(chart?.applied).toHaveLength(4);
    expect(chart?.applied.at(-1)).toMatchObject({ layout: { textColor: light.text } });
    expect(chart?.series[0]?.applied.at(-1)).toEqual({ color: light.line });
    // One batched flip back to where it started changes nothing.
    act(() => {
      setTheme('dark');
      setTheme('light');
    });
    expect(chart?.applied).toHaveLength(4);
    expect(recorder.charts).toHaveLength(1);
    expect(chart?.removed).toBe(false);
  });
});

describe('the chart on the card page, later updates', () => {
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

  it('leaves exactly one live chart after switching ranges', async () => {
    open();
    const section = await screen.findByRole('region', { name: 'Price history' });
    await within(section).findByRole('link', { name: ATTRIBUTION });
    for (const range of ['3M', '1W', '1M']) {
      fireEvent.click(within(section).getByRole('button', { name: range }));
      await within(section).findByRole('link', { name: ATTRIBUTION });
    }
    const live = recorder.charts.filter((chart) => !chart.removed);
    expect(live).toHaveLength(1);
    expect(recorder.charts.length).toBeGreaterThanOrEqual(2);
    expect(live[0]?.container.isConnected).toBe(true);
  });

  it('contains a throw on a later update, keeps the page, and draws again once it can', async () => {
    vi.spyOn(console, 'error').mockImplementation(() => undefined);
    open();
    const section = await screen.findByRole('region', { name: 'Price history' });
    await within(section).findByRole('link', { name: ATTRIBUTION });
    recorder.failWith = new Error('context lost');
    fireEvent.click(within(section).getByRole('button', { name: '3M' }));
    expect(await within(section).findByText(CHART_FAILED)).toBeTruthy();
    expect(within(section).queryByRole('img')).toBeNull();
    expect(within(section).queryByRole('link', { name: ATTRIBUTION })).toBeNull();
    expect(screen.getByRole('heading', { level: 1 }).textContent).toBe('Unown');
    expect(screen.getByRole('region', { name: 'Prices' }).textContent).toContain(
      'Market price $70.81',
    );
    expect(within(section).getByRole('button', { name: 'Show data table' })).toBeTruthy();
    const text = screen.getByRole('main').textContent;
    for (const pattern of FORBIDDEN) expect(text).not.toMatch(pattern);
    recorder.failWith = null;
    fireEvent.click(within(section).getByRole('button', { name: '1W' }));
    expect(await within(section).findByRole('link', { name: ATTRIBUTION })).toBeTruthy();
    expect(within(section).queryByText(CHART_FAILED)).toBeNull();
  });
});
