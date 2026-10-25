import { describe, expect, it } from 'vitest';
import { changeLine, grouped, initialOf, signed, variantLabel } from './format';

describe('grouped (D2)', () => {
  it.each([
    ['1234.50', '1,234.50'],
    ['0.07', '0.07'],
    ['1000000.00', '1,000,000.00'],
    ['0.10', '0.10'],
    ['999', '999'],
    ['1000', '1,000'],
  ])('groups %s as %s, copying every digit', (amount, expected) => {
    expect(grouped(amount)).toBe(expected);
  });

  it.each(['abc', '1e3', '-12.00', '12.', ''])('copies the non-decimal %j as it came', (amount) => {
    expect(grouped(amount)).toBe(amount);
  });
});

describe('signed (D2)', () => {
  it('reads the sign from the string and strips it from the digits', () => {
    expect(signed('-1.30')).toEqual({ negative: true, digits: '1.30' });
    expect(signed('+2.09')).toEqual({ negative: false, digits: '2.09' });
    expect(signed('1.45')).toEqual({ negative: false, digits: '1.45' });
    expect(signed('0.00')).toEqual({ negative: false, digits: '0.00' });
  });
});

describe('changeLine (D3)', () => {
  const base = {
    previous_close: '69.36',
    previous_day: '2026-10-11',
    change: '1.45',
    change_pct: '2.09',
  };

  it('prints an up change with arrow, sign and percentage in the up tone', () => {
    expect(changeLine(base)).toEqual({
      text: '▲ +$1.45 (+2.09%) vs previous close (2026-10-11)',
      tone: 'text-up',
    });
  });

  it('prints a down change with an ASCII minus in the down tone', () => {
    expect(changeLine({ ...base, change: '-1234.50', change_pct: '-99.99' })).toEqual({
      text: '▼ -$1,234.50 (-99.99%) vs previous close (2026-10-11)',
      tone: 'text-down',
    });
  });

  it('omits the percentage when change_pct is null', () => {
    expect(changeLine({ ...base, change: '-0.25', change_pct: null }).text).toBe(
      '▼ -$0.25 vs previous close (2026-10-11)',
    );
  });

  it('prints No change for a zero change, and from a zero previous close', () => {
    expect(changeLine({ ...base, change: '0.00', change_pct: '0.00' })).toEqual({
      text: 'No change vs previous close (2026-10-11)',
      tone: 'text-flat',
    });
    expect(
      changeLine({ ...base, previous_close: '0.00', change: '0.00', change_pct: null }),
    ).toEqual({ text: 'No change vs previous close (2026-10-11)', tone: 'text-flat' });
  });

  it('prints an up change from a zero previous close without a percentage', () => {
    expect(
      changeLine({ ...base, previous_close: '0.00', change: '0.25', change_pct: null }),
    ).toEqual({ text: '▲ +$0.25 vs previous close (2026-10-11)', tone: 'text-up' });
  });

  it('prints No previous close yet when any of the previous fields is null', () => {
    const none = { text: 'No previous close yet', tone: 'text-flat' };
    expect(changeLine({ ...base, previous_close: null })).toEqual(none);
    expect(changeLine({ ...base, previous_day: null })).toEqual(none);
    expect(changeLine({ ...base, change: null })).toEqual(none);
  });

  it('accepts a movers row: non-null strings with extra fields (T09 needs no adapter)', () => {
    const moverRow = {
      rank: 1,
      card_id: 'ex5-17',
      close: '26.34',
      previous_close: '5.71',
      previous_day: '2026-10-11',
      change: '20.63',
      change_pct: '361.30',
    };
    expect(changeLine(moverRow).text).toBe('▲ +$20.63 (+361.30%) vs previous close (2026-10-11)');
  });
});

describe('variantLabel', () => {
  it('joins finish, edition and treatment, skipping "-" and empty parts', () => {
    expect(
      variantLabel({
        variant_key: 'base1-4:holo:unlimited:-',
        finish: 'holo',
        edition: 'unlimited',
        treatment: '-',
      }),
    ).toBe('holo, unlimited');
  });

  it('falls back to the variant key when every part is empty', () => {
    expect(
      variantLabel({ variant_key: 'x-1:-:-:-', finish: '-', edition: '', treatment: '-' }),
    ).toBe('x-1:-:-:-');
  });
});

describe('initialOf (D2-i2-T08)', () => {
  it.each([
    ['', ''],
    ['   ', ''],
    ['unown', 'U'],
    ['  pikachu  ', 'P'],
    ['ñandu', 'Ñ'],
    ['Charizard', 'C'],
    ['\u{1F600}smile', '\u{1F600}'],
    ['\u{10428}x', '\u{10400}'],
  ])('reads the initial of %j as %j', (name, expected) => {
    expect(initialOf(name)).toBe(expected);
  });

  it('never splits an astral first character into a lone surrogate', () => {
    const initial = initialOf('\u{1F600}');
    expect(initial).toHaveLength(2);
    expect(initial.codePointAt(0)).toBe(0x1f600);
  });
});
