// D2-i1-T05 review (round 1a): the strip is an honesty surface (it claims a session state), the
// list rows must survive hostile names, and the alert select must follow the server without
// discarding a choice the visitor has not saved.
import { act, cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import App from '../App';
import { navigate } from '../router';
import {
  alertBody,
  pricedCardBody,
  clearXsrfCookie,
  demoMe,
  emptyPortfolioBody,
  freshnessBody,
  historyFor,
  holdingOf,
  jsonResponse,
  moversBody,
  portfolioBody,
  problemResponse,
  rawResponse,
  risingMover,
  fallingMover,
  searchPage,
  serverErrorProblem,
  signedInMe,
  stubAccountFetch,
  unauthenticatedProblem,
  watchItemOf,
  watchlistBody,
  watchlistItems,
  workedHoldings,
} from '../test/fixtures';
import { SESSION_SIGNAL_KEY } from './session';

vi.mock('lightweight-charts', () => import('../test/lightweightChartsFake'));

const DEMO_NOTICE =
  'You are signed in to the read-only demo account. Its watchlist and portfolio cannot be changed.';
const DEMO_BUTTON = 'Try the read-only demo';
const LIVE = '[role=status], [role=alert], [role=log], [aria-live], output';

function visit(path: string): void {
  window.history.replaceState(null, '', path);
}

beforeEach(() => {
  window.localStorage.clear();
});

afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
  clearXsrfCookie();
  visit('/');
});

const banner = (): HTMLElement => screen.getByRole('banner');
const main = (): HTMLElement => screen.getByRole('main');

async function settle(): Promise<void> {
  for (let round = 0; round < 5; round += 1) {
    await act(async () => {
      await new Promise((resolve) => setTimeout(resolve, 0));
    });
  }
}

/** A fetch that never answers: the session read stays in flight. */
function hangingFetch(): void {
  vi.stubGlobal('fetch', () => new Promise<Response>(() => undefined));
}

function go(to: string): void {
  act(() => {
    navigate(to);
  });
}

describe('the strip claims a session state only when the cache really knows it', () => {
  it.each([
    ['still loading', null],
    ['signed out (401)', problemResponse(unauthenticatedProblem)],
    ['failed (500)', problemResponse(serverErrorProblem('/v1/me'))],
  ] as const)('is absent while the session is %s', async (_name, answer) => {
    if (answer === null) hangingFetch();
    else stubAccountFetch({ 'GET /v1/me': answer });
    visit('/watchlist');
    render(<App />);
    await settle();
    expect(screen.queryByText(DEMO_NOTICE)).toBeNull();
    expect(within(banner()).queryByRole('button', { name: 'Sign out' })).toBeNull();
    expect(banner().querySelectorAll(LIVE)).toHaveLength(0);
  });

  it('leaves the strip at another tab signal even when the refetch brings an ordinary account', async () => {
    stubAccountFetch({
      'GET /v1/me': [jsonResponse(demoMe), jsonResponse(signedInMe)],
      'GET /v1/watchlist': jsonResponse(watchlistBody()),
    });
    visit('/watchlist');
    render(<App />);
    await screen.findByRole('list', { name: 'Watchlist' });
    expect(screen.getAllByText(DEMO_NOTICE)).toHaveLength(1);
    act(() => {
      window.dispatchEvent(
        new StorageEvent('storage', { key: SESSION_SIGNAL_KEY, newValue: '2.2' }),
      );
    });
    await settle();
    expect(screen.queryByText(DEMO_NOTICE)).toBeNull();
    expect(within(banner()).queryByRole('button', { name: 'Sign out' })).toBeNull();
    // The ordinary account now sees its own controls, so the page followed the new session.
    expect(within(main()).getAllByRole('button', { name: /^Save the alert for/ }).length).toBe(3);
  });

  it.each([
    ['/sign-in', [problemResponse(unauthenticatedProblem), jsonResponse(demoMe)]],
    ['/', [jsonResponse(demoMe)]],
  ] as const)(
    'a demo sign-in from %s shows the strip and keeps it over /movers, /search and /card with each route its own requests',
    async (start, meAnswers) => {
      const calls = stubAccountFetch({
        'GET /v1/me': [...meAnswers],
        'POST /v1/demo/session': rawResponse(204, null),
        'GET /v1/watchlist': jsonResponse(watchlistBody()),
        'GET /v1/movers?direction=up&limit=50': jsonResponse(moversBody('up', [risingMover])),
        'GET /v1/movers?direction=down&limit=50': jsonResponse(moversBody('down', [fallingMover])),
        'GET /v1/cards?q=unown': jsonResponse(searchPage),
        'GET /v1/cards/ex10-%3F': jsonResponse(pricedCardBody),
        'GET /v1/cards/ex10-%3F/prices?range=1M': jsonResponse(historyFor(pricedCardBody)),
        'GET /v1/prices/freshness': jsonResponse(
          freshnessBody({ within_slo: true, age_hours: 3.5 }),
        ),
      });
      visit(start);
      render(<App />);
      fireEvent.click(await screen.findByRole('button', { name: DEMO_BUTTON }));
      await waitFor(() => {
        expect(window.location.pathname).toBe('/watchlist');
      });
      await screen.findByRole('list', { name: 'Watchlist' });
      expect(within(banner()).getAllByText(DEMO_NOTICE)).toHaveLength(1);
      expect(screen.getAllByText(DEMO_NOTICE)).toHaveLength(1);

      const hops: readonly (readonly [string, readonly string[]])[] = [
        ['/movers', ['GET /v1/movers?direction=up&limit=50']],
        ['/search?q=unown', ['GET /v1/cards?q=unown']],
        ['/card/ex10-%3F', ['GET /v1/cards/ex10-%3F', 'GET /v1/cards/ex10-%3F/prices?range=1M']],
      ];
      // The prices' freshness was asked once, before the hops, and answered within its limit.
      expect(calls.filter((call) => call.key === 'GET /v1/prices/freshness')).toHaveLength(1);
      for (const [path, keys] of hops) {
        const before = calls.length;
        go(path);
        await waitFor(() => {
          expect(calls.length - before).toBe(keys.length);
        });
        await settle();
        expect(screen.getAllByText(DEMO_NOTICE), path).toHaveLength(1);
        expect(within(banner()).getByRole('button', { name: 'Sign out' }), path).toBeTruthy();
        expect(
          calls.slice(before).map((call) => call.key),
          path,
        ).toEqual(keys);
        expect(banner().querySelectorAll(LIVE), path).toHaveLength(0);
      }
    },
  );
});

