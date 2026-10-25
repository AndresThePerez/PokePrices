// A recording stand-in for lightweight-charts (T06). jsdom has no canvas, so every test file that
// renders a priced card replaces the package with this module:
//   vi.mock('lightweight-charts', () => import('../test/lightweightChartsFake'));
// It records what the chart wrapper asks for (createChart, addSeries, setData, applyOptions,
// fitContent, subscribeSizeChange, unsubscribeSizeChange, remove) so tests can read the exact
// options and data, and resize() plays the library's size-change events to the live handlers.

export interface RecordedSeries {
  readonly definition: unknown;
  readonly options: unknown;
  /** One entry per setData call. */
  readonly data: unknown[];
  /** One entry per applyOptions call. */
  readonly applied: unknown[];
}

export interface RecordedChart {
  readonly container: HTMLElement;
  readonly options: unknown;
  readonly applied: unknown[];
  readonly series: RecordedSeries[];
  fitContent: number;
  removed: boolean;
  /** The size-change handlers still subscribed on the time scale, in subscription order. */
  readonly sizeHandlers: ((width: number, height: number) => void)[];
}

interface Recorder {
  readonly charts: RecordedChart[];
  /** Every call in order, e.g. 'createChart', 'addSeries', 'setData', 'fitContent'. */
  readonly calls: string[];
  /** When set, createChart throws it (the error boundary test). */
  failWith: Error | null;
}

export const recorder: Recorder = { charts: [], calls: [], failWith: null };

/** Forgets every recorded chart and call; createChart draws again. */
export function resetRecorder(): void {
  recorder.charts.length = 0;
  recorder.calls.length = 0;
  recorder.failWith = null;
}

export const ColorType = { Solid: 'solid', VerticalGradient: 'gradient' } as const;

export const LineSeries = { type: 'Line' } as const;

export function createChart(container: HTMLElement, options?: unknown) {
  if (recorder.failWith !== null) throw recorder.failWith;
  const chart: RecordedChart = {
    container,
    options,
    applied: [],
    series: [],
    fitContent: 0,
    removed: false,
    sizeHandlers: [],
  };
  recorder.charts.push(chart);
  recorder.calls.push('createChart');
  return {
    addSeries(definition: unknown, seriesOptions?: unknown) {
      const series: RecordedSeries = { definition, options: seriesOptions, data: [], applied: [] };
      chart.series.push(series);
      recorder.calls.push('addSeries');
      return {
        setData(data: unknown) {
          series.data.push(data);
          recorder.calls.push('setData');
        },
        applyOptions(next: unknown) {
          series.applied.push(next);
          recorder.calls.push('series.applyOptions');
        },
      };
    },
    applyOptions(next: unknown) {
      chart.applied.push(next);
      recorder.calls.push('applyOptions');
    },
    timeScale() {
      return {
        fitContent() {
          chart.fitContent += 1;
          recorder.calls.push('fitContent');
        },
        subscribeSizeChange(handler: (width: number, height: number) => void) {
          chart.sizeHandlers.push(handler);
          recorder.calls.push('subscribeSizeChange');
        },
        unsubscribeSizeChange(handler: (width: number, height: number) => void) {
          const index = chart.sizeHandlers.indexOf(handler);
          if (index >= 0) chart.sizeHandlers.splice(index, 1);
          recorder.calls.push('unsubscribeSizeChange');
        },
      };
    },
    remove() {
      chart.removed = true;
      recorder.calls.push('remove');
    },
  };
}

/** Fires a time-scale size change on `chart`, as the library does when autoSize measures. */
export function resize(chart: RecordedChart, width: number, height: number): void {
  for (const handler of [...chart.sizeHandlers]) handler(width, height);
}
