// Q1-i2-T01 review: a 401 from a write where nothing observes ['me'] (the demo strip on a public
// page, the card page's buttons) must neither fetch GET /v1/me nor leave the session half-ended.
import { act, cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import App from '../App';
import { navigate } from '../router';
import {
  clearXsrfCookie,
  demoMe,
  historyFor,
  jsonResponse,
  moversBody,
  pricedCardBody,
  problemResponse,
  risingMover,
  stubAccountFetch,
  unauthenticatedProblem,
} from '../test/fixtures';

vi.mock('lightweight-charts', () => import('../test/lightweightChartsFake'));

const DEMO_NOTICE =
  'You are signed in to the read-only demo account. Its watchlist and portfolio cannot be changed.';

function visit(path: string): void {
  window.history.replaceState(null, '', path);
}

beforeEach(() => {
  window.localStorage.clear();
});

afterEach(() => {
  cleanup();
  clearXsrfCookie();
  visit('/');
});

async function settle(): Promise<void> {
  for (let round = 0; round < 5; round += 1) {
    await act(async () => {
      await new Promise((resolve) => setTimeout(resolve, 0));
    });
  }
}

describe('a 401 write with no ["me"] observer (review)', () => {
  it('signing out from the demo strip on /movers with a 401 leaves the strip, goes to /, asks nothing', async () => {
    const calls = stubAccountFetch({
      'GET /v1/me': jsonResponse(demoMe),
      'GET /v1/movers?direction=up&limit=50': jsonResponse(moversBody('up', [risingMover])),
      'POST /logout': problemResponse({ ...unauthenticatedProblem, instance: '/logout' }),
      'GET /v1/movers?direction=up&limit=5': jsonResponse(moversBody('up', [risingMover])),
      'GET /v1/movers?direction=down&limit=5': jsonResponse(moversBody('down', [])),
    });
    visit('/account');
    render(<App />);
    await screen.findByText('Signed in as demo@example.com');
    act(() => {
      navigate('/movers');
    });
    await settle();
    expect(screen.getByText(DEMO_NOTICE)).toBeTruthy();
    fireEvent.click(within(screen.getByRole('banner')).getByRole('button', { name: 'Sign out' }));
    await waitFor(() => {
      expect(window.location.pathname).toBe('/');
    });
    await settle();
    expect(screen.queryByText(DEMO_NOTICE)).toBeNull();
    expect(screen.queryByText(/Could not sign out/)).toBeNull();
    expect(
      within(within(screen.getByRole('banner')).getByRole('navigation', { name: 'Main' }))
        .getAllByRole('link')
        .at(-1)?.textContent,
    ).toBe('Sign in');
    expect(calls.filter((call) => call.key === 'GET /v1/me')).toHaveLength(1);
    expect(calls.filter((call) => call.key === 'POST /logout')).toHaveLength(1);
  });

  it('a 401 on the card page watchlist button asks the visitor to sign in without fetching GET /v1/me', async () => {
    const put = 'PUT /v1/watchlist/ex10-%3F%3Aholo%3Aunlimited%3A-';
    const calls = stubAccountFetch({
      'GET /v1/cards/ex10-%3F': jsonResponse(pricedCardBody),
      'GET /v1/cards/ex10-%3F/prices?range=1M': jsonResponse(historyFor(pricedCardBody)),
      [put]: problemResponse(unauthenticatedProblem),
    });
    visit('/card/ex10-%3F');
    render(<App />);
    await screen.findByRole('heading', { level: 1, name: 'Unown' });
    fireEvent.click(screen.getByRole('button', { name: 'Add holo, unlimited to your watchlist' }));
    expect(await screen.findByText('Sign in to keep a watchlist and a portfolio.')).toBeTruthy();
    await settle();
    expect(calls.map((call) => call.key)).toEqual([
      'GET /v1/cards/ex10-%3F',
      'GET /v1/cards/ex10-%3F/prices?range=1M',
      'GET /sanctum/csrf-cookie',
      put,
    ]);
  });
});
