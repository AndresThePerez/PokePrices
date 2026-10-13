import { cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import App from '../App';
import {
  filledOf,
  historyBody,
  isHistoryUrl,
  jsonResponse,
  pointOf,
  pricedCardBody,
  seriesOf,
  stubFetch,
} from '../test/fixtures';
import { resetRecorder } from '../test/lightweightChartsFake';
import Sparkline from './Sparkline';

vi.mock('lightweight-charts', () => import('../test/lightweightChartsFake'));

const HOLO = 'ex10-?:holo:unlimited:-';
const ATTRIBUTION = 'Chart by TradingView Lightweight Charts';

function drawn(points: Parameters<typeof Sparkline>[0]['points']): Element[] {
  const { container } = render(<Sparkline points={points} />);
  return Array.from(container.querySelectorAll('svg > *'));
}

beforeEach(() => {
  resetRecorder();
});

afterEach(() => {
  cleanup();
  window.history.replaceState(null, '', '/');
});

describe('Sparkline edge geometry (D12)', () => {
  it('does not dash a filled first point: no piece arrives at it, the run stays one solid line', () => {
    const shapes = drawn([
      filledOf('2026-10-10', '1.00', '2026-10-09'),
      pointOf('2026-10-11', '3.00'),
      pointOf('2026-10-12', '2.00'),
    ]);
    expect(shapes.map((shape) => shape.tagName)).toEqual(['polyline']);
    expect(shapes[0]?.getAttribute('points')).toBe('2.0,26.0 48.0,2.0 94.0,14.0');
    expect(shapes[0]?.getAttribute('stroke-dasharray')).toBeNull();
  });

  it('places 31 points from x 2.0 to x 94.0 with every y inside the padded box', () => {
    const points = Array.from({ length: 31 }, (_, index) =>
      pointOf(
        `2026-09-${String(index + 1).padStart(2, '0')}`.replace('09-31', '10-01'),
        `${String(10 + index)}.00`,
      ),
    );
    const shapes = drawn(points);
    const pairs = (shapes[0]?.getAttribute('points') ?? '')
      .split(' ')
      .map((pair) => pair.split(','));
    expect(pairs).toHaveLength(31);
    expect(pairs[0]).toEqual(['2.0', '26.0']);
    expect(pairs[30]).toEqual(['94.0', '2.0']);
    for (const [x, y] of pairs) {
      expect(Number(x)).toBeGreaterThanOrEqual(2);
      expect(Number(x)).toBeLessThanOrEqual(94);
      expect(Number(y)).toBeGreaterThanOrEqual(2);
      expect(Number(y)).toBeLessThanOrEqual(26);
    }
  });

  it('keeps one scale across a mapping change and a gap, with the dashed piece inside its own segment', () => {
    const shapes = drawn([
      pointOf('2026-10-01', '1.00'),
      pointOf('2026-10-02', '2.00'),
      pointOf('2026-10-05', '100.00', { series_epoch: 2 }),
      filledOf('2026-10-06', '100.00', '2026-10-05', 2),
    ]);
    expect(
      shapes.map((shape) => [shape.getAttribute('points'), shape.getAttribute('stroke-dasharray')]),
    ).toEqual([
      ['2.0,26.0 32.7,25.8', null],
      ['63.3,2.0 94.0,2.0', '2 2'],
    ]);
  });
});

describe('request sharing between the chart and the sparklines (D9, D12)', () => {
  const holoPoints = [
    pointOf('2026-10-09', '928.32'),
    pointOf('2026-10-10', '928.06'),
    pointOf('2026-10-12', '968.91'),
  ];

  it('asks for 1M once, 3M once, and answers a return to 1M from the cache', async () => {
    vi.useFakeTimers({ toFake: ['Date'] });
    vi.setSystemTime(new Date('2031-02-03T04:05:06Z'));
    const urls: string[] = [];
    stubFetch((url) => {
      urls.push(url);
      return isHistoryUrl(url)
        ? jsonResponse(historyBody([seriesOf(HOLO, holoPoints)]))
        : jsonResponse(pricedCardBody);
    });
    window.history.replaceState(null, '', '/card/ex10-%3F');
    render(<App />);
    const section = await screen.findByRole('region', { name: 'Price history' });
    await within(section).findByRole('link', { name: ATTRIBUTION });
    const prices = screen.getByRole('region', { name: 'Prices' });
    const before = prices.innerHTML;
    expect(prices.querySelectorAll('svg')).toHaveLength(1);
    fireEvent.click(within(section).getByRole('button', { name: '3M' }));
    await within(section).findByRole('link', { name: ATTRIBUTION });
    fireEvent.click(within(section).getByRole('button', { name: '1M' }));
    await within(section).findByRole('link', { name: ATTRIBUTION });
    expect(urls).toEqual([
      '/v1/cards/ex10-%3F',
      '/v1/cards/ex10-%3F/prices?range=1M',
      '/v1/cards/ex10-%3F/prices?range=3M',
    ]);
    expect(screen.getByRole('region', { name: 'Prices' }).innerHTML).toBe(before);
    vi.useRealTimers();
  });
});
