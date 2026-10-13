import { describe, expect, it } from 'vitest';
import { filledOf, pointOf, seriesOf } from '../test/fixtures';
import {
  DEFAULT_RANGE,
  RANGES,
  bannerText,
  eligibleSeries,
  pointsInRange,
  segments,
  showsBanner,
  summaryText,
  tableRows,
} from './series';

function days(groups: readonly (readonly { readonly day: string }[])[]): string[][] {
  return groups.map((group) => group.map((point) => point.day));
}

describe('ranges (D4)', () => {
  it('are exactly 1W, 1M, 3M, YTD, 1Y in that order, 1M by default, and no 1D', () => {
    expect(RANGES).toEqual(['1W', '1M', '3M', 'YTD', '1Y']);
    expect(DEFAULT_RANGE).toBe('1M');
  });
});

describe('segments (D6)', () => {
  it('keeps consecutive days of one epoch in one segment', () => {
    const points = [pointOf('2026-10-09', '1.00'), pointOf('2026-10-10', '2.00')];
    expect(days(segments(points))).toEqual([['2026-10-09', '2026-10-10']]);
  });

  it('splits at a one-day gap', () => {
    const points = [
      pointOf('2026-10-09', '1.00'),
      pointOf('2026-10-10', '2.00'),
      pointOf('2026-10-12', '3.00'),
    ];
    expect(days(segments(points))).toEqual([['2026-10-09', '2026-10-10'], ['2026-10-12']]);
  });

  it('splits at an epoch change on consecutive days', () => {
    const points = [
      pointOf('2026-10-09', '1.00'),
      pointOf('2026-10-10', '2.00', { series_epoch: 2 }),
    ];
    expect(days(segments(points))).toEqual([['2026-10-09'], ['2026-10-10']]);
  });

  it('does not split at month or year boundaries', () => {
    expect(days(segments([pointOf('2026-10-31', '1.00'), pointOf('2026-11-01', '1.00')]))).toEqual([
      ['2026-10-31', '2026-11-01'],
    ]);
    expect(days(segments([pointOf('2026-12-31', '1.00'), pointOf('2027-01-01', '1.00')]))).toEqual([
      ['2026-12-31', '2027-01-01'],
    ]);
  });

  it('does not split across a leap day', () => {
    const points = [
      pointOf('2028-02-28', '1.00'),
      pointOf('2028-02-29', '1.00'),
      pointOf('2028-03-01', '1.00'),
    ];
    expect(days(segments(points))).toEqual([['2028-02-28', '2028-02-29', '2028-03-01']]);
  });

  it('splits 2027-02-28 to 2027-03-01 nowhere and 2026-02-28 to 2026-03-02 at the gap', () => {
    expect(days(segments([pointOf('2027-02-28', '1.00'), pointOf('2027-03-01', '1.00')]))).toEqual([
      ['2027-02-28', '2027-03-01'],
    ]);
    expect(days(segments([pointOf('2026-02-28', '1.00'), pointOf('2026-03-02', '1.00')]))).toEqual([
      ['2026-02-28'],
      ['2026-03-02'],
    ]);
  });

  it('gives no segment for an empty list', () => {
    expect(segments([])).toEqual([]);
  });
});

describe('the History begins banner (D5)', () => {
  it('shows only when history_begins is after from', () => {
    expect(showsBanner('2026-10-09', '2026-09-13')).toBe(true);
    expect(showsBanner('2026-09-13', '2026-09-13')).toBe(false);
    expect(showsBanner('2026-09-01', '2026-09-13')).toBe(false);
    expect(showsBanner(null, '2026-09-13')).toBe(false);
    expect(showsBanner('2026-10-09', null)).toBe(false);
  });

  it('says exactly when history begins', () => {
    expect(bannerText('2026-10-09')).toBe(
      'History begins 2026-10-09. PokéPrices has no daily close for this variant before that day.',
    );
  });
});

