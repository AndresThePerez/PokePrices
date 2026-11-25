// Adversarial tests for Removed. Undo and "Saved.": Undo sends the old quantity (1 and 9999, never 1
// by default and never an unsaved draft), a double press sends one write, a watchlist Undo whose
// alert PUT fails brings the row back without it and the alert can be set again on the row, a
// removal and an Undo that fail with 404, 422, 429 and
// 500 each keep their line and their focus (never the page body), two removals in flight end with one
// line and no row shown twice, leaving the page and coming back drops the line, and "Saved." is one
// status line that goes with the next write.
import { act, cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import App from '../App';
import { navigate } from '../router';
import {
  FORBIDDEN_WORDS,
  clearXsrfCookie,
  holdSessionCookieFor,
  holdingOf,
  jsonResponse,
  portfolioBody,
  problemResponse,
  rawResponse,
  sentBody,
  serverErrorProblem,
  signedInMe,
  stubAccountFetch,
  tooManyRequestsProblem,
  watchItemOf,
  watchlistBody,
  watchlistItems,
  workedHoldings,
  type AccountAnswer,
} from '../test/fixtures';

const WATCHLIST = 'GET /v1/watchlist';
const PORTFOLIO = 'GET /v1/portfolio';
const CHARIZARD = 'base1-4%3Aholo%3Aunlimited%3A-';
const CHIMECHO = 'ex5-17%3Areverse%3A-%3A-';
const BASE = 'https://pokeprices.andrestheperez.com/problems/';

afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
  clearXsrfCookie();
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

/** A response that arrives when the test releases it. */
function deferred() {
  let release: (response: Response) => void = () => undefined;
  const promise = new Promise<Response>((resolve) => {
    release = resolve;
  });
  return { promise, release };
}

/**
 * A fetch stub whose answers may be promises: `METHOD url` keys answer their entries in order (the last
 * one repeats); GET /sanctum/csrf-cookie answers 204. Returns the names of the requests, in order.
 */
function stubDeferred(
  routes: Readonly<Record<string, readonly (Response | Promise<Response>)[]>>,
): string[] {
  const names: string[] = [];
  const used = new Map<string, number>();
  holdSessionCookieFor(routes['GET /v1/me']?.[0]);
  vi.stubGlobal('fetch', (input: string | URL, init?: RequestInit): Promise<Response> => {
    const name = `${init?.method ?? 'GET'} ${String(input)}`;
    names.push(name);
    if (name === 'GET /sanctum/csrf-cookie') return Promise.resolve(rawResponse(204, null));
    const answers = routes[name];
    if (answers === undefined) return Promise.reject(new TypeError(`no request expected: ${name}`));
    const index = used.get(name) ?? 0;
    used.set(name, index + 1);
    const answer = answers[Math.min(index, answers.length - 1)];
    if (answer === undefined) return Promise.reject(new TypeError(`empty route: ${name}`));
    return answer instanceof Response ? Promise.resolve(answer.clone()) : answer;
  });
  return names;
}

function list(name: 'Watchlist' | 'Portfolio'): HTMLElement {
  return screen.getByRole('list', { name });
}

function items(name: 'Watchlist' | 'Portfolio'): string[] {
  return Array.from(list(name).children).map((item) =>
    item.textContent.startsWith('Removed.')
      ? 'Removed.'
      : (item.querySelector('a')?.textContent ?? ''),
  );
}

function problemOf(status: number, slug: string, title: string, instance: string) {
  return problemResponse({
    type: `${BASE}${slug}`,
    title,
    status,
    instance,
    request_id: `req-edge-${String(status)}`,
  });
}

/** A failure answer of each status the brief names, for the given path. */
function failureOf(status: number, instance: string): Response {
  if (status === 429) return problemResponse(tooManyRequestsProblem(instance));
  if (status === 500) return problemResponse(serverErrorProblem(instance));
  if (status === 404) return problemOf(404, 'not-found', 'Not Found', instance);
  return problemOf(422, 'validation-failed', 'Validation Failed', instance);
}

const STATUSES = [404, 422, 429, 500] as const;
const watchWithout = (gone: string) =>
  watchlistBody(watchlistItems.filter((item) => item.variant_key !== gone));
const portfolioWithout = (gone: string) =>
  portfolioBody(workedHoldings.filter((holding) => holding.variant_key !== gone));

function notBody(): void {
  expect(document.activeElement).not.toBe(document.body);
  expect(document.activeElement).not.toBeNull();
}

describe('Undo restores exactly what was removed', () => {
  it.each([1, 9999])(
    'a holding of %i comes back with %i, sent as that number',
    async (quantity) => {
      const held = holdingOf({ quantity, value: '968.91' });
      const calls = await open('/portfolio', {
        [PORTFOLIO]: [
          jsonResponse(portfolioBody([held])),
          jsonResponse(portfolioBody([])),
          jsonResponse(portfolioBody([held])),
        ],
        [`DELETE /v1/portfolio/${CHARIZARD}`]: rawResponse(204, null),
        [`PUT /v1/portfolio/${CHARIZARD}`]: jsonResponse({
          data: { variant_key: held.variant_key, quantity },
        }),
      });
      fireEvent.click(within(list('Portfolio')).getByRole('button', { name: /^Remove Charizard/ }));
      const undo = await screen.findByRole('button', { name: /^Undo removing Charizard/ });
      await waitFor(() => {
        expect(document.activeElement).toBe(undo);
      });
      fireEvent.click(undo);
      await waitFor(() => {
        expect(within(list('Portfolio')).getByText(`Quantity ${String(quantity)}`)).toBeTruthy();
      });
      expect(sentBody(calls.find((call) => call.key === `PUT /v1/portfolio/${CHARIZARD}`))).toEqual(
        {
          quantity,
        },
      );
      expect(items('Portfolio')).toEqual(['Charizard']);
      expect(document.activeElement).toBe(list('Portfolio').children[0]?.querySelector('a'));
    },
  );

  it('Undo puts back the saved quantity, not the unsaved number typed into the form before Remove', async () => {
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
    const row = list('Portfolio').children[2] as HTMLElement;
    fireEvent.change(within(row).getByLabelText('Quantity owned'), { target: { value: '77' } });
    fireEvent.click(within(row).getByRole('button', { name: /^Remove Chimecho/ }));
    fireEvent.click(await screen.findByRole('button', { name: /^Undo removing Chimecho/ }));
    await waitFor(() => {
      expect(items('Portfolio')).toEqual(['Charizard', 'Charizard', 'Chimecho']);
    });
    expect(sentBody(calls.find((call) => call.key === `PUT /v1/portfolio/${CHIMECHO}`))).toEqual({
      quantity: 3,
    });
    // The restored row opens on the saved quantity, not the discarded draft.
    const restored = list('Portfolio').children[2] as HTMLElement;
    expect(within(restored).getByLabelText<HTMLInputElement>('Quantity owned').value).toBe('3');
  });

  it('a double press on Undo sends one write', async () => {
    const held = deferred();
    const calls = stubDeferred({
      'GET /v1/me': [jsonResponse(signedInMe)],
      [WATCHLIST]: [
        jsonResponse(watchlistBody()),
        jsonResponse(watchWithout('base1-4:holo:unlimited:-')),
        jsonResponse(watchlistBody()),
      ],
      [`DELETE /v1/watchlist/${CHARIZARD}`]: [rawResponse(204, null)],
      [`PUT /v1/watchlist/${CHARIZARD}`]: [held.promise],
    });
    window.history.replaceState(null, '', '/watchlist');
    render(<App />);
    await screen.findByRole('list', { name: 'Watchlist' });
    await settle();
    fireEvent.click(
      within(list('Watchlist')).getByRole('button', { name: /^Remove Charizard, holo, unlimited/ }),
    );
    const undo = await screen.findByRole('button', { name: /^Undo removing Charizard/ });
    fireEvent.click(undo);
    fireEvent.click(undo);
    fireEvent.click(undo);
    await settle();
    expect(undo.getAttribute('aria-disabled')).toBe('true');
    expect(document.activeElement).toBe(undo);
    held.release(jsonResponse({ data: { variant_key: 'base1-4:holo:unlimited:-' } }));
    await waitFor(() => {
      expect(items('Watchlist')).toEqual(['Charizard', 'Charizard', 'Chimecho']);
    });
    expect(calls.filter((name) => name === `PUT /v1/watchlist/${CHARIZARD}`)).toHaveLength(1);
  });
});

describe('a watchlist row with an alert: the second write of Undo fails', () => {
  it('brings the row back without its alert, says so on the row, and setting it again there finishes', async () => {
    const alerted = watchItemOf({ alert_pct: 10 });
    const calls = await open('/watchlist', {
      [WATCHLIST]: [
        jsonResponse(watchlistBody([alerted])),
        jsonResponse(watchlistBody([])),
        jsonResponse(watchlistBody([watchItemOf({ alert_pct: null })])),
        jsonResponse(watchlistBody([alerted])),
      ],
      [`DELETE /v1/watchlist/${CHARIZARD}`]: rawResponse(204, null),
      [`PUT /v1/watchlist/${CHARIZARD}`]: jsonResponse({
        data: { variant_key: alerted.variant_key },
      }),
      [`PUT /v1/alerts/${CHARIZARD}`]: [
        problemResponse(serverErrorProblem(`/v1/alerts/${CHARIZARD}`)),
        jsonResponse({ data: { variant_key: alerted.variant_key, alert_pct: 10 } }),
      ],
    });
    fireEvent.click(within(list('Watchlist')).getByRole('button', { name: /^Remove Charizard/ }));
    const undo = await screen.findByRole('button', { name: /^Undo removing Charizard/ });
    fireEvent.click(undo);
    await screen.findByText(
      'Back on your watchlist. Its alert could not be put back; set it again.',
    );
    const line = list('Watchlist').children[0] as HTMLElement;
    expect(line.textContent).not.toContain('Undo did not go through.');
    expect(within(line).getByText('Alert: off')).toBeTruthy();
    await waitFor(() => {
      expect(document.activeElement).toBe(line.querySelector('a, button, input'));
    });
    for (const word of FORBIDDEN_WORDS) expect(line.textContent).not.toMatch(word);

    fireEvent.change(
      within(line).getByRole('combobox', { name: /^Alert threshold for Charizard/ }),
      {
        target: { value: '10' },
      },
    );
    fireEvent.click(within(line).getByRole('button', { name: /^Save the alert for Charizard/ }));
    await screen.findByText('Alert: at 10%');
    const writes = calls.map((call) => call.key).filter((key) => key.startsWith('PUT '));
    expect(writes).toEqual([
      `PUT /v1/watchlist/${CHARIZARD}`,
      `PUT /v1/alerts/${CHARIZARD}`,
      `PUT /v1/alerts/${CHARIZARD}`,
    ]);
    expect(screen.queryByText(/Could not put it back/)).toBeNull();
    expect(items('Watchlist')).toEqual(['Charizard']);
  });
});

describe('a removal that fails keeps the row, its problem and its focus', () => {
  it.each(STATUSES)('watchlist, status %i', async (status) => {
    await open('/watchlist', {
      [WATCHLIST]: jsonResponse(watchlistBody()),
      [`DELETE /v1/watchlist/${CHARIZARD}`]: failureOf(status, `/v1/watchlist/${CHARIZARD}`),
    });
    fireEvent.click(
      within(list('Watchlist')).getByRole('button', { name: /^Remove Charizard, holo, unlimited/ }),
    );
    await screen.findByText(/Could not change your watchlist/);
    expect(items('Watchlist')).toEqual(['Charizard', 'Charizard', 'Chimecho']);
    expect(screen.queryByText('Removed.')).toBeNull();
    expect(screen.queryByRole('button', { name: /^Undo/ })).toBeNull();
    await waitFor(() => {
      expect(document.activeElement).toBe(
        within(list('Watchlist')).getByRole('button', {
          name: /^Remove Charizard, holo, unlimited/,
        }),
      );
    });
    notBody();
  });

  it.each(STATUSES)('portfolio, status %i', async (status) => {
    await open('/portfolio', {
      [PORTFOLIO]: jsonResponse(portfolioBody()),
      [`DELETE /v1/portfolio/${CHIMECHO}`]: failureOf(status, `/v1/portfolio/${CHIMECHO}`),
    });
    fireEvent.click(within(list('Portfolio')).getByRole('button', { name: /^Remove Chimecho/ }));
    await screen.findByText(/Could not change your portfolio/);
    expect(items('Portfolio')).toEqual(['Charizard', 'Charizard', 'Chimecho']);
    expect(screen.queryByText('Removed.')).toBeNull();
    await waitFor(() => {
      expect(document.activeElement).toBe(
        within(list('Portfolio')).getByRole('button', { name: /^Remove Chimecho/ }),
      );
    });
    notBody();
  });

  it('a 401 on the removal leaves no Removed line and no Undo, and nothing crashes', async () => {
    await open('/watchlist', {
      [WATCHLIST]: jsonResponse(watchlistBody()),
      [`DELETE /v1/watchlist/${CHARIZARD}`]: problemResponse({
        type: `${BASE}unauthenticated`,
        title: 'Unauthenticated',
        status: 401,
        instance: `/v1/watchlist/${CHARIZARD}`,
        request_id: 'req-edge-401',
      }),
    });
    fireEvent.click(
      within(list('Watchlist')).getByRole('button', { name: /^Remove Charizard, holo, unlimited/ }),
    );
    await settle();
    expect(screen.queryByText('Removed.')).toBeNull();
    expect(screen.queryByRole('button', { name: /^Undo/ })).toBeNull();
    expect(screen.getByRole('main').textContent).not.toMatch(/\b(NaN|undefined)\b/);
  });
});

describe('an Undo that fails keeps its line, offers Undo again and keeps focus off the body', () => {
  it.each(STATUSES)('watchlist, status %i', async (status) => {
    await open('/watchlist', {
      [WATCHLIST]: [
        jsonResponse(watchlistBody()),
        jsonResponse(watchWithout('base1-4:holo:unlimited:-')),
      ],
      [`DELETE /v1/watchlist/${CHARIZARD}`]: rawResponse(204, null),
      [`PUT /v1/watchlist/${CHARIZARD}`]: failureOf(status, `/v1/watchlist/${CHARIZARD}`),
    });
    fireEvent.click(
      within(list('Watchlist')).getByRole('button', { name: /^Remove Charizard, holo, unlimited/ }),
    );
    fireEvent.click(await screen.findByRole('button', { name: /^Undo removing Charizard/ }));
    await screen.findByText(/Could not put it back/);
    expect(items('Watchlist')).toEqual(['Charizard', 'Removed.', 'Chimecho']);
    const again = screen.getByRole('button', { name: /^Undo removing Charizard/ });
    expect(again.getAttribute('aria-disabled')).toBeNull();
    await waitFor(() => {
      expect(document.activeElement).toBe(again);
    });
  });

  it.each(STATUSES)('portfolio, status %i', async (status) => {
    await open('/portfolio', {
      [PORTFOLIO]: [
        jsonResponse(portfolioBody()),
        jsonResponse(portfolioWithout('ex5-17:reverse:-:-')),
      ],
      [`DELETE /v1/portfolio/${CHIMECHO}`]: rawResponse(204, null),
      [`PUT /v1/portfolio/${CHIMECHO}`]: failureOf(status, `/v1/portfolio/${CHIMECHO}`),
    });
    fireEvent.click(within(list('Portfolio')).getByRole('button', { name: /^Remove Chimecho/ }));
    fireEvent.click(await screen.findByRole('button', { name: /^Undo removing Chimecho/ }));
    await screen.findByText(/Could not put it back/);
    expect(items('Portfolio')).toEqual(['Charizard', 'Charizard', 'Removed.']);
    const again = screen.getByRole('button', { name: /^Undo removing Chimecho/ });
    expect(again.getAttribute('aria-disabled')).toBeNull();
    await waitFor(() => {
      expect(document.activeElement).toBe(again);
    });
  });
});

describe('one line at a time and none left behind', () => {
  it('two removals in flight at once end with exactly one Removed line and neither removed row shown', async () => {
    const first = deferred();
    stubDeferred({
      'GET /v1/me': [jsonResponse(signedInMe)],
      [WATCHLIST]: [
        jsonResponse(watchlistBody()),
        jsonResponse(watchlistBody(watchlistItems.slice(0, 2))),
        jsonResponse(watchlistBody(watchlistItems.slice(0, 1))),
      ],
      [`DELETE /v1/watchlist/${CHIMECHO}`]: [rawResponse(204, null)],
      [`DELETE /v1/watchlist/${CHARIZARD}`]: [first.promise],
    });
    window.history.replaceState(null, '', '/watchlist');
    render(<App />);
    await screen.findByRole('list', { name: 'Watchlist' });
    await settle();
    fireEvent.click(
      within(list('Watchlist')).getByRole('button', { name: /^Remove Charizard, holo, unlimited/ }),
    );
    fireEvent.click(within(list('Watchlist')).getByRole('button', { name: /^Remove Chimecho/ }));
    await screen.findByRole('button', { name: /^Undo removing Chimecho/ });
    await act(async () => {
      first.release(rawResponse(204, null));
      await new Promise((resolve) => setTimeout(resolve, 0));
    });
    await settle();
    expect(screen.getAllByText('Removed.')).toHaveLength(1);
    expect(screen.getAllByRole('button', { name: /^Undo removing/ })).toHaveLength(1);
    const names = items('Watchlist');
    expect(names.filter((name) => name === 'Removed.')).toHaveLength(1);
    expect(names.filter((name) => name === 'Chimecho')).toHaveLength(0);
    notBody();
  });

  it('leaving the page and coming back drops the line: the row is read again and nothing says Removed', async () => {
    const calls = await open('/watchlist', {
      [WATCHLIST]: [
        jsonResponse(watchlistBody()),
        jsonResponse(watchWithout('base1-4:holo:unlimited:-')),
        jsonResponse(watchWithout('base1-4:holo:unlimited:-')),
      ],
      [`DELETE /v1/watchlist/${CHARIZARD}`]: rawResponse(204, null),
    });
    fireEvent.click(
      within(list('Watchlist')).getByRole('button', { name: /^Remove Charizard, holo, unlimited/ }),
    );
    await screen.findByRole('button', { name: /^Undo removing Charizard/ });
    act(() => {
      navigate('/about');
    });
    await screen.findByRole('heading', { level: 1 });
    act(() => {
      navigate('/watchlist');
    });
    await screen.findByRole('list', { name: 'Watchlist' });
    await settle();
    expect(screen.queryByText('Removed.')).toBeNull();
    expect(screen.queryByRole('button', { name: /^Undo/ })).toBeNull();
    expect(items('Watchlist')).toEqual(['Charizard', 'Chimecho']);
    expect(calls.filter((call) => call.key === WATCHLIST).length).toBeGreaterThanOrEqual(2);
  });

  it("Remove A, Remove B: the line is B's, A is gone for good (one line at a time), and focus is on B's Undo", async () => {
    await open('/portfolio', {
      [PORTFOLIO]: [
        jsonResponse(portfolioBody()),
        jsonResponse(portfolioWithout('base1-4:holo:unlimited:-')),
        jsonResponse(
          portfolioBody(
            workedHoldings.filter(
              (holding) =>
                holding.variant_key !== 'base1-4:holo:unlimited:-' &&
                holding.variant_key !== 'ex5-17:reverse:-:-',
            ),
          ),
        ),
      ],
      [`DELETE /v1/portfolio/${CHARIZARD}`]: rawResponse(204, null),
      [`DELETE /v1/portfolio/${CHIMECHO}`]: rawResponse(204, null),
    });
    fireEvent.click(
      within(list('Portfolio')).getByRole('button', { name: /^Remove Charizard, holo, unlimited/ }),
    );
    await screen.findByRole('button', { name: /^Undo removing Charizard/ });
    fireEvent.click(within(list('Portfolio')).getByRole('button', { name: /^Remove Chimecho/ }));
    const undo = await screen.findByRole('button', { name: /^Undo removing Chimecho/ });
    await waitFor(() => {
      expect(document.activeElement).toBe(undo);
    });
    expect(screen.queryByRole('button', { name: /^Undo removing Charizard/ })).toBeNull();
    expect(items('Portfolio').filter((name) => name === 'Removed.')).toHaveLength(1);
  });
});

describe('Saved. after a quantity save', () => {
  it('is one status line, goes with the next write, and never rides along onto a restored row', async () => {
    const after = portfolioBody(
      workedHoldings.map((holding) =>
        holding.variant_key === 'ex5-17:reverse:-:-' ? { ...holding, quantity: 4 } : holding,
      ),
    );
    await open('/portfolio', {
      [PORTFOLIO]: [
        jsonResponse(portfolioBody()),
        jsonResponse(after),
        jsonResponse(portfolioWithout('ex5-17:reverse:-:-')),
        jsonResponse(after),
      ],
      [`PUT /v1/portfolio/${CHIMECHO}`]: jsonResponse({
        data: { variant_key: 'ex5-17:reverse:-:-', quantity: 4 },
      }),
      [`DELETE /v1/portfolio/${CHIMECHO}`]: rawResponse(204, null),
    });
    const row = () => list('Portfolio').children[2] as HTMLElement;
    fireEvent.change(within(row()).getByLabelText('Quantity owned'), { target: { value: '4' } });
    fireEvent.click(within(row()).getByRole('button', { name: /^Save/ }));
    await within(row()).findByText('Saved.');
    expect(screen.getAllByText('Saved.')).toHaveLength(1);
    expect(within(row()).getByText('Saved.').closest('[role="status"]')).not.toBeNull();

    fireEvent.click(within(row()).getByRole('button', { name: /^Remove Chimecho/ }));
    fireEvent.click(await screen.findByRole('button', { name: /^Undo removing Chimecho/ }));
    await waitFor(() => {
      expect(items('Portfolio')).toEqual(['Charizard', 'Charizard', 'Chimecho']);
    });
    expect(screen.queryByText('Saved.')).toBeNull();
  });
});