describe('the failed strip sign-out is announced where the visitor acted and can be retried', () => {
  it('keeps the banner free of live regions until a sign-out fails, then clears the alert on a retry that works', async () => {
    stubAccountFetch({
      'GET /v1/me': [jsonResponse(demoMe), problemResponse(unauthenticatedProblem)],
      'GET /v1/watchlist': jsonResponse(watchlistBody()),
      'POST /logout': [problemResponse(serverErrorProblem('/logout')), rawResponse(204, null)],
    });
    visit('/watchlist');
    render(<App />);
    await screen.findByRole('list', { name: 'Watchlist' });
    expect(banner().querySelectorAll(LIVE)).toHaveLength(0);
    fireEvent.click(within(banner()).getByRole('button', { name: 'Sign out' }));
    await within(banner()).findByRole('alert');
    expect(screen.getAllByRole('alert')).toHaveLength(1);
    expect(window.location.pathname).toBe('/watchlist');
    expect(screen.getAllByText(DEMO_NOTICE)).toHaveLength(1);
    fireEvent.click(within(banner()).getByRole('button', { name: 'Sign out' }));
    await waitFor(() => {
      expect(window.location.pathname).toBe('/');
    });
    expect(screen.queryByRole('alert')).toBeNull();
    expect(screen.queryByText(DEMO_NOTICE)).toBeNull();
  });
});

describe('list rows with hostile or odd card names', () => {
  function rowsFor(names: readonly (string | null)[]) {
    return names.map((name, index) =>
      watchItemOf({
        variant_key: `hostile-${String(index)}:holo:unlimited:-`,
        card_id: `hostile-${String(index)}`,
        card: name === null ? null : { name, set_name: 'Base', number: String(index) },
        price: null,
      }),
    );
  }

  async function initialsOf(names: readonly (string | null)[]): Promise<(string | null)[]> {
    stubAccountFetch({
      'GET /v1/me': jsonResponse(demoMe),
      'GET /v1/watchlist': jsonResponse(watchlistBody(rowsFor(names))),
    });
    visit('/watchlist');
    render(<App />);
    const list = await screen.findByRole('list', { name: 'Watchlist' });
    return within(list)
      .getAllByRole('listitem')
      .map((row) => row.querySelector('[data-initial]')?.getAttribute('data-initial') ?? null);
  }

  it('escapes markup, blanks an empty, blank or missing name, and never prints undefined', async () => {
    const hostile = '<img src=x onerror=alert(1)>';
    const initials = await initialsOf([hostile, '', '   ', null, 'x'.repeat(200), 'éclair']);
    expect(initials).toEqual(['<', '', '', '', 'X', 'É']);
    expect(main().querySelectorAll('img, script, [onerror]')).toHaveLength(0);
    expect(main().textContent).not.toContain('undefined');
    expect(within(main()).getAllByText(hostile)).toHaveLength(1);
    expect(main().querySelectorAll('div[aria-hidden]')).toHaveLength(0);
  });

  it('draws a whole character, never half a surrogate pair, for a name that starts with an emoji', async () => {
    const [initial] = await initialsOf(['\u{1F525} Flame']);
    expect(initial).toBe('\u{1F525}');
  });
});

