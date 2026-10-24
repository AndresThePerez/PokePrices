import { describe, expect, it } from 'vitest';
import type { AutoscaleInfo, TickMarkFormatter, Time } from 'lightweight-charts';
import { axisPrice, dayTick, paddedAutoscale, paddedRange } from './series';

// TickMarkType of lightweight-charts 5.2.1, as plain numbers: series.ts must not import the
// library at runtime (it is in the entry chunk; the chart is a lazy chunk).
const YEAR = 0;
const MONTH = 1;
const DAY_OF_MONTH = 2;
const TIME = 3;

describe('paddedRange: the y range is padded to at least ±1 % of the larger magnitude (F11)', () => {
  it('opens a flat 2257.87 series to about 2235.29 – 2280.45, never a 0.02 window', () => {
    const range = paddedRange(2257.87, 2257.87);
    expect(range.minValue).toBeCloseTo(2235.2913, 4);
    expect(range.maxValue).toBeCloseTo(2280.4487, 4);
  });

  it('pads both ends by 1 % of the larger magnitude, not of the spread', () => {
    const range = paddedRange(928.06, 968.91);
    expect(range.minValue).toBeCloseTo(928.06 - 9.6891, 4);
    expect(range.maxValue).toBeCloseTo(968.91 + 9.6891, 4);
  });

  it('uses the larger magnitude when the low end is the bigger one', () => {
    const range = paddedRange(-10, 5);
    expect(range.minValue).toBeCloseTo(-10.1, 6);
    expect(range.maxValue).toBeCloseTo(5.1, 6);
  });

  it('never returns an empty range: a zero series still spans one cent each way', () => {
    expect(paddedRange(0, 0)).toEqual({ minValue: -0.01, maxValue: 0.01 });
  });
});

describe('paddedAutoscale: the autoscaleInfoProvider of every segment series', () => {
  it('pads the range the library computed and keeps its margins', () => {
    const base = (): AutoscaleInfo => ({
      priceRange: { minValue: 2257.87, maxValue: 2257.87 },
      margins: { above: 4, below: 2 },
    });
    const info = paddedAutoscale(base);
    expect(info?.margins).toEqual({ above: 4, below: 2 });
    expect(info?.priceRange?.minValue).toBeCloseTo(2235.2913, 4);
    expect(info?.priceRange?.maxValue).toBeCloseTo(2280.4487, 4);
  });

  it('passes "nothing to scale" through unchanged', () => {
    expect(paddedAutoscale(() => null)).toBeNull();
    expect(paddedAutoscale(() => ({ priceRange: null }))).toEqual({ priceRange: null });
  });
});

describe('axisPrice: the y-axis labels are "$" + 2 decimals with thousands separators', () => {
  it.each([
    [2257.87, '$2,257.87'],
    [2235.2913, '$2,235.29'],
    [1234567.891, '$1,234,567.89'],
    [70.8, '$70.80'],
    [0.1, '$0.10'],
    [0, '$0.00'],
    [999.999, '$1,000.00'],
    [-1.5, '-$1.50'],
    [-0.001, '$0.00'],
  ])('%s -> %s', (value, label) => {
    expect(axisPrice(value)).toBe(label);
  });
});

describe('dayTick: x ticks from a fixed English month table, no locale and no clock', () => {
  it('prints a day tick as "Oct 9", without a leading zero', () => {
    expect(dayTick('2026-10-09', DAY_OF_MONTH)).toBe('Oct 9');
    expect(dayTick('2026-10-31', DAY_OF_MONTH)).toBe('Oct 31');
  });

  it('prints the month name on a month tick and the year on a year tick', () => {
    expect(dayTick('2026-10-01', MONTH)).toBe('Oct');
    expect(dayTick('2027-01-01', YEAR)).toBe('2027');
  });

  it('names all twelve months in English', () => {
    const names = Array.from({ length: 12 }, (_, index) =>
      dayTick(`2026-${String(index + 1).padStart(2, '0')}-15`, DAY_OF_MONTH),
    );
    expect(names).toEqual([
      'Jan 15',
      'Feb 15',
      'Mar 15',
      'Apr 15',
      'May 15',
      'Jun 15',
      'Jul 15',
      'Aug 15',
      'Sep 15',
      'Oct 15',
      'Nov 15',
      'Dec 15',
    ]);
  });

  it('is a TickMarkFormatter and ignores the locale argument the library passes', () => {
    const formatter: TickMarkFormatter = dayTick;
    expect(formatter).toBe(dayTick);
    const tick: (time: Time, type: number, locale: string) => string | null = dayTick;
    expect(tick('2026-10-09', DAY_OF_MONTH, 'de-DE')).toBe('Oct 9');
  });

  it('leaves anything that is not an API day, or a time-of-day tick, to the library (null)', () => {
    expect(dayTick('2026-13-01', DAY_OF_MONTH)).toBeNull();
    expect(dayTick('20261009', DAY_OF_MONTH)).toBeNull();
    expect(dayTick(1_760_000_000 as Time, DAY_OF_MONTH)).toBeNull();
    expect(dayTick({ year: 2026, month: 10, day: 9 }, DAY_OF_MONTH)).toBeNull();
    expect(dayTick('2026-10-09', TIME)).toBeNull();
  });
});
