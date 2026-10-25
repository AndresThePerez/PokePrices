// The daily-close chart (D6): lightweight-charts v5, one line series per segment, loaded lazily by
// PriceHistory so the library is its own chunk. Colours come from the D8 tokens on <html>; a token
// that reads empty falls back to its light-theme hex, so the library never receives ''.
import { useEffect, useRef } from 'react';
import {
  ColorType,
  LineSeries,
  createChart,
  type ChartOptions,
  type DeepPartial,
  type IChartApi,
  type ISeriesApi,
  type LineData,
  type LineSeriesPartialOptions,
  type SizeChangeEventHandler,
} from 'lightweight-charts';
import type { PricePoint, PriceRange } from '../api/client';
import { usePreferences } from '../preferences';
import { axisPrice, dayTick, paddedAutoscale } from './series';

export interface PriceChartProps {
  /** The selected series' points in range, split by segments() (D6). */
  readonly segments: readonly (readonly PricePoint[])[];
  readonly label: string;
  readonly range: PriceRange;
  /** The id of the hidden summary that describes the chart (D7). */
  readonly describedBy: string;
}

interface Colours {
  readonly line: string;
  readonly filled: string;
  readonly text: string;
  readonly grid: string;
}

/** D8's light-theme values: what a token that reads empty or whitespace is replaced by. */
const LIGHT: Colours = { line: '#44403c', filled: '#a8a29e', text: '#57534e', grid: '#ebe5dc' };

function readColours(): Colours {
  const style = getComputedStyle(document.documentElement);
  const token = (name: string, fallback: string): string => {
    const value = style.getPropertyValue(name).trim();
    return value === '' ? fallback : value;
  };
  return {
    line: token('--color-chart-line', LIGHT.line),
    filled: token('--color-chart-filled', LIGHT.filled),
    text: token('--color-chart-text', LIGHT.text),
    grid: token('--color-chart-grid', LIGHT.grid),
  };
}

/** The options that follow the theme: text, grid and scale borders. */
function themed(colours: Colours) {
  return {
    grid: { vertLines: { color: colours.grid }, horzLines: { color: colours.grid } },
    rightPriceScale: { borderColor: colours.grid },
    timeScale: { borderColor: colours.grid },
  };
}

/** The chart options; the theme's keys are kept, the time scale adds its month ticks (F11). */
function chartOptions(colours: Colours): DeepPartial<ChartOptions> {
  const theme = themed(colours);
  return {
    autoSize: true,
    layout: {
      background: { type: ColorType.Solid, color: 'transparent' },
      textColor: colours.text,
      attributionLogo: true,
    },
    ...theme,
    timeScale: { ...theme.timeScale, tickMarkFormatter: dayTick },
    localization: { dateFormat: 'yyyy-MM-dd' },
    handleScroll: false,
    handleScale: false,
  };
}

function seriesOptions(colours: Colours): LineSeriesPartialOptions {
  return {
    color: colours.line,
    lineWidth: 2,
    pointMarkersVisible: true,
    priceLineVisible: false,
    lastValueVisible: false,
    // "$" labels with thousands separators, and a y range padded to at least ±1 % (F11).
    priceFormat: { type: 'custom', formatter: axisPrice, minMove: 0.01 },
    autoscaleInfoProvider: paddedAutoscale,
  };
}

/** One data item per point; Number() is for geometry only (D2). Filled points carry their colour. */
function items(segment: readonly PricePoint[], filled: string): LineData[] {
  return segment.map((point) =>
    point.is_filled
      ? { time: point.day, value: Number(point.close), color: filled }
      : { time: point.day, value: Number(point.close) },
  );
}

interface Drawn {
  readonly chart: IChartApi;
  readonly series: readonly ISeriesApi<'Line'>[];
  colours: Colours;
}

export default function PriceChart({ segments, label, range, describedBy }: PriceChartProps) {
  const container = useRef<HTMLDivElement>(null);
  const drawn = useRef<Drawn | null>(null);
  const { resolvedTheme, palette } = usePreferences();

  useEffect(() => {
    const element = container.current;
    if (element === null) return;
    const colours = readColours();
    const chart = createChart(element, chartOptions(colours));
    let series: ISeriesApi<'Line'>[];
    let stopListening: () => void;
    try {
      series = segments.map((segment) => {
        const line = chart.addSeries(LineSeries, seriesOptions(colours));
        line.setData(items(segment, colours.filled));
        return line;
      });
      chart.timeScale().fitContent();
      // autoSize applies the container's width after this first fit, which was computed for a
      // width of 0, and a later shrink clamps the bar spacing that a regrow keeps: fit again on
      // every size change with a real width, until the cleanup stops listening.
      const scale = chart.timeScale();
      const refit: SizeChangeEventHandler = (width) => {
        if (width > 0) scale.fitContent();
      };
      scale.subscribeSizeChange(refit);
      stopListening = () => {
        scale.unsubscribeSizeChange(refit);
      };
    } catch (error) {
      chart.remove();
      throw error;
    }
    drawn.current = { chart, series, colours };
    return () => {
      drawn.current = null;
      stopListening();
      chart.remove();
    };
  }, [segments]);

  // A theme or palette choice re-reads the tokens and re-applies whatever changed (D6, D8).
  useEffect(() => {
    const current = drawn.current;
    if (current === null) return;
    const colours = readColours();
    const before = current.colours;
    if (
      colours.line === before.line &&
      colours.filled === before.filled &&
      colours.text === before.text &&
      colours.grid === before.grid
    ) {
      return;
    }
    current.chart.applyOptions({ layout: { textColor: colours.text }, ...themed(colours) });
    current.series.forEach((line, index) => {
      line.applyOptions({ color: colours.line });
      const segment = segments[index];
      if (colours.filled !== before.filled && segment !== undefined) {
        line.setData(items(segment, colours.filled));
      }
    });
    if (colours.filled !== before.filled) current.chart.timeScale().fitContent();
    current.colours = colours;
  }, [resolvedTheme, palette, segments]);

  return (
    <div
      ref={container}
      role="img"
      aria-label={`Daily close chart of ${label}, ${range}`}
      aria-describedby={describedBy}
      className="h-[240px] w-full sm:h-[320px]"
    />
  );
}
