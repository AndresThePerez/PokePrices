// The pure model of the PokéPrices price history (D4–D7): ranges, segments, the History begins
// banner, the hidden summary and the data table rows. Every printed amount is the API's string
// through grouped(); Number() is used only to choose the high and the low (D2). Day arithmetic
// happens only on API day strings, in UTC (D1): nothing here reads the clock.
import type { PricePoint, PriceRange, PriceSeries } from '../api/client';
import { grouped } from '../format';

/** The five ranges in order (D4); there is no 1D. */
export const RANGES = ['1W', '1M', '3M', 'YTD', '1Y'] as const satisfies readonly PriceRange[];
export const DEFAULT_RANGE: PriceRange = '1M';

const DAY_MS = 86_400_000;
const DAY = /^(\d{4})-(\d{2})-(\d{2})$/;

/** The UTC midnight of an API day string in ms, or NaN for anything that is not YYYY-MM-DD. */
function dayMs(day: string): number {
  const match = DAY.exec(day);
  if (match === null) return Number.NaN;
  return Date.UTC(Number(match[1]), Number(match[2]) - 1, Number(match[3]));
}

/**
 * The points split where the line must break (D6): a new segment starts at a point whose day is
 * not exactly the previous point's day + 1, or whose series_epoch differs from the previous one.
 */
export function segments(points: readonly PricePoint[]): PricePoint[][] {
  const result: PricePoint[][] = [];
  let current: PricePoint[] = [];
  let previous: PricePoint | null = null;
  for (const point of points) {
    const joins =
      previous !== null &&
      previous.series_epoch === point.series_epoch &&
      dayMs(point.day) - dayMs(previous.day) === DAY_MS;
    if (!joins && current.length > 0) {
      result.push(current);
      current = [];
    }
    current.push(point);
    previous = point;
  }
  if (current.length > 0) result.push(current);
  return result;
}

/** D5: shown exactly when history_begins is after the window's first day (string order). */
export function showsBanner(historyBegins: string | null, from: string | null): boolean {
  return historyBegins !== null && from !== null && historyBegins > from;
}

export function bannerText(historyBegins: string): string {
  return `History begins ${historyBegins}. PokéPrices has no daily close for this variant before that day.`;
}

/** The series a visitor can choose: those PokéPrices holds a close for, in API order (D7). */
export function eligibleSeries(series: readonly PriceSeries[]): PriceSeries[] {
  return series.filter((one) => one.history_begins !== null);
}

/** The points whose day lies in [from, to]. */
export function pointsInRange(
  points: readonly PricePoint[],
  from: string,
  to: string,
): PricePoint[] {
  return points.filter((point) => point.day >= from && point.day <= to);
}

function capitalised(label: string): string {
  return label.charAt(0).toUpperCase() + label.slice(1);
}

/** The first point with the highest (sign 1) or lowest (sign -1) close; ties keep the earliest. */
function extreme(points: readonly PricePoint[], sign: 1 | -1): PricePoint | undefined {
  let best: PricePoint | undefined;
  for (const point of points) {
    if (best === undefined || sign * (Number(point.close) - Number(best.close)) > 0) best = point;
  }
  return best;
}

function money(point: PricePoint): string {
  return `$${grouped(point.close)}`;
}

/**
 * The hidden summary (D7) of the given points (ascending by day). Statistics cover the real
 * points of the newest series_epoch only, never a comparison across epochs; filled points are
 * counted, never measured.
 */
export function summaryText(
  label: string,
  range: PriceRange,
  points: readonly PricePoint[],
): string {
  const head = `${capitalised(label)}, ${range}: `;
  const newestEpoch = points.at(-1)?.series_epoch;
  const ofNewest = points.filter((point) => point.series_epoch === newestEpoch);
  const real = ofNewest.filter((point) => !point.is_filled);
  const first = real[0];
  const last = real.at(-1);
  const high = extreme(real, 1);
  const low = extreme(real, -1);
  let text: string;
  if (first === undefined || last === undefined || high === undefined || low === undefined) {
    text = `${head}no new daily close in this range.`;
  } else if (real.length === 1) {
    text = `${head}1 daily close, ${money(last)} on ${last.day}.`;
  } else {
    text =
      `${head}${String(real.length)} daily closes from ${first.day} to ${last.day}. ` +
      `Last close ${money(last)} on ${last.day}. High ${money(high)} on ${high.day}. ` +
      `Low ${money(low)} on ${low.day}.`;
  }
  const filled = points.filter((point) => point.is_filled).length;
  if (filled === 1) text += ' 1 carried-forward day repeats an earlier close.';
  if (filled > 1) text += ` ${String(filled)} carried-forward days repeat an earlier close.`;
  const mappingDay = ofNewest[0]?.day;
  if (mappingDay !== undefined && ofNewest.length < points.length) {
    text += ` The TCGplayer product mapping changed on ${mappingDay}: earlier closes are a different series.`;
  }
  return text;
}

export interface TableRow {
  readonly day: string;
  readonly close: string;
  readonly note: string;
}

const MAPPING_NOTE = 'New product mapping from this day: not comparable with earlier closes';

/** One data table row per point, ascending by day, with its D7 note. */
export function tableRows(points: readonly PricePoint[]): TableRow[] {
  const ordered = [...points].sort((a, b) => (a.day < b.day ? -1 : a.day > b.day ? 1 : 0));
  return ordered.map((point, index) => {
    const previous = ordered[index - 1];
    let note = '';
    if (point.is_filled) {
      note =
        point.filled_from === null
          ? 'Carried forward'
          : `Carried forward from ${point.filled_from}`;
    } else if (previous !== undefined && previous.series_epoch !== point.series_epoch) {
      note = MAPPING_NOTE;
    }
    return { day: point.day, close: money(point), note };
  });
}
