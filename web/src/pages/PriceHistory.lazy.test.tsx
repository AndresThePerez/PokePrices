// The chart is a lazy chunk (T06 criterion 3). This file holds a single test: React.lazy caches
// the loaded component for the whole module graph, so the "still loading" state is only
// observable on the first render of a file.
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
import { recorder } from '../test/lightweightChartsFake';

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

describe('the lazy chart (T06)', () => {
  it('shows the summary while the chart chunk loads, adds no text, then draws the chart', async () => {
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
    expect(summary?.textContent).toBe(
      'Holo, unlimited, 1M: 2 daily closes from 2026-10-11 to 2026-10-12. Last close $70.81 on 2026-10-12. High $70.81 on 2026-10-12. Low $69.36 on 2026-10-11.',
    );
    expect(within(section).queryByRole('img')).toBeNull();
    expect(within(section).queryByRole('link')).toBeNull();
    expect(recorder.charts).toHaveLength(0);
    const whileLoading = section.textContent;
    expect(whileLoading).not.toContain('Chart');
    expect(whileLoading).not.toContain('Loading');

    gate.open();
    await within(section).findByRole('link', { name: ATTRIBUTION });
    expect(
      within(section).getByRole('img', { name: 'Daily close chart of holo, unlimited, 1M' }),
    ).toBeTruthy();
    expect(recorder.charts).toHaveLength(1);
    expect(section.textContent.replace(ATTRIBUTION, '')).toBe(whileLoading);
  });
});
