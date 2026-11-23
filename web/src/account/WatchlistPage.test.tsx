import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import App from '../App';
import {
  FORBIDDEN_WORDS,
  type AccountAnswer,
  clearXsrfCookie,
  demoMe,
  jsonResponse,
  lightOnlyClasses,
  millionPrice,
  problemResponse,
  rawResponse,
  serverErrorProblem,
  shortControls,
  signedInMe,
  stubAccountFetch,
  unauthenticatedProblem,
  watchItemOf,
  watchlistBody,
  watchlistItems,
} from '../test/fixtures';

const FOOTNOTE = 'TCGplayer market prices via TCGCSV, collected once a day.';
const DEGRADED = 'Catalog data may be out of date: PokéSearch is unavailable';
const DEMO_NOTICE =
  'You are signed in to the read-only demo account. Its watchlist and portfolio cannot be changed.';
const EMPTY = 'Your watchlist is empty. Open a card from Search and choose Add to watchlist.';
const CHARIZARD_KEY = '/v1/watchlist/base1-4%3Aholo%3Aunlimited%3A-';

function visit(path: string): void {
  window.history.replaceState(null, '', path);
}

afterEach(() => {
  cleanup();
  clearXsrfCookie();
  vi.useRealTimers();
  visit('/');
});

function main(): HTMLElement {
  return screen.getByRole('main');
}

function rows(): HTMLElement[] {
  return within(screen.getByRole('list', { name: 'Watchlist' })).getAllByRole('listitem');
}

function openWatchlist(
  routes: Readonly<Record<string, AccountAnswer | readonly AccountAnswer[]>>,
): ReturnType<typeof stubAccountFetch> {
  const calls = stubAccountFetch(routes);
  visit('/watchlist');
  render(<App />);
  return calls;
}

