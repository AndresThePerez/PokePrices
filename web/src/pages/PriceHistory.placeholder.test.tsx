// The chart chunk's Suspense fallback holds the slot the chart and its attribution take (D2-i3
// T02, CLS): one plain element with no text, no role and no aria-hidden, gone once the chunk
// lands. React.lazy caches the loaded component for the whole module graph, so this file holds a
// single test (see PriceHistory.lazy.test.tsx).
import { cleanup, render, screen, waitFor, within } from '@testing-library/react';
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

const gate = vi.hoisted(() => {
  let open: () => void = () => undefined;
  const opened = new Promise<void>((resolve) => {
    open = resolve;
  });
  return {
    opened,
    open: () => {
      open();
    },
  };
});

// The chart library (and so the lazy PriceChart module that imports it) loads only when the
// test opens the gate.
vi.mock('lightweight-charts', async () => {
  await gate.opened;
  return import('../test/lightweightChartsFake');
});

const ATTRIBUTION = 'Chart by TradingView Lightweight Charts';

afterEach(() => {
  cleanup();
  window.history.replaceState(null, '', '/');
});

describe('the chart placeholder (D2-i3 T02)', () => {
  it('holds the chart slot with one plain empty div while the chunk loads, then gives way to the chart and its attribution', async () => {
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
    await within(section).findByText('as of 2026-10-12 from TCGCSV (daily close)');
    const summary = section.querySelector('p.sr-only');
    const wrapper = summary?.parentElement ?? null;
    expect(wrapper).not.toBeNull();
    const others = Array.from(wrapper?.children ?? []).filter((child) => child !== summary);
    expect(others).toHaveLength(1);
    const placeholder = others[0];
    expect(placeholder?.tagName).toBe('DIV');
    expect(placeholder?.childElementCount).toBe(0);
    expect(placeholder?.textContent).toBe('');
    expect(placeholder?.hasAttribute('role')).toBe(false);
    expect(placeholder?.hasAttribute('aria-hidden')).toBe(false);

    gate.open();
    await within(section).findByRole('link', { name: ATTRIBUTION });
    expect(
      within(section).getByRole('img', { name: 'Daily close chart of holo, unlimited, 1M' }),
    ).toBeTruthy();
    await waitFor(() => {
      expect(placeholder?.isConnected).toBe(false);
    });
  });
});