describe('the hidden summary (D7)', () => {
  it('describes n >= 2 real closes with last, high and low', () => {
    const points = [
      pointOf('2026-10-09', '928.32'),
      pointOf('2026-10-10', '928.06'),
      pointOf('2026-10-11', '928.06'),
      pointOf('2026-10-12', '968.91'),
    ];
    expect(summaryText('holo, unlimited', '1M', points)).toBe(
      'Holo, unlimited, 1M: 4 daily closes from 2026-10-09 to 2026-10-12. Last close $968.91 on 2026-10-12. High $968.91 on 2026-10-12. Low $928.06 on 2026-10-10.',
    );
  });

  it('picks the earliest day on high and low ties', () => {
    const points = [
      pointOf('2026-10-09', '5.00'),
      pointOf('2026-10-10', '1.00'),
      pointOf('2026-10-11', '5.00'),
      pointOf('2026-10-12', '1.00'),
    ];
    expect(summaryText('holo', '1W', points)).toBe(
      'Holo, 1W: 4 daily closes from 2026-10-09 to 2026-10-12. Last close $1.00 on 2026-10-12. High $5.00 on 2026-10-09. Low $1.00 on 2026-10-10.',
    );
  });

  it('describes one close', () => {
    expect(summaryText('reverse', '3M', [pointOf('2026-10-12', '0.10')])).toBe(
      'Reverse, 3M: 1 daily close, $0.10 on 2026-10-12.',
    );
  });

  it('says no new daily close when every point is filled, and counts them', () => {
    expect(summaryText('holo', '1W', [filledOf('2026-10-12', '2.00', '2026-10-11')])).toBe(
      'Holo, 1W: no new daily close in this range. 1 carried-forward day repeats an earlier close.',
    );
    expect(summaryText('holo', '1W', [])).toBe('Holo, 1W: no new daily close in this range.');
  });

  it('excludes filled points from the statistics and counts them in the plural', () => {
    const points = [
      pointOf('2026-10-08', '3.00'),
      filledOf('2026-10-09', '3.00', '2026-10-08'),
      filledOf('2026-10-10', '3.00', '2026-10-08'),
      pointOf('2026-10-11', '2.00'),
    ];
    expect(summaryText('holo', '1M', points)).toBe(
      'Holo, 1M: 2 daily closes from 2026-10-08 to 2026-10-11. Last close $2.00 on 2026-10-11. High $3.00 on 2026-10-08. Low $2.00 on 2026-10-11. 2 carried-forward days repeat an earlier close.',
    );
  });

  it('a filled high never wins over a real close', () => {
    const points = [
      pointOf('2026-10-10', '1.00'),
      filledOf('2026-10-11', '9.00', '2026-10-01'),
      pointOf('2026-10-12', '2.00'),
    ];
    expect(summaryText('holo', '1M', points)).toContain('High $2.00 on 2026-10-12.');
  });

  it('keeps statistics to the newest epoch and names the mapping change', () => {
    const points = [
      pointOf('2026-10-07', '500.00'),
      pointOf('2026-10-08', '1.00'),
      pointOf('2026-10-10', '3.00', { series_epoch: 2 }),
      pointOf('2026-10-11', '4.00', { series_epoch: 2 }),
    ];
    expect(summaryText('holo', '1M', points)).toBe(
      'Holo, 1M: 2 daily closes from 2026-10-10 to 2026-10-11. Last close $4.00 on 2026-10-11. High $4.00 on 2026-10-11. Low $3.00 on 2026-10-10. The TCGplayer product mapping changed on 2026-10-10: earlier closes are a different series.',
    );
  });

  it('counts the closes of a gap-split newest epoch together, not only the last segment', () => {
    const points = [
      pointOf('2026-10-01', '1.00'),
      pointOf('2026-10-02', '2.00'),
      pointOf('2026-10-10', '3.00'),
    ];
    expect(summaryText('holo', '1M', points)).toContain('3 daily closes from 2026-10-01');
  });

  it('prints every amount as its point string grouped, never re-rounded', () => {
    const points = [pointOf('2026-10-11', '0.10'), pointOf('2026-10-12', '1000000.00')];
    expect(summaryText('holo', '1Y', points)).toBe(
      'Holo, 1Y: 2 daily closes from 2026-10-11 to 2026-10-12. Last close $1,000,000.00 on 2026-10-12. High $1,000,000.00 on 2026-10-12. Low $0.10 on 2026-10-11.',
    );
  });

  it('upper-cases only the first letter of the label', () => {
    expect(summaryText('1st edition, holo', 'YTD', [pointOf('2026-10-12', '1.00')])).toBe(
      '1st edition, holo, YTD: 1 daily close, $1.00 on 2026-10-12.',
    );
  });
});

describe('the data table rows (D7)', () => {
  it('lists every point ascending with its close and note', () => {
    const points = [
      pointOf('2026-10-08', '1234.50'),
      filledOf('2026-10-09', '1234.50', '2026-10-08'),
      pointOf('2026-10-10', '3.00', { series_epoch: 2 }),
      pointOf('2026-10-11', '0.07', { series_epoch: 2 }),
    ];
    expect(tableRows(points)).toEqual([
      { day: '2026-10-08', close: '$1,234.50', note: '' },
      { day: '2026-10-09', close: '$1,234.50', note: 'Carried forward from 2026-10-08' },
      {
        day: '2026-10-10',
        close: '$3.00',
        note: 'New product mapping from this day: not comparable with earlier closes',
      },
      { day: '2026-10-11', close: '$0.07', note: '' },
    ]);
  });

  it('sorts rows ascending by day even when given out of order', () => {
    const rows = tableRows([pointOf('2026-10-12', '1.00'), pointOf('2026-10-10', '2.00')]);
    expect(rows.map((row) => row.day)).toEqual(['2026-10-10', '2026-10-12']);
  });
});

describe('eligible series and the range window (D7)', () => {
  it('offers only series with history_begins, in API order', () => {
    const a = seriesOf('x:holo:-:-', [], null);
    const b = seriesOf('x:normal:-:-', [pointOf('2026-10-10', '1.00')]);
    const c = seriesOf('x:reverse:-:-', [], '2026-10-01');
    expect(eligibleSeries([a, b, c]).map((series) => series.variant_key)).toEqual([
      'x:normal:-:-',
      'x:reverse:-:-',
    ]);
  });

  it('keeps the points inside [from, to]', () => {
    const points = [
      pointOf('2026-09-12', '1.00'),
      pointOf('2026-09-13', '2.00'),
      pointOf('2026-10-12', '3.00'),
      pointOf('2026-10-13', '4.00'),
    ];
    expect(pointsInRange(points, '2026-09-13', '2026-10-12').map((point) => point.day)).toEqual([
      '2026-09-13',
      '2026-10-12',
    ]);
  });
});