describe('the watchlist page (T07 criterion 1, D10)', () => {
  it('shows the loading line, then the signed-out view with its links and the demo button', async () => {
    const calls = openWatchlist({ 'GET /v1/me': problemResponse(unauthenticatedProblem) });
    expect(screen.getByRole('heading', { level: 1 }).textContent).toBe('Watchlist');
    expect(screen.getByRole('status').textContent).toBe('Loading your watchlist…');
    expect(await screen.findByText('Sign in to keep a watchlist.')).toBeTruthy();
    const links = within(main())
      .getAllByRole('link')
      .map((link) => [link.textContent, link.getAttribute('href')]);
    expect(links).toEqual([
      ['Sign in', '/sign-in?next=/watchlist'],
      ['Create an account', '/sign-up'],
    ]);
    expect(within(main()).getByRole('button', { name: 'Try the read-only demo' })).toBeTruthy();
    expect(screen.queryByRole('alert')).toBeNull();
    expect(calls.map((call) => call.key)).toEqual(['GET /v1/prices/freshness', 'GET /v1/me']);
  });

  it('shows each row like a movers row with its own as-of line, then the footnote', async () => {
    openWatchlist({
      'GET /v1/me': jsonResponse(signedInMe),
      'GET /v1/watchlist': jsonResponse(watchlistBody()),
    });
    await screen.findByRole('list', { name: 'Watchlist' });
    const [first, rising, falling] = rows();
    if (first === undefined || rising === undefined || falling === undefined) {
      throw new Error('three rows expected');
    }
    const link = within(rising).getByRole('link', { name: 'Charizard' });
    expect(link.getAttribute('href')).toBe('/card/base1-4?variant=base1-4%3Aholo%3Aunlimited%3A-');
    expect(within(rising).getByText('Base No. 4 · Holo, unlimited')).toBeTruthy();
    expect(within(rising).getByText('Market price $968.91')).toBeTruthy();
    const up = within(rising).getByText('▲ +$18.91 (+1.99%) vs previous close (2026-10-11)');
    expect(up.className).toContain('sr-only');
    expect(within(rising).getByText('▲ +1.99%').className).toContain('text-up');
    expect(within(rising).getByText('as of 2026-10-12 from TCGCSV (daily close)')).toBeTruthy();

    const down = within(falling).getByText('▼ -$0.66 (-2.44%) vs previous close (2026-10-10)');
    expect(down.className).toContain('sr-only');
    expect(within(falling).getByText('▼ -2.44%').className).toContain('text-down');
    expect(within(falling).getByText('Hidden Legends No. 17 · Reverse')).toBeTruthy();
    expect(within(falling).getByText('as of 2026-10-11 from TCGCSV (daily close)')).toBeTruthy();

    expect(within(first).getByText('No price in the collected snapshots')).toBeTruthy();
    expect(within(first).queryByText(/Market price/)).toBeNull();
    expect(within(first).queryByText(/^as of/)).toBeNull();

    expect(
      within(rising).getByRole('button', {
        name: 'Remove Charizard, holo, unlimited from your watchlist',
      }),
    ).toBeTruthy();
    expect(
      within(first).getByRole('button', {
        name: 'Remove Charizard, holo, 1st from your watchlist',
      }),
    ).toBeTruthy();
    expect(main().textContent.endsWith(FOOTNOTE)).toBe(true);
    expect(screen.queryByText(DEGRADED)).toBeNull();
    expect(screen.queryByText(DEMO_NOTICE)).toBeNull();
  });

  it('names a card the catalog does not know by its id and prints the variant alone', async () => {
    openWatchlist({
      'GET /v1/me': jsonResponse(signedInMe),
      'GET /v1/watchlist': jsonResponse(watchlistBody([watchItemOf({ card: null })])),
    });
    const link = await screen.findByRole('link', { name: 'Card base1-4' });
    expect(link.getAttribute('href')).toBe('/card/base1-4?variant=base1-4%3Aholo%3Aunlimited%3A-');
    expect(within(rows()[0] as HTMLElement).getByText('Holo, unlimited')).toBeTruthy();
  });

  it('prints the API money strings verbatim, grouped, never converted', async () => {
    openWatchlist({
      'GET /v1/me': jsonResponse(signedInMe),
      'GET /v1/watchlist': jsonResponse(
        watchlistBody([
          watchItemOf({ price: millionPrice }),
          watchItemOf({
            variant_key: 'base1-4:holo:1st:-',
            edition: '1st',
            price: { ...millionPrice, close: '9999999999.99', change: '0.10', change_pct: '0.00' },
          }),
        ]),
      ),
    });
    expect(await screen.findByText('Market price $1,000,000.00')).toBeTruthy();
    expect(screen.getByText('Market price $9,999,999,999.99')).toBeTruthy();
    expect(screen.getAllByText('▲ +$0.10 (+0.00%) vs previous close (2026-10-11)')).toHaveLength(2);
  });

  it('shows the empty line without a list or a footnote', async () => {
    openWatchlist({
      'GET /v1/me': jsonResponse(signedInMe),
      'GET /v1/watchlist': jsonResponse(watchlistBody([])),
    });
    expect(await screen.findByText(EMPTY)).toBeTruthy();
    expect(screen.queryByRole('list', { name: 'Watchlist' })).toBeNull();
    expect(screen.queryByText(FOOTNOTE)).toBeNull();
  });

  it('shows the degraded catalog line when meta.degraded is true', async () => {
    openWatchlist({
      'GET /v1/me': jsonResponse(signedInMe),
      'GET /v1/watchlist': jsonResponse(watchlistBody(watchlistItems, { degraded: true })),
    });
    expect(await screen.findByText(DEGRADED)).toBeTruthy();
  });

  it('shows the problem block when the watchlist cannot be loaded', async () => {
    openWatchlist({
      'GET /v1/me': jsonResponse(signedInMe),
      'GET /v1/watchlist': problemResponse(serverErrorProblem('/v1/watchlist')),
    });
    const alert = await screen.findByRole('alert');
    expect(alert.textContent).toContain('Could not load your watchlist: Internal Server Error');
    expect(alert.textContent).toContain('Request ID req-p4-server-0007');
  });

  it('shows the signed-out view when the watchlist answers 401', async () => {
    openWatchlist({
      'GET /v1/me': jsonResponse(signedInMe),
      'GET /v1/watchlist': problemResponse(unauthenticatedProblem),
    });
    expect(await screen.findByText('Sign in to keep a watchlist.')).toBeTruthy();
    expect(screen.queryByRole('alert')).toBeNull();
  });

  it('shows the problem block when the session cannot be loaded', async () => {
    openWatchlist({ 'GET /v1/me': problemResponse(serverErrorProblem('/v1/me')) });
    const alert = await screen.findByRole('alert');
    expect(alert.textContent).toContain('Could not load your account: Internal Server Error');
  });
});

