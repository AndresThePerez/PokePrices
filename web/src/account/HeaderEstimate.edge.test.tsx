// Adversarial tests for the header's portfolio estimate: it follows the portfolio the visitor changed
// (a Remove, an Undo, a quantity save) without ever showing the figure from before the change, it is
// gone the moment the session ends and is never the previous account's under the next one, and an
// answer that is missing the estimate or holds odd figures shows nothing misleading.
import { act, cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import App from '../App';
import { navigate } from '../router';
import {
  FORBIDDEN_WORDS,
  answerMovers,
  clearXsrfCookie,
  demoMe,
  estimateOf,
  freshnessBody,
  isFreshnessUrl,
  isMoversUrl,
  jsonResponse,
  portfolioBody,
  problemResponse,
  rawResponse,
  setXsrfCookie,
  signedInMe,
  unauthenticatedProblem,
  watchlistBody,
  workedHoldings,
  type Estimate,
} from '../test/fixtures';

const BEFORE = '$1,047.93';
const AFTER_REMOVE = '$968.91';

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

type Answer = Response | Promise<Response> | (() => Response);

/**
 * A fetch stub keyed by `METHOD url`: each key answers its entries in order (the last repeats, and an
 * entry may be a promise); the CSRF cookie request answers 204 and sets the cookie; a movers or
 * freshness read is answered as a public page needs; anything else is a network failure.
 */
function stub(routes: Readonly<Record<string, readonly Answer[]>>): string[] {
  const names: string[] = [];
  const used = new Map<string, number>();
  vi.stubGlobal('fetch', (input: string | URL, init?: RequestInit): Promise<Response> => {
    const url = String(input);
    const name = `${init?.method ?? 'GET'} ${url}`;
    names.push(name);
    if (name === 'GET /sanctum/csrf-cookie') {
      setXsrfCookie();
      return Promise.resolve(rawResponse(204, null));
    }
    if (isMoversUrl(url)) {
      const movers = answerMovers(url);
      return movers instanceof Response ? Promise.resolve(movers) : Promise.reject(movers);
    }
    if (isFreshnessUrl(url)) return Promise.resolve(jsonResponse(freshnessBody()));
    const answers = routes[name];
    if (answers === undefined) return Promise.reject(new TypeError(`no request expected: ${name}`));
    const index = used.get(name) ?? 0;
    used.set(name, index + 1);
    const answer = answers[Math.min(index, answers.length - 1)];
    if (answer === undefined) return Promise.reject(new TypeError(`empty route: ${name}`));
    if (typeof answer === 'function') return Promise.resolve(answer());
    return answer instanceof Response ? Promise.resolve(answer.clone()) : answer;
  });
  return names;
}

const NEVER = new Promise<Response>(() => undefined);

const meOf = (estimate: Estimate, base: typeof signedInMe | typeof demoMe = signedInMe) =>
  jsonResponse({ data: { ...base.data, portfolio: estimate } });

function banner(): HTMLElement {
  return screen.getByRole('banner');
}

function estimateText(): string | null {
  const link = within(banner()).queryByRole('link', { name: /^Portfolio estimate/ });
  return link === null ? null : link.textContent;
}

/** The worked portfolio once the Chimecho holding (3 × 26.34) is gone. */
const portfolioAfterRemove = portfolioBody(
  workedHoldings.filter((holding) => holding.variant_key !== 'ex5-17:reverse:-:-'),
  {
    count: 2,
    total_value: '968.91',
    priced: 1,
    unpriced: 1,
    change: '18.91',
    change_pct: '1.99',
    first_day: '2026-10-12',
    last_day: '2026-10-12',
  },
);

async function openPortfolio(routes: Readonly<Record<string, readonly Answer[]>>) {
  const names = stub({
    'GET /v1/me': [meOf(estimateOf()), NEVER],
    'GET /v1/watchlist': [jsonResponse(watchlistBody([]))],
    ...routes,
  });
  window.history.replaceState(null, '', '/portfolio');
  render(<App />);
  await screen.findByRole('list', { name: 'Portfolio' });
  await settle();
  return names;
}

describe('the header follows the portfolio, never the figure from before a change', () => {
  it('after a Remove: the estimate of the list read after it (and a session that never answers again)', async () => {
    const names = await openPortfolio({
      'GET /v1/portfolio': [jsonResponse(portfolioBody()), jsonResponse(portfolioAfterRemove)],
      'DELETE /v1/portfolio/ex5-17%3Areverse%3A-%3A-': [rawResponse(204, null)],
    });
    fireEvent.click(screen.getByRole('button', { name: /^Remove Chimecho/ }));
    await screen.findByRole('button', { name: /^Undo removing Chimecho/ });
    await settle();
    act(() => {
      navigate('/watchlist');
    });
    await settle();
    const text = estimateText();
    expect(text).not.toBeNull();
    expect(text).toContain(`Portfolio estimate ${AFTER_REMOVE}`);
    expect(text).not.toContain(BEFORE);
    expect(names.filter((name) => name === 'GET /v1/portfolio')).toHaveLength(2);
  });

  it("after Remove then Undo: the estimate is back to the restored portfolio's", async () => {
    await openPortfolio({
      'GET /v1/portfolio': [
        jsonResponse(portfolioBody()),
        jsonResponse(portfolioAfterRemove),
        jsonResponse(portfolioBody()),
      ],
      'DELETE /v1/portfolio/ex5-17%3Areverse%3A-%3A-': [rawResponse(204, null)],
      'PUT /v1/portfolio/ex5-17%3Areverse%3A-%3A-': [
        jsonResponse({ data: { variant_key: 'ex5-17:reverse:-:-', quantity: 3 } }),
      ],
    });
    fireEvent.click(screen.getByRole('button', { name: /^Remove Chimecho/ }));
    fireEvent.click(await screen.findByRole('button', { name: /^Undo removing Chimecho/ }));
    await waitFor(() => {
      expect(screen.queryByRole('button', { name: /^Undo removing/ })).toBeNull();
    });
    await settle();
    act(() => {
      navigate('/watchlist');
    });
    await settle();
    expect(estimateText()).toContain(`Portfolio estimate ${BEFORE}`);
  });

  it('after a quantity save: the new total, not the one before', async () => {
    const bigger = portfolioBody(
      workedHoldings.map((holding) =>
        holding.variant_key === 'ex5-17:reverse:-:-'
          ? { ...holding, quantity: 4, value: '105.36' }
          : holding,
      ),
      { total_value: '1074.27', change: '17.59', change_pct: '1.70' },
    );
    await openPortfolio({
      'GET /v1/portfolio': [jsonResponse(portfolioBody()), jsonResponse(bigger)],
      'PUT /v1/portfolio/ex5-17%3Areverse%3A-%3A-': [
        jsonResponse({ data: { variant_key: 'ex5-17:reverse:-:-', quantity: 4 } }),
      ],
    });
    const row = screen.getByRole('list', { name: 'Portfolio' }).children[2] as HTMLElement;
    fireEvent.change(within(row).getByLabelText('Quantity owned'), { target: { value: '4' } });
    fireEvent.click(within(row).getByRole('button', { name: /^Save/ }));
    await within(row).findByText('Saved.');
    await settle();
    act(() => {
      navigate('/watchlist');
    });
    await settle();
    const text = estimateText();
    expect(text).toContain('Portfolio estimate $1,074.27');
    expect(text).not.toContain(BEFORE);
  });

  it('is hidden on /portfolio however fresh the figures, and shown on the next page', async () => {
    await openPortfolio({ 'GET /v1/portfolio': [jsonResponse(portfolioBody())] });
    expect(estimateText()).toBeNull();
    act(() => {
      navigate('/watchlist');
    });
    await settle();
    expect(estimateText()).toContain(`Portfolio estimate ${BEFORE}`);
  });
});

describe('the session ends, and the next account is not the last one', () => {
  it("shows nothing after sign-out, and the demo's own figure (never the first account's) after the demo starts", async () => {
    setXsrfCookie();
    const demoEstimate = estimateOf({
      count: 5,
      priced: 5,
      unpriced: 0,
      total_value: '1219.79',
      change: '0.86',
      change_pct: '0.07',
    });
    // Who the server says is signed in; /v1/me answers whoever that is when it is asked.
    let who: 'first' | 'nobody' | 'demo' = 'first';
    stub({
      'GET /v1/me': [
        () => {
          if (who === 'nobody') return problemResponse(unauthenticatedProblem);
          return who === 'first' ? meOf(estimateOf()) : meOf(demoEstimate, demoMe);
        },
      ],
      'POST /logout': [
        () => {
          who = 'nobody';
          return rawResponse(204, null);
        },
      ],
      'POST /v1/demo/session': [
        () => {
          who = 'demo';
          return rawResponse(204, null);
        },
      ],
      'GET /v1/watchlist': [jsonResponse(watchlistBody([]))],
    });
    window.history.replaceState(null, '', '/watchlist');
    render(<App />);
    expect(await within(banner()).findByRole('link', { name: /^Portfolio estimate/ })).toBeTruthy();
    expect(estimateText()).toContain(`Portfolio estimate ${BEFORE}`);

    // From the sign-out on, the first account's figure is never in the header, at any moment.
    const seen: string[] = [];
    const watcher = new MutationObserver(() => {
      seen.push(banner().textContent);
    });
    act(() => {
      navigate('/account');
    });
    const signOut = await screen.findByRole('button', { name: 'Sign out' });
    watcher.observe(document.body, { subtree: true, childList: true, characterData: true });
    fireEvent.click(signOut);
    await waitFor(() => {
      expect(window.location.pathname).toBe('/');
    });
    await settle();
    expect(estimateText()).toBeNull();
    act(() => {
      navigate('/watchlist');
    });
    await screen.findByText('Sign in to keep a watchlist.');
    expect(estimateText()).toBeNull();

    fireEvent.click(await screen.findByRole('button', { name: 'Try the read-only demo' }));
    await within(banner()).findByRole('link', { name: /^Portfolio estimate/ });
    await settle();
    watcher.disconnect();
    expect(estimateText()).toContain('Portfolio estimate $1,219.79');
    expect(estimateText()).not.toContain(BEFORE);
    expect(seen.filter((text) => text.includes(BEFORE))).toEqual([]);
    expect(seen.length).toBeGreaterThan(0);
  });
});

describe('an answer without a usable estimate shows nothing misleading', () => {
  const SHAPES: readonly (readonly [string, () => Response])[] = [
    [
      'no portfolio key at all',
      () => jsonResponse({ data: { ...signedInMe.data, portfolio: undefined } }),
    ],
    [
      'zero holdings',
      () =>
        meOf(
          estimateOf({
            count: 0,
            priced: 0,
            unpriced: 0,
            total_value: null,
            change: null,
            change_pct: null,
            first_day: null,
            last_day: null,
          }),
        ),
    ],
    [
      'every holding unpriced',
      () =>
        meOf(
          estimateOf({
            count: 4,
            priced: 0,
            unpriced: 4,
            total_value: null,
            change: null,
            change_pct: null,
            first_day: null,
            last_day: null,
          }),
        ),
    ],
    ['a priced holding with no day', () => meOf(estimateOf({ first_day: null, last_day: null }))],
    [
      'a close of 0.00 (priced, worth nothing here)',
      () =>
        meOf(
          estimateOf({ total_value: '0.00', change: null, change_pct: null, no_previous_close: 1 }),
        ),
    ],
    ['a change with no percent', () => meOf(estimateOf({ change: '4.00', change_pct: null }))],
    ['a percent with no change', () => meOf(estimateOf({ change: null, change_pct: '1.64' }))],
    ['a negative change', () => meOf(estimateOf({ change: '-1234.50', change_pct: '-0.12' }))],
    [
      'a total of 199979999999800.02',
      () =>
        meOf(
          estimateOf({
            total_value: '199979999999800.02',
            change: '99989999999900.01',
            change_pct: '100.00',
          }),
        ),
    ],
    ['a change of -0.00', () => meOf(estimateOf({ change: '-0.00', change_pct: '0.00' }))],
  ];

  it.each(SHAPES)('%s', async (...row) => {
    const [, answer] = row;
    stub({ 'GET /v1/me': [answer()], 'GET /v1/watchlist': [jsonResponse(watchlistBody([]))] });
    window.history.replaceState(null, '', '/watchlist');
    render(<App />);
    await screen.findByText(/Your watchlist is empty/);
    await settle();
    const text = banner().textContent;
    expect(text).not.toMatch(/\b(NaN|undefined|null|Infinity)\b/);
    expect(text).not.toMatch(/[0-9]e[+-]?[0-9]/i);
    expect(text).not.toMatch(/-\$?0\.00\b/);
    for (const word of FORBIDDEN_WORDS) expect(text).not.toMatch(word);
    const link = estimateText();
    if (link !== null) {
      expect(link).toMatch(/^Portfolio estimate \$[\d,]+\.\d{2}/);
      expect(link).toContain('an estimate at daily-close market prices, not a sale value');
      expect(link).toContain('TCGplayer market prices');
      expect(link).toMatch(/as of|Closes from|\d{4}-\d{2}-\d{2}/);
    }
  });

  it.each([
    ['a number where a string is expected', { total_value: 1047.93 }],
    ['a string where the count is expected', { count: '3' }],
    ['a boolean change', { change: true }],
  ] as const)(
    '%s: the session fails as a problem, not a crash, and no estimate shows',
    async (...row) => {
      const [, bad] = row;
      stub({
        'GET /v1/me': [
          jsonResponse({ data: { ...signedInMe.data, portfolio: { ...estimateOf(), ...bad } } }),
        ],
      });
      window.history.replaceState(null, '', '/watchlist');
      render(<App />);
      await screen.findByText(/Could not load your account/);
      expect(estimateText()).toBeNull();
      expect(document.body.textContent).not.toMatch(/\b(NaN|undefined)\b/);
    },
  );
});

describe('a signed-out visitor', () => {
  it('sees no estimate and sends no list read', async () => {
    const names = stub({ 'GET /v1/me': [problemResponse(unauthenticatedProblem)] });
    window.history.replaceState(null, '', '/watchlist');
    render(<App />);
    await screen.findByText('Sign in to keep a watchlist.');
    expect(estimateText()).toBeNull();
    expect(
      names.filter((name) => name.includes('/v1/portfolio') || name.includes('/v1/watchlist')),
    ).toEqual([]);
  });
});
