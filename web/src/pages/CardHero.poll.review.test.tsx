import { act, cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import App from '../App';
import {
  cardWithPrices,
  fallingPrice,
  historyBody,
  isHistoryUrl,
  jsonResponse,
  pointOf,
  risingPrice,
  seriesOf,
  stubFetch,
} from '../test/fixtures';

// jsdom has no canvas: the chart library is replaced by its recording fake (T06 helper).
vi.mock('lightweight-charts', () => import('../test/lightweightChartsFake'));

const KEY = (index: number): string => `ex10-?:finish${String(index)}:unlimited:-`;

async function settle(): Promise<void> {
  for (let round = 0; round < 3; round += 1) {
    await act(async () => {
      await new Promise((resolve) => {
        setTimeout(resolve, 0);
      });
    });
  }
}

beforeEach(() => {
  vi.useFakeTimers({ toFake: ['setInterval', 'clearInterval', 'Date'] });
  vi.setSystemTime(new Date('2031-02-03T04:05:06Z'));
});

afterEach(() => {
  cleanup();
  vi.useRealTimers();
  window.history.replaceState(null, '', '/');
});

describe('Card hero across the five-minute poll (D2-i1-T01, A1-4)', () => {
  it('falls back to a priced variant, with no crash and no stale figure, when the chosen variant loses its price on a poll', async () => {
    const first = cardWithPrices(risingPrice, { ...fallingPrice, close: '68.06' });
    const second = cardWithPrices(risingPrice, null);
    const cards = [first, second];
    let asked = 0;
    stubFetch((url) => {
      if (isHistoryUrl(url)) {
        return jsonResponse(
          historyBody([
            seriesOf(KEY(0), [pointOf('2026-10-11', '69.36'), pointOf('2026-10-12', '70.81')]),
            seriesOf(KEY(1), [pointOf('2026-10-11', '69.00'), pointOf('2026-10-12', '68.06')]),
          ]),
        );
      }
      const card = cards[Math.min(asked, cards.length - 1)];
      asked += 1;
      return jsonResponse(card);
    });
    window.history.replaceState(null, '', '/card/ex10-%3F');
    render(<App />);
    await settle();
    const variants = screen.getByRole('group', { name: 'Variant' });
    fireEvent.click(within(variants).getByRole('button', { name: 'finish1, unlimited' }));
    expect(screen.getByText('Market price · finish1, unlimited')).toBeTruthy();
    expect(screen.getByText('$68.06')).toBeTruthy();

    await act(async () => {
      await vi.advanceTimersByTimeAsync(300_000);
    });
    await settle();

    expect(asked).toBe(2);
    const label = screen.getByText(/^Market price · /);
    expect(label.textContent).toBe('Market price · finish0, unlimited');
    expect(within(label.parentElement as HTMLElement).getByText('$70.81')).toBeTruthy();
    expect(screen.queryByText('$68.06')).toBeNull();
    expect(screen.queryByText('Market price · finish1, unlimited')).toBeNull();
    // One priced variant is left: no Variant group, and the chart follows the hero.
    expect(screen.queryByRole('group', { name: 'Variant' })).toBeNull();
    const history = screen.getByRole('region', { name: 'Price history' });
    expect(history.querySelector('p.sr-only')?.textContent).toMatch(/^Finish0, unlimited, 1M: /);
  });
});