describe('no decorative div[aria-hidden] on the list pages, in any state', () => {
  it.each([
    ['/watchlist', 'GET /v1/watchlist', watchlistBody()],
    ['/portfolio', 'GET /v1/portfolio', portfolioBody()],
    ['/portfolio', 'GET /v1/portfolio', emptyPortfolioBody],
    [
      '/portfolio',
      'GET /v1/portfolio',
      portfolioBody([holdingOf({ price: null, value: null })], {
        total_value: null,
        priced: 0,
        unpriced: 1,
        first_day: null,
        last_day: null,
      }),
    ],
  ] as const)('%s loaded (%#), demo and ordinary account', async (path, key, body) => {
    for (const me of [demoMe, signedInMe]) {
      stubAccountFetch({ 'GET /v1/me': jsonResponse(me), [key]: jsonResponse(body) });
      visit(path);
      const view = render(<App />);
      await settle();
      expect(main().querySelectorAll('div[aria-hidden]')).toHaveLength(0);
      view.unmount();
    }
  });

  it.each(['/watchlist', '/portfolio'] as const)(
    '%s: the loading skeleton is the only aria-hidden div; failed and signed out have none',
    async (path) => {
      const states = [
        null,
        problemResponse(serverErrorProblem('/v1/me')),
        problemResponse(unauthenticatedProblem),
      ];
      for (const answer of states) {
        // Loading shows the one skeleton bar after the status; no other state has any.
        const bars = answer === null ? 1 : 0;
        if (answer === null) hangingFetch();
        else stubAccountFetch({ 'GET /v1/me': answer });
        visit(path);
        const view = render(<App />);
        await settle();
        expect(main().querySelectorAll('div[aria-hidden]')).toHaveLength(bars);
        if (bars === 1) {
          expect(screen.getByRole('status').nextElementSibling).toBe(
            main().querySelector('div[aria-hidden]'),
          );
        }
        view.unmount();
      }
    },
  );

  it('keeps Total value one p whose next sibling is the days line, then the unpriced line, for the demo', async () => {
    stubAccountFetch({
      'GET /v1/me': jsonResponse(demoMe),
      'GET /v1/portfolio': jsonResponse(portfolioBody(workedHoldings)),
    });
    visit('/portfolio');
    render(<App />);
    const total = await screen.findByText('Total value $1,047.93');
    expect(total.tagName).toBe('P');
    expect(total.childNodes).toHaveLength(1);
    const days = total.nextElementSibling;
    expect(days?.tagName).toBe('P');
    expect(days?.textContent).toBe(
      'Closes from 2026-10-11 to 2026-10-12 from TCGCSV (daily close)',
    );
    expect(days?.nextElementSibling?.textContent).toBe(
      '1 holding has no price in the collected snapshots and is not counted.',
    );
    expect(screen.getAllByText(DEMO_NOTICE)).toHaveLength(1);
  });
});

describe('the demo notice is said once on every demo page, and /alerts keeps its own line', () => {
  it.each(['/watchlist', '/portfolio', '/account', '/alerts'] as const)(
    '%s: one strip sentence in the banner and none in main',
    async (path) => {
      stubAccountFetch({
        'GET /v1/me': jsonResponse(demoMe),
        'GET /v1/watchlist': jsonResponse(watchlistBody()),
        'GET /v1/portfolio': jsonResponse(portfolioBody()),
        'GET /v1/webhooks': jsonResponse({ data: [], meta: { count: 0, limit: 25 } }),
      });
      visit(path);
      render(<App />);
      await settle();
      expect(screen.getAllByText(DEMO_NOTICE)).toHaveLength(1);
      expect(within(banner()).getAllByText(DEMO_NOTICE)).toHaveLength(1);
      expect(main().textContent).not.toContain(DEMO_NOTICE);
      if (path === '/alerts') {
        expect(main().textContent).toContain(
          'The read-only demo cannot set alerts or add webhooks',
        );
      }
    },
  );
});

