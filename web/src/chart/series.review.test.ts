import { describe, expect, it, vi } from 'vitest';
import type { AutoscaleInfo, Time } from 'lightweight-charts';
import seriesSource from './series.ts?raw';
import chartSource from './PriceChart.tsx?raw';
import sparklineSource from './Sparkline.tsx?raw';
import historySource from '../pages/PriceHistory.tsx?raw';
import { axisPrice, dayTick, paddedAutoscale, paddedRange } from './series';

// A lightweight-charts load in the entry graph would put the library in the eager bundle.
const libraryLoads = vi.hoisted(() => ({ count: 0 }));
vi.mock('lightweight-charts', () => {
  libraryLoads.count += 1;
  return {};
});

const DAY_OF_MONTH = 2;
const MONTH = 1;
const LABEL = /^-?\$\d{1,3}(,\d{3})*\.\d{2}$/;

describe('axisPrice never prints a label that is not money (F11, honesty surface)', () => {
  it.each([
    ['-0', -0, '$0.00'],
    ['a sub-cent negative', -0.004, '$0.00'],
    ['a value that rounds across a thousands boundary', 999.9951, '$1,000.00'],
    ['a million minus a hair', 999999.999, '$1,000,000.00'],
    ['a large but plain number', 123456789012.34, '$123,456,789,012.34'],
  ])('%s', (_name, value, label) => {
    expect(axisPrice(value)).toBe(label);
    expect(axisPrice(value)).toMatch(LABEL);
  });

  it.each([
    ['NaN', Number.NaN],
    ['Infinity', Number.POSITIVE_INFINITY],
    ['-Infinity', Number.NEGATIVE_INFINITY],
  ])(
    'does not print %s, undefined or a bare $ for a value the library should never send',
    (_n, value) => {
      const label = axisPrice(value);
      expect(label).not.toMatch(/NaN|Infinity|undefined/);
      expect(label).not.toBe('$');
    },
  );

  it('does not print exponent notation for a 1e21-class magnitude', () => {
    for (const value of [1e21, -1e21, 1e25]) {
      expect(axisPrice(value)).not.toMatch(/e[+-]?\d/i);
    }
  });
});

describe('paddedAutoscale only ever widens the library range (F11)', () => {
  it('keeps the library range inside the padded one for a huge range, and never mutates it', () => {
    const base: AutoscaleInfo = {
      priceRange: { minValue: 0, maxValue: 1_000_000 },
      margins: { above: 9, below: 3 },
    };
    const frozen = structuredClone(base);
    const info = paddedAutoscale(() => base);
    expect(base).toEqual(frozen);
    expect(info?.priceRange?.minValue).toBeLessThan(0);
    expect(info?.priceRange?.maxValue).toBeGreaterThan(1_000_000);
    expect(info?.margins).toEqual({ above: 9, below: 3 });
  });

  it('calls the base provider exactly once per autoscale', () => {
    const base = vi.fn((): AutoscaleInfo | null => ({ priceRange: { minValue: 1, maxValue: 2 } }));
    paddedAutoscale(base);
    expect(base).toHaveBeenCalledTimes(1);
  });

  it.each([
    [0.01, 0.01],
    [0, 0],
    [-5, -5],
    [-10, 5],
    [1e-9, 2e-9],
  ])('paddedRange(%s, %s) contains both inputs', (a, b) => {
    const range = paddedRange(a, b);
    expect(range.minValue).toBeLessThan(Math.min(a, b));
    expect(range.maxValue).toBeGreaterThan(Math.max(a, b));
  });
});

describe('dayTick leaves anything it cannot name to the library', () => {
  it.each(['2026-00-10', '2026-10-9', ' 2026-10-09', '2026-10-09T00:00:00Z', '', 'Oct 9'])(
    'returns null for %j',
    (time) => {
      expect(dayTick(time, DAY_OF_MONTH)).toBeNull();
      expect(dayTick(time, MONTH)).toBeNull();
    },
  );

  it('returns null for a unix timestamp and a business-day object (the library may send either)', () => {
    expect(dayTick(1_760_000_000 as Time, MONTH)).toBeNull();
    expect(dayTick({ year: 2026, month: 2, day: 28 }, DAY_OF_MONTH)).toBeNull();
  });

  it('prints 29 February of a leap year as the day it is, with no clock or Date involved', () => {
    expect(dayTick('2028-02-29', DAY_OF_MONTH)).toBe('Feb 29');
    expect(dayTick('2028-03-01', MONTH)).toBe('Mar');
  });
});

describe('the browser never reads the clock, and series.ts stays out of the library chunk (R132)', () => {
  it.each([
    ['series.ts', seriesSource],
    ['PriceChart.tsx', chartSource],
    ['Sparkline.tsx', sparklineSource],
    ['PriceHistory.tsx', historySource],
  ])('%s has no Date.now, new Date, Intl, toLocale* or performance.now', (_name, source) => {
    const code = source.replace(/\/\/.*$/gm, '').replace(/\/\*[\s\S]*?\*\//g, '');
    expect(code).not.toMatch(/Date\.now|new Date|\bIntl\b|toLocale|performance\.now/);
  });

  it('importing the helpers and the page does not load lightweight-charts at runtime', async () => {
    await import('./series');
    await import('../pages/PriceHistory');
    expect(libraryLoads.count).toBe(0);
  });
});
