// D2-i3 T02 review: if the chart chunk fails to load, the Suspense placeholder must give way to the
// error boundary's line instead of staying as a blank slot. One test per file: React.lazy caches
// its result for the whole module graph (see PriceHistory.lazy.test.tsx).
import { cleanup, render, screen, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import App from '../App';
import {
  historyBody,
  isHistoryUrl,
  jsonResponse,
  pointOf,
  pricedCardBody,
  seriesOf,
  stubFetch,
} from '../test/fixtures';

vi.mock('lightweight-charts', () => {
  throw new Error('fake chunk failure');
});

const CHART_FAILED = 'The chart could not be drawn. Show the data table to read every close.';

afterEach(() => {
  cleanup();
  window.history.replaceState(null, '', '/');
});

describe('the chart placeholder when the chunk fails (D2-i3 T02)', () => {
  it('replaces the empty placeholder with the failure line and leaves no empty div in the chart slot', async () => {
    vi.spyOn(console, 'error').mockImplementation(() => undefined);
    stubFetch((url) =>
      isHistoryUrl(url)
        ? jsonResponse(
            historyBody([
              seriesOf('ex10-?:holo:unlimited:-', [
                pointOf('2026-10-11', '69.36'),
                pointOf('2026-10-12', '70.81'),
              ]),
            ]),
          )
        : jsonResponse(pricedCardBody),
    );
    window.history.replaceState(null, '', '/card/ex10-%3F');
    render(<App />);
    const section = await screen.findByRole('region', { name: 'Price history' });
    await within(section).findByText(CHART_FAILED);
    const wrapper = section.querySelector('p.sr-only')?.parentElement ?? null;
    expect(wrapper).not.toBeNull();
    const empties = Array.from(wrapper?.children ?? []).filter(
      (child) => child.tagName === 'DIV' && child.childElementCount === 0,
    );
    expect(empties).toHaveLength(0);
    expect(within(section).queryByRole('img')).toBeNull();
    expect(within(section).queryByRole('link', { name: /tradingview/i })).toBeNull();
    // The data table is still reachable: the failure leaves the rest of the panel working.
    expect(within(section).getByRole('button', { name: 'Show data table' })).toBeTruthy();
  });
});
