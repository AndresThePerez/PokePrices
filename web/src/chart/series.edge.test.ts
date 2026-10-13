import { describe, expect, it } from 'vitest';
import { filledOf, pointOf } from '../test/fixtures';
import { segments, showsBanner, summaryText, tableRows } from './series';

describe('summaryText edge cases (D7, D2)', () => {
  it('picks the earliest day for both high and low when every close is equal', () => {
    const flat = [
      pointOf('2026-10-10', '5.00'),
      pointOf('2026-10-11', '5.00'),
      pointOf('2026-10-12', '5.00'),
    ];
    expect(summaryText('holo, unlimited', '1M', flat)).toBe(
      'Holo, unlimited, 1M: 3 daily closes from 2026-10-10 to 2026-10-12. Last close $5.00 on 2026-10-12. High $5.00 on 2026-10-10. Low $5.00 on 2026-10-10.',
    );
  });

  it('measures every real close of the newest epoch across a gap, not just the last segment', () => {
    const gapped = [
      pointOf('2026-10-01', '900.00'),
      pointOf('2026-10-02', '10.00'),
      pointOf('2026-10-06', '500.00'),
      pointOf('2026-10-07', '400.00'),
    ];
    expect(segments(gapped)).toHaveLength(2);
    expect(summaryText('holo', '3M', gapped)).toBe(
      'Holo, 3M: 4 daily closes from 2026-10-01 to 2026-10-07. Last close $400.00 on 2026-10-07. High $900.00 on 2026-10-01. Low $10.00 on 2026-10-02.',
    );
  });

  it('leaves older-epoch extremes out of the statistics but counts their filled days and names the mapping day', () => {
    const mixed = [
      pointOf('2026-10-01', '9999.00', { series_epoch: 1 }),
      filledOf('2026-10-02', '9999.00', '2026-10-01', 1),
      filledOf('2026-10-03', '9999.00', '2026-10-01', 1),
      pointOf('2026-10-04', '20.00', { series_epoch: 2 }),
      pointOf('2026-10-05', '30.00', { series_epoch: 2 }),
    ];
    expect(summaryText('holo', '1M', mixed)).toBe(
      'Holo, 1M: 2 daily closes from 2026-10-04 to 2026-10-05. Last close $30.00 on 2026-10-05. High $30.00 on 2026-10-05. Low $20.00 on 2026-10-04. 2 carried-forward days repeat an earlier close. The TCGplayer product mapping changed on 2026-10-04: earlier closes are a different series.',
    );
  });

  it('says no new close, and counts the carried-forward day, when the newest epoch holds only a filled point', () => {
    const onlyFilled = [
      pointOf('2026-10-01', '12.00', { series_epoch: 1 }),
      filledOf('2026-10-02', '12.00', '2026-10-01', 2),
    ];
    expect(summaryText('holo', '1W', onlyFilled)).toBe(
      'Holo, 1W: no new daily close in this range. 1 carried-forward day repeats an earlier close. The TCGplayer product mapping changed on 2026-10-02: earlier closes are a different series.',
    );
  });

  it('takes the last close from the last real point when filled days trail it', () => {
    const trailing = [
      pointOf('2026-10-01', '1.10'),
      pointOf('2026-10-02', '1.20'),
      filledOf('2026-10-03', '1.20', '2026-10-02'),
      filledOf('2026-10-04', '1.20', '2026-10-02'),
    ];
    expect(summaryText('holo', '1W', trailing)).toBe(
      'Holo, 1W: 2 daily closes from 2026-10-01 to 2026-10-02. Last close $1.20 on 2026-10-02. High $1.20 on 2026-10-02. Low $1.10 on 2026-10-01. 2 carried-forward days repeat an earlier close.',
    );
  });

  it('prints the point strings grouped, never re-rounded, for a seven-figure and a sub-dollar close', () => {
    const wide = [pointOf('2026-10-01', '1234567.80'), pointOf('2026-10-02', '0.07')];
    expect(summaryText('holo', 'YTD', wide)).toBe(
      'Holo, YTD: 2 daily closes from 2026-10-01 to 2026-10-02. Last close $0.07 on 2026-10-02. High $1,234,567.80 on 2026-10-01. Low $0.07 on 2026-10-02.',
    );
  });
});

describe('banner and segments boundaries (D5, D6)', () => {
  it.each([
    ['2026-10-01', '2026-09-30', true],
    ['2026-09-30', '2026-10-01', false],
    ['2027-01-01', '2026-12-31', true],
    ['2026-09-13', '2026-09-13', false],
    ['2026-09-14', '2026-09-13', true],
  ])('showsBanner(%s, %s) is %s', (begins, from, expected) => {
    expect(showsBanner(begins, from)).toBe(expected);
  });

  it('splits a repeated day and a non-leap 2027-02-28 to 2027-03-02 jump, and joins 2027-02-28 to 2027-03-01', () => {
    expect(segments([pointOf('2027-02-28', '1.00'), pointOf('2027-03-01', '1.00')])).toHaveLength(
      1,
    );
    expect(segments([pointOf('2027-02-28', '1.00'), pointOf('2027-03-02', '1.00')])).toHaveLength(
      2,
    );
    expect(segments([pointOf('2026-10-01', '1.00'), pointOf('2026-10-01', '1.00')])).toHaveLength(
      2,
    );
  });

  it('keeps every point in order across the segments it makes', () => {
    const points = [
      pointOf('2026-10-01', '1.00'),
      pointOf('2026-10-02', '1.00', { series_epoch: 2 }),
      pointOf('2026-10-05', '1.00', { series_epoch: 2 }),
      pointOf('2026-10-06', '1.00', { series_epoch: 2 }),
    ];
    const parts = segments(points);
    expect(parts.map((part) => part.map((point) => point.day))).toEqual([
      ['2026-10-01'],
      ['2026-10-02'],
      ['2026-10-05', '2026-10-06'],
    ]);
  });
});

describe('tableRows edge cases (D7)', () => {
  it('notes a filled point without a source day as plain Carried forward and sorts unordered input', () => {
    const rows = tableRows([
      pointOf('2026-10-03', '2.00'),
      pointOf('2026-10-02', '1.00', { is_filled: true, filled_from: null }),
    ]);
    expect(rows).toEqual([
      { day: '2026-10-02', close: '$1.00', note: 'Carried forward' },
      { day: '2026-10-03', close: '$2.00', note: '' },
    ]);
  });
});
