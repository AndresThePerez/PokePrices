// The portfolio estimate outside the portfolio page: in the header, for a signed-in session, a
// short link to /portfolio whose accessible name is the full sentence (an estimate at daily-close
// market prices, not a sale value; the change vs previous close; the cards; source and "as of").
// Absent signed out, while the session loads, when its request fails, for a portfolio with no
// priced card, and on /portfolio itself. It sends no request of its own.
import { cleanup, render, screen, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import App from '../App';
import {
  FORBIDDEN_WORDS,
  clearXsrfCookie,
  demoMe,
  estimateOf,
  historyFor,
  holdSessionCookie,
  jsonResponse,
  portfolioBody,
  pricedCardBody,
  problemResponse,
  serverErrorProblem,
  setXsrfCookie,
  signedInMe,
  stubAccountFetch,
  unauthenticatedProblem,
  watchlistBody,
  type Estimate,
} from '../test/fixtures';

vi.mock('lightweight-charts', () => import('../test/lightweightChartsFake'));

const NAME_START = 'Portfolio estimate $1,047.93 ▲ +1.64%';

afterEach(() => {
  cleanup();
  clearXsrfCookie();
  vi.unstubAllGlobals();
  window.localStorage.clear();
  window.history.replaceState(null, '', '/');
});

async function settle(): Promise<void> {
  for (let i = 0; i < 6; i += 1) {
    await new Promise((resolve) => setTimeout(resolve, 0));
  }
}

function meWith(estimate: Estimate, base: typeof signedInMe | typeof demoMe = signedInMe) {
  return { data: { ...base.data, portfolio: estimate } };
}

function banner(): HTMLElement {
  return screen.getByRole('banner');
}

function estimateLink(): HTMLElement | null {
  return within(banner()).queryByRole('link', { name: /^Portfolio estimate/ });
}

async function openWatchlist(me: Response): Promise<ReturnType<typeof stubAccountFetch>> {
  const calls = stubAccountFetch({
    'GET /v1/me': me,
    'GET /v1/watchlist': jsonResponse(watchlistBody()),
  });
  window.history.replaceState(null, '', '/watchlist');
  render(<App />);
  await settle();
  return calls;
}

describe('the header estimate, signed in', () => {
  it('links to /portfolio with the full sentence, source and "as of" in its name, and sends nothing of its own', async () => {
    const calls = await openWatchlist(jsonResponse(meWith(estimateOf())));
    const link = await within(banner()).findByRole('link', { name: /^Portfolio estimate/ });
    expect(link.getAttribute('href')).toBe('/portfolio');
    expect(link.textContent).toContain('Portfolio estimate $1,047.93');
    expect(link.textContent).toContain('▲ +1.64%');
    let name = '';
    within(banner()).getByRole('link', {
      name: (computed) => {
        if (computed.startsWith('Portfolio estimate')) name = computed;
        return computed.startsWith('Portfolio estimate');
      },
    });
    expect(name.startsWith(`${NAME_START}: an estimate at daily-close market prices`)).toBe(true);
    expect(name).toContain('from TCGCSV (daily close)');
    for (const part of [
      'an estimate at daily-close market prices, not a sale value.',
      '▲ +$16.93 (+1.64%) vs previous close.',
      '3 cards, 1 without a price.',
      'TCGplayer market prices, Closes from 2026-10-11 to 2026-10-12 from TCGCSV (daily close).',
    ]) {
      expect(link.textContent).toContain(part);
    }
    expect(calls.map((call) => call.key)).toEqual([
      'GET /v1/prices/freshness',
      'GET /v1/me',
      'GET /v1/watchlist',
    ]);
    for (const word of FORBIDDEN_WORDS) expect(banner().textContent).not.toMatch(word);
  });

  it('shows on a card page opened directly once the cookie lets the page ask the session', async () => {
    setXsrfCookie();
    stubAccountFetch({
      'GET /v1/cards/ex10-%3F': jsonResponse(pricedCardBody),
      'GET /v1/cards/ex10-%3F/prices?range=1M': jsonResponse(historyFor(pricedCardBody)),
      'GET /v1/me': jsonResponse(meWith(estimateOf())),
      'GET /v1/watchlist': jsonResponse(watchlistBody([])),
      'GET /v1/portfolio': jsonResponse(portfolioBody()),
    });
    window.history.replaceState(null, '', '/card/ex10-%3F');
    render(<App />);
    expect(
      await within(banner()).findByRole('link', { name: /^Portfolio estimate \$1,047\.93/ }),
    ).toBeTruthy();
  });

  it('shows for the read-only demo too', async () => {
    await openWatchlist(jsonResponse(meWith(estimateOf(), demoMe)));
    expect(await within(banner()).findByRole('link', { name: /^Portfolio estimate/ })).toBeTruthy();
  });

  it('says "No change" and no percent when the change is zero, and leaves the chip out without a change', async () => {
    await openWatchlist(jsonResponse(meWith(estimateOf({ change: '0.00', change_pct: '0.00' }))));
    let link = await within(banner()).findByRole('link', { name: /^Portfolio estimate/ });
    expect(link.textContent).toContain('No change');
    expect(link.textContent).toContain('No change vs previous close.');
    cleanup();
    await openWatchlist(
      jsonResponse(
        meWith(estimateOf({ change: null, change_pct: null, no_previous_close: 2, unpriced: 1 })),
      ),
    );
    link = await within(banner()).findByRole('link', { name: /^Portfolio estimate/ });
    expect(link.textContent).not.toContain('▲');
    expect(link.textContent).toContain('No previous close yet, so no change to show.');
    expect(link.textContent).toContain('The change leaves out 2 cards with no previous close.');
    cleanup();
    await openWatchlist(jsonResponse(meWith(estimateOf({ change: '4.00', change_pct: null }))));
    link = await within(banner()).findByRole('link', { name: /^Portfolio estimate/ });
    expect(link.textContent).toContain('▲ +$4.00');
    expect(link.textContent).toContain(
      '▲ +$4.00 vs previous close; no percent, as the previous closes add up to $0.00.',
    );
  });
});

describe('no header estimate', () => {
  it('signed out', async () => {
    await openWatchlist(problemResponse(unauthenticatedProblem));
    await screen.findByText('Sign in to keep a watchlist.');
    expect(estimateLink()).toBeNull();
  });

  it('when the session request fails', async () => {
    holdSessionCookie();
    await openWatchlist(problemResponse(serverErrorProblem('/v1/me')));
    await screen.findByText(/Could not load your account/);
    expect(estimateLink()).toBeNull();
  });

  it('while the session is loading', async () => {
    holdSessionCookie();
    vi.stubGlobal('fetch', () => new Promise<Response>(() => undefined));
    window.history.replaceState(null, '', '/watchlist');
    render(<App />);
    await settle();
    expect(screen.getByText('Loading your watchlist…')).toBeTruthy();
    expect(estimateLink()).toBeNull();
  });

  it('for a portfolio with no priced card, or none at all', async () => {
    await openWatchlist(jsonResponse(signedInMe));
    await screen.findByRole('list', { name: 'Watchlist' });
    expect(estimateLink()).toBeNull();
    cleanup();
    await openWatchlist(
      jsonResponse(meWith(estimateOf({ total_value: null, change: null, change_pct: null }))),
    );
    await screen.findByRole('list', { name: 'Watchlist' });
    expect(estimateLink()).toBeNull();
  });

  it('on /portfolio, which states the total itself', async () => {
    stubAccountFetch({
      'GET /v1/me': jsonResponse(meWith(estimateOf())),
      'GET /v1/portfolio': jsonResponse(portfolioBody()),
    });
    window.history.replaceState(null, '', '/portfolio');
    render(<App />);
    await screen.findByRole('list', { name: 'Portfolio' });
    expect(estimateLink()).toBeNull();
  });
});
