// shortChangeLine: the change chip of a short list row, without "vs previous close (<day>)".
import { describe, expect, it } from 'vitest';
import { changeLine, shortChangeLine, type ChangeFields } from './format';

function change(fields: Partial<ChangeFields>): ChangeFields {
  return {
    previous_close: '5.71',
    previous_day: '2026-10-11',
    change: '20.63',
    change_pct: '361.30',
    ...fields,
  };
}

describe('shortChangeLine', () => {
  it.each([
    ['a rise', change({}), '▲ +361.30%', 'text-up'],
    ['a fall', change({ change: '-99.89', change_pct: '-60.54' }), '▼ -60.54%', 'text-down'],
    ['a rise of 0.00 %', change({ change: '0.01', change_pct: '0.00' }), '▲ +0.00%', 'text-up'],
    [
      'a fall of -0.00 %',
      change({ change: '-0.01', change_pct: '-0.00' }),
      '▼ -0.00%',
      'text-down',
    ],
    [
      'no change_pct',
      change({ change: '-1234.50', change_pct: null }),
      '▼ -$1,234.50',
      'text-down',
    ],
    ['no change', change({ change: '0.00', change_pct: '0.00' }), 'No change', 'text-flat'],
    [
      'no previous close',
      change({ previous_close: null, previous_day: null, change: null, change_pct: null }),
      'No previous close yet',
      'text-flat',
    ],
  ])('prints %s without the previous-close day', (_case, price, text, tone) => {
    expect(shortChangeLine(price)).toEqual({ text, tone });
    expect(shortChangeLine(price).text).not.toContain('vs previous close');
  });

  it('keeps the tone of the full line', () => {
    for (const price of [change({}), change({ change: '-0.66', change_pct: '-2.44' })]) {
      expect(shortChangeLine(price).tone).toBe(changeLine(price).tone);
    }
  });
});