describe('the alert select follows the server and never loses an unsaved choice', () => {
  const A = 'Charizard, holo, unlimited';
  const B = 'Chimecho, reverse';
  const A_KEY = 'base1-4%3Aholo%3Aunlimited%3A-';

  const withAlert = (index: number, pct: number | null) =>
    watchItemOf({ ...watchlistItems[index], alert_pct: pct });

  const select = (name: string): HTMLSelectElement =>
    screen.getByRole<HTMLSelectElement>('combobox', { name: `Alert threshold for ${name}` });

  it('keeps a saved value over a stale read, then follows the server to a threshold set elsewhere', async () => {
    const first = watchlistItems[0];
    const chimecho = withAlert(2, null);
    if (first === undefined) throw new Error('fixture');
    const calls = stubAccountFetch({
      'GET /v1/me': jsonResponse(signedInMe),
      'GET /v1/watchlist': [
        jsonResponse(watchlistBody([first, withAlert(1, 5), chimecho])),
        // The refetch after the save is stale (the old threshold) ...
        jsonResponse(watchlistBody([first, withAlert(1, 5), chimecho])),
        // ... the next poll has caught up, and the one after was changed on another device.
        jsonResponse(watchlistBody([withAlert(1, 20), chimecho])),
        jsonResponse(watchlistBody([withAlert(1, 30)])),
      ],
      [`PUT /v1/alerts/${A_KEY}`]: jsonResponse(alertBody('base1-4:holo:unlimited:-', 20)),
      'DELETE /v1/watchlist/base1-4%3Aholo%3A1st%3A-': rawResponse(204, null),
      'DELETE /v1/watchlist/ex5-17%3Areverse%3A-%3A-': rawResponse(204, null),
    });
    visit('/watchlist');
    render(<App />);
    await screen.findByRole('list', { name: 'Watchlist' });
    fireEvent.change(select(A), { target: { value: '20' } });
    fireEvent.click(screen.getByRole('button', { name: `Save the alert for ${A}` }));
    await waitFor(() => {
      expect(calls.filter((call) => call.key === 'GET /v1/watchlist')).toHaveLength(2);
    });
    await settle();
    expect(screen.getByText('Alert: at 5%')).toBeTruthy();
    expect(select(A).value).toBe('20');

    fireEvent.click(
      screen.getByRole('button', { name: 'Remove Charizard, holo, 1st from your watchlist' }),
    );
    await waitFor(() => {
      expect(screen.getByText('Alert: at 20%')).toBeTruthy();
    });
    expect(select(A).value).toBe('20');

    fireEvent.click(
      screen.getByRole('button', { name: 'Remove Chimecho, reverse from your watchlist' }),
    );
    await waitFor(() => {
      expect(screen.getByText('Alert: at 30%')).toBeTruthy();
    });
    expect(select(A).value).toBe('30');
  });

  it('gives each row its own select state when a refetch lists the rows in another order', async () => {
    const first = watchlistItems[0];
    if (first === undefined) throw new Error('fixture');
    stubAccountFetch({
      'GET /v1/me': jsonResponse(signedInMe),
      'GET /v1/watchlist': [
        jsonResponse(watchlistBody([first, withAlert(1, 5), withAlert(2, 10)])),
        jsonResponse(watchlistBody([withAlert(2, 10), withAlert(1, 5)])),
      ],
      'DELETE /v1/watchlist/base1-4%3Aholo%3A1st%3A-': rawResponse(204, null),
    });
    visit('/watchlist');
    render(<App />);
    await screen.findByRole('list', { name: 'Watchlist' });
    fireEvent.change(select(A), { target: { value: '20' } });
    fireEvent.click(
      screen.getByRole('button', { name: 'Remove Charizard, holo, 1st from your watchlist' }),
    );
    await waitFor(() => {
      expect(
        within(screen.getByRole('list', { name: 'Watchlist' })).getAllByRole('listitem'),
      ).toHaveLength(2);
    });
    const names = within(main())
      .getAllByRole('combobox')
      .map((element) => element.getAttribute('aria-label'));
    expect(names).toEqual([`Alert threshold for ${B}`, `Alert threshold for ${A}`]);
    expect(select(A).value).toBe('20');
    expect(select(B).value).toBe('10');
  });
});
