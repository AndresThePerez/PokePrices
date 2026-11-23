// The portfolio total, labelled an estimate at daily-close market prices, not a sale value, with
// the day's change in dollars and percent against the previous close and the card counts, all
// under the total's one source-and-"as of" line; the "no change", "no percent" and "no previous
// close" states; and "Saved." after a quantity save, announced as the alerts page announces it.
import { cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import App from '../App';
import {
  FORBIDDEN_WORDS,
  clearXsrfCookie,
  holdingOf,
  jsonResponse,
  portfolioBody,
  signedInMe,
  stubAccountFetch,
} from '../test/fixtures';
import { visibleStamps } from '../test/rows';

afterEach(() => {
  cleanup();
  clearXsrfCookie();
  window.localStorage.clear();
  window.history.replaceState(null, '', '/');
});

type Meta = Parameters<typeof portfolioBody>[1];

async function openPortfolio(meta: Meta = {}): Promise<HTMLElement> {
  stubAccountFetch({
    'GET /v1/me': jsonResponse(signedInMe),
    'GET /v1/portfolio': jsonResponse(portfolioBody(undefined, meta)),
  });
  window.history.replaceState(null, '', '/portfolio');
  render(<App />);
  const total = await screen.findByText(/^Total value \$/);
  return total.parentElement as HTMLElement;
}

/** The panel's paragraphs, in order. */
function lines(panel: HTMLElement): string[] {
  return Array.from(panel.querySelectorAll('p')).map((p) => p.textContent);
}

describe("the total is an estimate with the day's change and the counts", () => {
  it('reads, in order: the label, the total, its days, the unpriced line, the change, the counts, the base', async () => {
    const panel = await openPortfolio();
    expect(lines(panel)).toEqual([
      'An estimate at daily-close market prices, not a sale value.',
      'Total value $1,047.93',
      'Closes from 2026-10-11 to 2026-10-12 from TCGCSV (daily close)',
      '1 holding has no price in the collected snapshots and is not counted.',
      '▲ +$16.93 (+1.64%) vs previous close.',
      '3 cards, 1 without a price.',
      "Change is each card's close vs its previous close, not a gain on your holding.",
    ]);
    // One source-and-"as of" line covers the total and the change.
    expect(visibleStamps(panel)).toHaveLength(1);
    const change = within(panel).getByText('▲ +$16.93 (+1.64%) vs previous close.');
    expect(change.className).toContain('text-up');
    for (const word of FORBIDDEN_WORDS) expect(panel.textContent).not.toMatch(word);
  });

  it('a fall: the arrow down, the minus sign and the down tone', async () => {
    const panel = await openPortfolio({ change: '-1.98', change_pct: '-2.44' });
    const change = within(panel).getByText('▼ -$1.98 (-2.44%) vs previous close.');
    expect(change.className).toContain('text-down');
  });

  it('no change: says so, in the neutral tone', async () => {
    const panel = await openPortfolio({ change: '0.00', change_pct: '0.00' });
    expect(within(panel).getByText('No change vs previous close.').className).toContain(
      'text-flat',
    );
  });

  it('no percent: the amount and why there is no percent', async () => {
    const panel = await openPortfolio({ change: '4.00', change_pct: null });
    expect(
      within(panel).getByText(
        '▲ +$4.00 vs previous close; no percent, as the previous closes add up to $0.00.',
      ),
    ).toBeTruthy();
  });

  it('no previous close at all: no change to show, and how many cards the change leaves out', async () => {
    const panel = await openPortfolio({
      change: null,
      change_pct: null,
      no_previous_close: 2,
      unpriced: 1,
    });
    expect(lines(panel)).toEqual([
      'An estimate at daily-close market prices, not a sale value.',
      'Total value $1,047.93',
      'Closes from 2026-10-11 to 2026-10-12 from TCGCSV (daily close)',
      '1 holding has no price in the collected snapshots and is not counted.',
      'No previous close yet, so no change to show.',
      '3 cards, 1 without a price.',
      'The change leaves out 2 cards with no previous close.',
      "Change is each card's close vs its previous close, not a gain on your holding.",
    ]);
  });

  it('every card priced, one left out of the change: "1 card with no previous close"', async () => {
    const panel = await openPortfolio({ unpriced: 0, priced: 3, no_previous_close: 1, count: 12 });
    expect(within(panel).getByText('12 cards.')).toBeTruthy();
    expect(
      within(panel).getByText('The change leaves out 1 card with no previous close.'),
    ).toBeTruthy();
  });
});

describe('Saved. after a quantity save', () => {
  it('shows the alerts page\'s "Saved." as a status once the save and the read again are done', async () => {
    const one = portfolioBody([holdingOf({ quantity: 2, value: '1937.82' })], {
      total_value: '1937.82',
      priced: 1,
      unpriced: 0,
      count: 1,
    });
    const after = portfolioBody([holdingOf({ quantity: 5, value: '4844.55' })], {
      total_value: '4844.55',
      priced: 1,
      unpriced: 0,
      count: 1,
    });
    stubAccountFetch({
      'GET /v1/me': jsonResponse(signedInMe),
      'GET /v1/portfolio': [jsonResponse(one), jsonResponse(after)],
      'PUT /v1/portfolio/base1-4%3Aholo%3Aunlimited%3A-': jsonResponse({
        data: { variant_key: 'base1-4:holo:unlimited:-', quantity: 5 },
      }),
    });
    window.history.replaceState(null, '', '/portfolio');
    render(<App />);
    const field = await screen.findByLabelText<HTMLInputElement>('Quantity owned');
    expect(screen.queryByText('Saved.')).toBeNull();
    fireEvent.change(field, { target: { value: '5' } });
    fireEvent.click(screen.getByRole('button', { name: 'Save' }));
    const saved = await screen.findByText('Saved.');
    expect(saved.closest('[role="status"]')).not.toBeNull();
    expect(screen.getByText('Total value $4,844.55')).toBeTruthy();
  });
});
