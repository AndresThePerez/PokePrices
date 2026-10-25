import { act, cleanup, render } from '@testing-library/react';
import { StrictMode } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { setPalette, setTheme } from '../preferences';
import { filledOf, pointOf } from '../test/fixtures';
import { recorder, resetRecorder, resize } from '../test/lightweightChartsFake';
import PriceChart from './PriceChart';
import { segments } from './series';

vi.mock('lightweight-charts', () => import('../test/lightweightChartsFake'));

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

function only() {
  const chart = recorder.charts[0];
  if (chart === undefined) throw new Error('no chart was drawn');
  return chart;
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

describe('the chart refit on size change (R300)', () => {
  it('never refits for a width that is zero, negative, NaN or a negative zero', () => {
    render(<PriceChart {...props()} />);
    const chart = only();
    for (const width of [0, -0, -1, -342, Number.NaN, Number.NEGATIVE_INFINITY]) {
      resize(chart, width, 240);
    }
    expect(chart.fitContent).toBe(1);
    resize(chart, 1, 240);
    expect(chart.fitContent).toBe(2);
  });

  it('refits for a real width after a collapse to 1x1 and back, as a full-page capture does', () => {
    render(<PriceChart {...props()} />);
    const chart = only();
    resize(chart, 398, 240);
    resize(chart, 1, 1);
    resize(chart, 398, 240);
    expect(chart.fitContent).toBe(4);
    expect(chart.sizeHandlers).toHaveLength(1);
  });

  it('moves the listener to the new chart when the segments change, leaking none on the old one', () => {
    const { rerender } = render(<PriceChart {...props()} />);
    const first = only();
    rerender(
      <PriceChart {...props([pointOf('2026-10-05', '20.00'), pointOf('2026-10-06', '21.00')])} />,
    );
    const second = recorder.charts[1];
    if (second === undefined) throw new Error('no second chart was drawn');
    expect(first.removed).toBe(true);
    expect(first.sizeHandlers).toEqual([]);
    expect(second.sizeHandlers).toHaveLength(1);
    const swap = recorder.calls.slice(recorder.calls.indexOf('unsubscribeSizeChange'));
    expect(swap.slice(0, 3)).toEqual(['unsubscribeSizeChange', 'remove', 'createChart']);
    expect(swap.filter((call) => call === 'subscribeSizeChange')).toHaveLength(1);
    resize(first, 500, 240);
    expect(first.fitContent).toBe(1);
    resize(second, 500, 240);
    expect(second.fitContent).toBe(2);
  });

  it('keeps its one listener through theme and palette switches and subscribes nothing more', () => {
    const style = document.createElement('style');
    style.setAttribute('data-test', 'tokens');
    style.textContent =
      ':root { --color-chart-line: #44403c; --color-chart-filled: #a8a29e; --color-chart-text: #57534e; --color-chart-grid: #ebe5dc; } ' +
      "[data-theme='dark'] { --color-chart-line: #38bdf8; --color-chart-filled: #94a3b8; --color-chart-text: #94a3b8; --color-chart-grid: #1e293b; }";
    document.head.append(style);
    render(<PriceChart {...props()} />);
    const chart = only();
    act(() => {
      setTheme('dark');
    });
    act(() => {
      setPalette('blue-orange');
    });
    act(() => {
      setTheme('light');
    });
    expect(recorder.calls.filter((call) => call === 'subscribeSizeChange')).toHaveLength(1);
    expect(recorder.calls).not.toContain('unsubscribeSizeChange');
    expect(chart.sizeHandlers).toHaveLength(1);
    const fitsBefore = chart.fitContent;
    resize(chart, 400, 240);
    expect(chart.fitContent).toBe(fitsBefore + 1);
  });

  it('leaves exactly one live listener on the surviving chart under React strict mode', () => {
    render(
      <StrictMode>
        <PriceChart {...props()} />
      </StrictMode>,
    );
    const live = recorder.charts.filter((chart) => !chart.removed);
    expect(live).toHaveLength(1);
    for (const chart of recorder.charts) {
      expect(chart.sizeHandlers).toHaveLength(chart.removed ? 0 : 1);
    }
    const survivor = live[0];
    if (survivor === undefined) throw new Error('no live chart');
    const before = survivor.fitContent;
    resize(survivor, 320, 240);
    expect(survivor.fitContent).toBe(before + 1);
  });

  it('does nothing when a size change is played after the unmount', () => {
    const { unmount } = render(<PriceChart {...props()} />);
    const chart = only();
    unmount();
    resize(chart, 500, 240);
    expect(chart.fitContent).toBe(1);
    expect(recorder.calls.filter((call) => call === 'unsubscribeSizeChange')).toHaveLength(1);
  });
});