describe('removing from the watchlist (T07 criterion 2)', () => {
  it('sends DELETE for the encoded key, then refetches the list', async () => {
    const calls = openWatchlist({
      'GET /v1/me': jsonResponse(signedInMe),
      'GET /v1/watchlist': [
        jsonResponse(watchlistBody()),
        jsonResponse(watchlistBody(watchlistItems.filter((_, index) => index !== 1))),
      ],
      [`DELETE ${CHARIZARD_KEY}`]: rawResponse(204, null),
    });
    fireEvent.click(
      await screen.findByRole('button', {
        name: 'Remove Charizard, holo, unlimited from your watchlist',
      }),
    );
    await waitFor(() => {
      expect(rows().filter((item) => !item.textContent.startsWith('Removed.'))).toHaveLength(2);
    });
    expect(screen.queryByText('Market price $968.91')).toBeNull();
    const keys = calls.map((call) => call.key);
    expect(keys.filter((key) => key === `DELETE ${CHARIZARD_KEY}`)).toHaveLength(1);
    expect(keys.filter((key) => key === 'GET /v1/watchlist')).toHaveLength(2);
    expect(keys.indexOf(`DELETE ${CHARIZARD_KEY}`)).toBeLessThan(
      keys.lastIndexOf('GET /v1/watchlist'),
    );
  });

  it('keeps the last good list on screen when the removal fails', async () => {
    openWatchlist({
      'GET /v1/me': jsonResponse(signedInMe),
      'GET /v1/watchlist': jsonResponse(watchlistBody()),
      [`DELETE ${CHARIZARD_KEY}`]: problemResponse(serverErrorProblem(CHARIZARD_KEY)),
    });
    fireEvent.click(
      await screen.findByRole('button', {
        name: 'Remove Charizard, holo, unlimited from your watchlist',
      }),
    );
    const alert = await screen.findByRole('alert');
    expect(alert.textContent).toContain('Could not change your watchlist: Internal Server Error');
    expect(rows()).toHaveLength(3);
    expect(screen.getByText('Market price $968.91')).toBeTruthy();
  });

  it('asks GET /v1/me once more when the removal answers 401, then shows the signed-out view', async () => {
    let me: Response = jsonResponse(signedInMe);
    const calls = openWatchlist({
      'GET /v1/me': () => me,
      'GET /v1/watchlist': jsonResponse(watchlistBody()),
      [`DELETE ${CHARIZARD_KEY}`]: problemResponse({
        ...unauthenticatedProblem,
        instance: '/v1/watchlist',
      }),
    });
    const remove = await screen.findByRole('button', {
      name: 'Remove Charizard, holo, unlimited from your watchlist',
    });
    // The session expired between the read and the write.
    me = problemResponse(unauthenticatedProblem);
    fireEvent.click(remove);
    expect(await screen.findByText('Sign in to keep a watchlist.')).toBeTruthy();
    await new Promise((resolve) => setTimeout(resolve, 30));
    expect(calls.map((call) => call.key)).toEqual([
      'GET /v1/prices/freshness',
      'GET /v1/me',
      'GET /v1/watchlist',
      'GET /sanctum/csrf-cookie',
      `DELETE ${CHARIZARD_KEY}`,
      'GET /v1/me',
    ]);
    expect(within(main()).queryByRole('list', { name: 'Watchlist' })).toBeNull();
    expect(screen.queryByText('Market price $968.91')).toBeNull();
  });
});

describe('the watchlist of the read-only demo (T07 criterion 4)', () => {
  it('says it is the read-only demo and renders no Remove button', async () => {
    openWatchlist({
      'GET /v1/me': jsonResponse(demoMe),
      'GET /v1/watchlist': jsonResponse(watchlistBody()),
    });
    expect(await screen.findByText(DEMO_NOTICE)).toBeTruthy();
    await screen.findByRole('list', { name: 'Watchlist' });
    expect(within(main()).queryAllByRole('button')).toEqual([]);
    expect(screen.getByText('Market price $968.91')).toBeTruthy();
  });
});

describe('watchlist accessibility and honesty (T07 criterion 6)', () => {
  const states = [
    [
      'signed out',
      { 'GET /v1/me': problemResponse(unauthenticatedProblem) },
      'Sign in to keep a watchlist.',
    ],
    [
      'rows',
      {
        'GET /v1/me': jsonResponse(signedInMe),
        'GET /v1/watchlist': jsonResponse(watchlistBody(watchlistItems, { degraded: true })),
      },
      FOOTNOTE,
    ],
    [
      'demo',
      { 'GET /v1/me': jsonResponse(demoMe), 'GET /v1/watchlist': jsonResponse(watchlistBody()) },
      FOOTNOTE,
    ],
    [
      'empty',
      {
        'GET /v1/me': jsonResponse(signedInMe),
        'GET /v1/watchlist': jsonResponse(watchlistBody([])),
      },
      EMPTY,
    ],
    [
      'failed',
      {
        'GET /v1/me': jsonResponse(signedInMe),
        'GET /v1/watchlist': problemResponse(serverErrorProblem('/v1/watchlist')),
      },
      /Could not load your watchlist/,
    ],
  ] as const;

  it.each(states)(
    '%s: dark counterparts, 44 px controls, no forbidden word, no clock',
    async (_name, routes, ready) => {
      vi.useFakeTimers({ toFake: ['Date'] });
      vi.setSystemTime(new Date('2031-02-03T04:05:06Z'));
      stubAccountFetch(routes);
      visit('/watchlist');
      const { container } = render(<App />);
      const check = (): void => {
        expect(lightOnlyClasses(container)).toEqual([]);
        expect(shortControls(main())).toEqual([]);
        for (const pattern of FORBIDDEN_WORDS) expect(main().textContent).not.toMatch(pattern);
        expect(main().textContent).not.toContain('2031');
      };
      check();
      await screen.findAllByText(ready);
      check();
      expect(screen.getAllByRole('heading', { level: 1 })).toHaveLength(1);
    },
  );
});
