// Remove on the watchlist and the portfolio leaves "Removed." with Undo in the row's place. Undo
// puts the row back through the existing routes (the watchlist PUT, and its alert when it had one;
// the portfolio PUT with the old quantity). The line stays until the visitor leaves the page or
// removes another row. A failed removal keeps the row with its problem; a failed Undo says so and
// offers Undo again. Focus: the Undo after a removal, the restored row after Undo, the row in the
// line's place (or the page heading when there is none) when the line goes away; never the body.
import type { QueryClient } from '@tanstack/react-query';
import { act, cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import App from '../App';
import type * as QueryModule from '../query';
import {
  FORBIDDEN_WORDS,
  accountValidationProblem,
  clearXsrfCookie,
  jsonResponse,
  portfolioBody,
  problemResponse,
  rawResponse,
  sentBody,
  serverErrorProblem,
  unauthenticatedProblem,
  signedInMe,
  stubAccountFetch,
  watchItemOf,
  watchlistBody,
  watchlistItems,
  workedHoldings,
  type AccountAnswer,
} from '../test/fixtures';
import { SESSION_SIGNAL_KEY } from './session';

const created = vi.hoisted(() => ({ clients: [] as QueryClient[] }));
vi.mock('../query', async (importOriginal) => {
  const actual = await importOriginal<typeof QueryModule>();
  return {
    ...actual,
    createQueryClient: (): QueryClient => {
      const client = actual.createQueryClient();
      created.clients.push(client);
      return client;
    },
  };
});

const WATCHLIST = 'GET /v1/watchlist';
const PORTFOLIO = 'GET /v1/portfolio';
const CHARIZARD = 'base1-4%3Aholo%3Aunlimited%3A-';
const CHIMECHO = 'ex5-17%3Areverse%3A-%3A-';
const FIRST = 'base1-4%3Aholo%3A1st%3A-';

afterEach(() => {
  cleanup();
  clearXsrfCookie();
  created.clients.length = 0;
  window.localStorage.clear();
  window.history.replaceState(null, '', '/');
});

async function settle(): Promise<void> {
  for (let i = 0; i < 6; i += 1) {
    await act(async () => {
      await new Promise((resolve) => setTimeout(resolve, 0));
    });
  }
}

type Routes = Readonly<Record<string, AccountAnswer | readonly AccountAnswer[]>>;

async function open(path: '/watchlist' | '/portfolio', routes: Routes) {
  const calls = stubAccountFetch({ 'GET /v1/me': jsonResponse(signedInMe), ...routes });
  window.history.replaceState(null, '', path);
  render(<App />);
  await screen.findByRole('list', { name: path === '/watchlist' ? 'Watchlist' : 'Portfolio' });
  await settle();
  return calls;
}

function list(name: 'Watchlist' | 'Portfolio'): HTMLElement {
  return screen.getByRole('list', { name });
}

/** Each item of the list: the card name of a row, or "Removed." for the line. */
function items(name: 'Watchlist' | 'Portfolio'): string[] {
  return Array.from(list(name).children).map((item) =>
    item.textContent.startsWith('Removed.')
      ? 'Removed.'
      : (item.querySelector('a')?.textContent ?? ''),
  );
}

function keysAfter(calls: { key: string }[], start: number): string[] {
  return calls.slice(start).map((call) => call.key);
}

const charizard = watchlistItems[1];
const watchWithout = (gone: string) =>
  watchlistBody(watchlistItems.filter((item) => item.variant_key !== gone));

describe('the watchlist: Removed. Undo', () => {
  it('replaces the row by the line, focuses Undo, and Undo puts the row back and focuses it', async () => {
    const calls = await open('/watchlist', {
      [WATCHLIST]: [
        jsonResponse(watchlistBody()),
        jsonResponse(watchWithout('base1-4:holo:unlimited:-')),
        jsonResponse(watchlistBody()),
      ],
      [`DELETE /v1/watchlist/${CHARIZARD}`]: rawResponse(204, null),
      [`PUT /v1/watchlist/${CHARIZARD}`]: jsonResponse({
        data: { variant_key: charizard?.variant_key },
      }),
    });
    const start = calls.length;
    fireEvent.click(
      within(list('Watchlist')).getByRole('button', {
        name: 'Remove Charizard, holo, unlimited from your watchlist',
      }),
    );
    const undo = await screen.findByRole('button', {
      name: 'Undo removing Charizard, holo, unlimited from your watchlist',
    });
    await waitFor(() => {
      expect(document.activeElement).toBe(undo);
    });
    expect(items('Watchlist')).toEqual(['Charizard', 'Removed.', 'Chimecho']);
    expect(undo.textContent).toBe('Undo');
    for (const word of FORBIDDEN_WORDS) expect(list('Watchlist').textContent).not.toMatch(word);
    expect(keysAfter(calls, start)).toEqual([
      'GET /sanctum/csrf-cookie',
      `DELETE /v1/watchlist/${CHARIZARD}`,
      WATCHLIST,
    ]);

    const before = calls.length;
    fireEvent.click(undo);
    await waitFor(() => {
      expect(items('Watchlist')).toEqual(['Charizard', 'Charizard', 'Chimecho']);
    });
    const restored = list('Watchlist').children[1]?.querySelector('a');
    await waitFor(() => {
      expect(document.activeElement).toBe(restored);
    });
    expect(keysAfter(calls, before)).toEqual([`PUT /v1/watchlist/${CHARIZARD}`, WATCHLIST]);
  });

  it('puts the alert back too when the row had one: the watchlist PUT, then the alert PUT, then one read', async () => {
    const alerted = watchItemOf({ alert_pct: 10 });
    const calls = await open('/watchlist', {
      [WATCHLIST]: [
        jsonResponse(watchlistBody([alerted])),
        jsonResponse(watchlistBody([])),
        jsonResponse(watchlistBody([alerted])),
      ],
      [`DELETE /v1/watchlist/${CHARIZARD}`]: rawResponse(204, null),
      [`PUT /v1/watchlist/${CHARIZARD}`]: jsonResponse({
        data: { variant_key: alerted.variant_key },
      }),
      [`PUT /v1/alerts/${CHARIZARD}`]: jsonResponse({
        data: { variant_key: alerted.variant_key, alert_pct: 10 },
      }),
    });
    fireEvent.click(within(list('Watchlist')).getByRole('button', { name: /^Remove Charizard/ }));
    const undo = await screen.findByRole('button', { name: /^Undo removing Charizard/ });
    expect(items('Watchlist')).toEqual(['Removed.']);
    expect(screen.queryByText(/Your watchlist is empty/)).toBeNull();
    const before = calls.length;
    fireEvent.click(undo);
    await screen.findByText('Alert: at 10%');
    const keys = calls
      .slice(before)
      .map((call) => call.key)
      .filter((key) => !key.includes('csrf'));
    expect(keys).toEqual([
      `PUT /v1/watchlist/${CHARIZARD}`,
      `PUT /v1/alerts/${CHARIZARD}`,
      WATCHLIST,
    ]);
    expect(sentBody(calls.find((call) => call.key === `PUT /v1/alerts/${CHARIZARD}`))).toEqual({
      pct: 10,
    });
  });

  it('keeps the row with its problem when the removal fails, and focus on its Remove', async () => {
    await open('/watchlist', {
      [WATCHLIST]: jsonResponse(watchlistBody()),
      [`DELETE /v1/watchlist/${CHARIZARD}`]: problemResponse(
        serverErrorProblem(`/v1/watchlist/${CHARIZARD}`),
      ),
    });
    const remove = within(list('Watchlist')).getByRole('button', {
      name: /^Remove Charizard, holo, unlimited/,
    });
    fireEvent.click(remove);
    await screen.findByText(/Could not change your watchlist: Internal Server Error/);
    expect(items('Watchlist')).toEqual(['Charizard', 'Charizard', 'Chimecho']);
    expect(screen.queryByRole('button', { name: /^Undo/ })).toBeNull();
    await waitFor(() => {
      expect(document.activeElement).toBe(
        within(list('Watchlist')).getByRole('button', {
          name: /^Remove Charizard, holo, unlimited/,
        }),
      );
    });
  });

  it('says a failed Undo and offers it again; the second Undo puts the row back', async () => {
    const calls = await open('/watchlist', {
      [WATCHLIST]: [
        jsonResponse(watchlistBody()),
        jsonResponse(watchWithout('base1-4:holo:unlimited:-')),
        jsonResponse(watchlistBody()),
      ],
      [`DELETE /v1/watchlist/${CHARIZARD}`]: rawResponse(204, null),
      [`PUT /v1/watchlist/${CHARIZARD}`]: [
        problemResponse(serverErrorProblem(`/v1/watchlist/${CHARIZARD}`)),
        jsonResponse({ data: { variant_key: charizard?.variant_key } }),
      ],
    });
    fireEvent.click(
      within(list('Watchlist')).getByRole('button', { name: /^Remove Charizard, holo, unlimited/ }),
    );
    const undo = await screen.findByRole('button', { name: /^Undo removing Charizard/ });
    fireEvent.click(undo);
    await screen.findByText(/Could not put it back: Internal Server Error/);
    const line = list('Watchlist').children[1] as HTMLElement;
    expect(line.textContent).toContain('Removed.');
    expect(line.textContent).toContain('Undo did not go through.');
    for (const word of FORBIDDEN_WORDS)
      expect(screen.getByRole('main').textContent).not.toMatch(word);
    const again = within(line).getByRole('button', { name: /^Undo removing Charizard/ });
    await waitFor(() => {
      expect(document.activeElement).toBe(again);
    });
    fireEvent.click(again);
    await waitFor(() => {
      expect(items('Watchlist')).toEqual(['Charizard', 'Charizard', 'Chimecho']);
    });
    expect(screen.queryByText(/Could not put it back/)).toBeNull();
    expect(calls.filter((call) => call.key === `PUT /v1/watchlist/${CHARIZARD}`)).toHaveLength(2);
  });

  it('keeps one line: removing another row moves the line there and focuses its Undo', async () => {
    await open('/watchlist', {
      [WATCHLIST]: [
        jsonResponse(watchlistBody()),
        jsonResponse(watchWithout('base1-4:holo:unlimited:-')),
        jsonResponse(watchlistBody(watchlistItems.slice(0, 1))),
      ],
      [`DELETE /v1/watchlist/${CHARIZARD}`]: rawResponse(204, null),
      [`DELETE /v1/watchlist/${CHIMECHO}`]: rawResponse(204, null),
    });
    fireEvent.click(
      within(list('Watchlist')).getByRole('button', { name: /^Remove Charizard, holo, unlimited/ }),
    );
    await screen.findByRole('button', { name: /^Undo removing Charizard/ });
    fireEvent.click(within(list('Watchlist')).getByRole('button', { name: /^Remove Chimecho/ }));
    const undo = await screen.findByRole('button', { name: /^Undo removing Chimecho/ });
    await waitFor(() => {
      expect(document.activeElement).toBe(undo);
    });
    expect(items('Watchlist')).toEqual(['Charizard', 'Removed.']);
    expect(screen.queryByRole('button', { name: /^Undo removing Charizard/ })).toBeNull();
  });

  it('when the line goes away because the list holds the row again, focus moves to the row in its place', async () => {
    await open('/watchlist', {
      [WATCHLIST]: [
        jsonResponse(watchlistBody()),
        jsonResponse(watchWithout('base1-4:holo:unlimited:-')),
        jsonResponse(watchlistBody()),
      ],
      [`DELETE /v1/watchlist/${CHARIZARD}`]: rawResponse(204, null),
    });
    fireEvent.click(
      within(list('Watchlist')).getByRole('button', { name: /^Remove Charizard, holo, unlimited/ }),
    );
    const undo = await screen.findByRole('button', { name: /^Undo removing Charizard/ });
    await waitFor(() => {
      expect(document.activeElement).toBe(undo);
    });
    // Put back elsewhere (another tab): the next read holds it again.
    await act(async () => {
      await created.clients[0]?.refetchQueries({ queryKey: ['account', 'watchlist'] });
    });
    await waitFor(() => {
      expect(items('Watchlist')).toEqual(['Charizard', 'Charizard', 'Chimecho']);
    });
    expect(document.activeElement).toBe(list('Watchlist').children[1]?.querySelector('a'));
    expect(document.activeElement).not.toBe(document.body);
  });

  it('when the line goes away with no row in its place, focus moves to the page heading', async () => {
    await open('/watchlist', {
      'GET /v1/me': [jsonResponse(signedInMe), problemResponse(unauthenticatedProblem)],
      [WATCHLIST]: [jsonResponse(watchlistBody([watchItemOf()])), jsonResponse(watchlistBody([]))],
      [`DELETE /v1/watchlist/${CHARIZARD}`]: rawResponse(204, null),
    });
    fireEvent.click(within(list('Watchlist')).getByRole('button', { name: /^Remove Charizard/ }));
    const undo = await screen.findByRole('button', { name: /^Undo removing Charizard/ });
    await waitFor(() => {
      expect(document.activeElement).toBe(undo);
    });
    // Another tab signs out: this one forgets the account's lists and shows the signed-out page,
    // and the line goes with the list.
    await act(async () => {
      window.dispatchEvent(new StorageEvent('storage', { key: SESSION_SIGNAL_KEY, newValue: 'x' }));
      await new Promise((resolve) => setTimeout(resolve, 0));
    });
    await screen.findByText('Sign in to keep a watchlist.');
    expect(screen.queryByRole('button', { name: /^Undo removing/ })).toBeNull();
    expect(document.activeElement).toBe(
      screen.getByRole('heading', { level: 1, name: 'Watchlist' }),
    );
  });
});

describe('the portfolio: Removed. Undo restores the quantity', () => {
  const portfolioWithout = (gone: string) =>
    portfolioBody(workedHoldings.filter((holding) => holding.variant_key !== gone));

  it('Undo sends the old quantity, and the row comes back with it', async () => {
    const calls = await open('/portfolio', {
      [PORTFOLIO]: [
        jsonResponse(portfolioBody()),
        jsonResponse(portfolioWithout('ex5-17:reverse:-:-')),
        jsonResponse(portfolioBody()),
      ],
      [`DELETE /v1/portfolio/${CHIMECHO}`]: rawResponse(204, null),
      [`PUT /v1/portfolio/${CHIMECHO}`]: jsonResponse({
        data: { variant_key: 'ex5-17:reverse:-:-', quantity: 3 },
      }),
    });
    const start = calls.length;
    fireEvent.click(within(list('Portfolio')).getByRole('button', { name: /^Remove Chimecho/ }));
    const undo = await screen.findByRole('button', {
      name: 'Undo removing Chimecho, reverse from your portfolio',
    });
    await waitFor(() => {
      expect(document.activeElement).toBe(undo);
    });
    expect(items('Portfolio')).toEqual(['Charizard', 'Charizard', 'Removed.']);
    fireEvent.click(undo);
    await waitFor(() => {
      expect(items('Portfolio')).toEqual(['Charizard', 'Charizard', 'Chimecho']);
    });
    expect(within(list('Portfolio')).getByText('Quantity 3')).toBeTruthy();
    expect(document.activeElement).toBe(list('Portfolio').children[2]?.querySelector('a'));
    expect(keysAfter(calls, start)).toEqual([
      'GET /sanctum/csrf-cookie',
      `DELETE /v1/portfolio/${CHIMECHO}`,
      PORTFOLIO,
      `PUT /v1/portfolio/${CHIMECHO}`,
      PORTFOLIO,
    ]);
    expect(sentBody(calls.find((call) => call.key === `PUT /v1/portfolio/${CHIMECHO}`))).toEqual({
      quantity: 3,
    });
  });

  it('shows a refused Undo (a full portfolio) and offers it again; a failed removal keeps the row', async () => {
    await open('/portfolio', {
      [PORTFOLIO]: [
        jsonResponse(portfolioBody()),
        jsonResponse(portfolioWithout('ex5-17:reverse:-:-')),
      ],
      [`DELETE /v1/portfolio/${CHIMECHO}`]: rawResponse(204, null),
      [`DELETE /v1/portfolio/${FIRST}`]: problemResponse(
        serverErrorProblem(`/v1/portfolio/${FIRST}`),
      ),
      [`PUT /v1/portfolio/${CHIMECHO}`]: problemResponse(
        accountValidationProblem(`/v1/portfolio/${CHIMECHO}`, {
          variant_key: ['Your portfolio holds at most 100 variants.'],
        }),
      ),
    });
    fireEvent.click(within(list('Portfolio')).getByRole('button', { name: /^Remove Chimecho/ }));
    fireEvent.click(await screen.findByRole('button', { name: /^Undo removing Chimecho/ }));
    await screen.findByText(/Your portfolio holds at most 100 variants\./);
    expect(screen.getByRole('button', { name: /^Undo removing Chimecho/ })).toBeTruthy();
    fireEvent.click(
      within(list('Portfolio')).getByRole('button', {
        name: 'Remove Charizard, holo, 1st from your portfolio',
      }),
    );
    await screen.findByText(/Could not change your portfolio: Internal Server Error/);
    expect(items('Portfolio')).toEqual(['Charizard', 'Charizard', 'Removed.']);
  });
});
