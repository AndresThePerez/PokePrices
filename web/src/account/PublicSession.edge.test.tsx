// Adversarial tests for the public page's session rule: it asks GET /v1/me only when the browser holds
// the exact XSRF-TOKEN cookie (a name that merely resembles it, or an empty value, does not count),
// in every state of the page (loading, error, not found), and a /me that is slow, fails (500, network
// error, wrong shape) or answers 401 never costs the public page its prices, never reads a list and
// never shows the estimate.
import { act, cleanup, render, screen, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import App from '../App';
import {
  answerMovers,
  cardNotFoundProblem,
  clearXsrfCookie,
  estimateOf,
  freshnessBody,
  historyFor,
  isHistoryUrl,
  isMoversUrl,
  jsonResponse,
  portfolioBody,
  pricedCardBody,
  problemResponse,
  searchPage,
  serverErrorProblem,
  setXsrfCookie,
  signedInMe,
  stubFetch,
  unauthenticatedProblem,
  watchlistBody,
} from '../test/fixtures';

vi.mock('lightweight-charts', () => import('../test/lightweightChartsFake'));

const ACCOUNT = /^\/(v1\/(me|watchlist|portfolio|alerts|webhooks)|sanctum|login|logout)/;
const PUBLIC = ['/', '/movers', '/search?q=unown', '/card/ex10-%3F'] as const;
const NEVER = new Promise<Response>(() => undefined);

const LOOKALIKES = [
  'XXSRF-TOKEN=abc',
  'XSRF-TOKEN2=abc',
  'XSRF-TOKENS=abc',
  'xsrf-token=abc',
  'Xsrf-Token=abc',
  'X-XSRF-TOKEN=abc',
  '_XSRF-TOKEN=abc',
  'XSRF-TOKEN=',
  'other=XSRF-TOKEN%3Dabc',
  'other=;XSRF-TOKEN',
] as const;

afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
  clearXsrfCookie();
  for (const cookie of LOOKALIKES) {
    const name = cookie.split('=')[0] ?? '';
    document.cookie = `${name}=; path=/; expires=Thu, 01 Jan 1970 00:00:00 GMT`;
  }
  document.cookie = 'other=; path=/; expires=Thu, 01 Jan 1970 00:00:00 GMT';
  window.localStorage.clear();
  window.history.replaceState(null, '', '/');
});

async function settle(): Promise<void> {
  for (let round = 0; round < 6; round += 1) {
    await act(async () => {
      await new Promise((resolve) => setTimeout(resolve, 0));
    });
  }
}

type Reply = (url: string) => Response | Error;

/** The public pages' own answers; `me` answers GET /v1/me, a list read is an error so a stray one shows. */
function publicAnswers(me: Reply | null, card: Reply | null = null): Reply {
  return (url) => {
    if (url === '/v1/me') return me === null ? new Error('no /v1/me expected') : me(url);
    if (url === '/v1/watchlist') return jsonResponse(watchlistBody([]));
    if (url === '/v1/portfolio') return jsonResponse(portfolioBody());
    if (isMoversUrl(url)) return answerMovers(url);
    if (url === '/v1/prices/freshness') return jsonResponse(freshnessBody());
    if (isHistoryUrl(url)) return jsonResponse(historyFor(pricedCardBody));
    if (url.startsWith('/v1/cards?')) return jsonResponse(searchPage);
    if (url.startsWith('/v1/cards/'))
      return card === null ? jsonResponse(pricedCardBody) : card(url);
    return new Error(`no request expected: ${url}`);
  };
}

async function open(path: string, reply: Reply): Promise<string[]> {
  const calls = stubFetch(reply);
  window.history.replaceState(null, '', path);
  render(<App />);
  await settle();
  return calls.map((call) => call.url);
}

function estimateLink(): HTMLElement | null {
  return within(screen.getByRole('banner')).queryByRole('link', { name: /^Portfolio estimate/ });
}

const signedIn = () => jsonResponse({ data: { ...signedInMe.data, portfolio: estimateOf() } });

describe('a name that resembles XSRF-TOKEN is not the cookie', () => {
  it.each(LOOKALIKES.flatMap((cookie) => PUBLIC.map((path) => [cookie, path] as const)))(
    'with %s, %s sends no account request and shows no estimate',
    async (...row) => {
      const [cookie, path] = row;
      document.cookie = `${cookie}; path=/`;
      const urls = await open(path, publicAnswers(signedIn));
      expect(urls.filter((url) => ACCOUNT.test(url))).toEqual([]);
      expect(urls.length).toBeGreaterThan(0);
      expect(estimateLink()).toBeNull();
    },
  );

  it('still counts the real cookie when other cookies sit before and after it', async () => {
    document.cookie = 'xsrf-token=lower; path=/';
    document.cookie = 'theme=dark; path=/';
    setXsrfCookie();
    document.cookie = 'zzz=1; path=/';
    const urls = await open('/', publicAnswers(signedIn));
    expect(urls.filter((url) => ACCOUNT.test(url))).toEqual(['/v1/me']);
    document.cookie = 'theme=; path=/; expires=Thu, 01 Jan 1970 00:00:00 GMT';
    document.cookie = 'zzz=; path=/; expires=Thu, 01 Jan 1970 00:00:00 GMT';
  });
});

describe('without the cookie, in every state of the page, no session request is sent', () => {
  const NOT_FOUND = () => problemResponse(cardNotFoundProblem);

  it('a card that is not found', async () => {
    const urls = await open('/card/ex10-%3F', publicAnswers(null, NOT_FOUND));
    expect(urls.filter((url) => ACCOUNT.test(url))).toEqual([]);
    expect(document.cookie).toBe('');
  });

  it('a card whose read fails with a 500', async () => {
    const urls = await open(
      '/card/ex10-%3F',
      publicAnswers(null, () => problemResponse(serverErrorProblem('/v1/cards/ex10-%3F'))),
    );
    expect(urls.filter((url) => ACCOUNT.test(url))).toEqual([]);
  });

  it('a card whose read never answers (loading)', async () => {
    const calls: string[] = [];
    vi.stubGlobal('fetch', (input: string | URL) => {
      calls.push(String(input));
      return NEVER;
    });
    window.history.replaceState(null, '', '/card/ex10-%3F');
    render(<App />);
    await settle();
    expect(calls.length).toBeGreaterThan(0);
    expect(calls.filter((url) => ACCOUNT.test(url))).toEqual([]);
  });

  it('a search with no result and a movers board that fails', async () => {
    const empty = await open('/search?q=zzz', publicAnswers(null, null));
    expect(empty.filter((url) => ACCOUNT.test(url))).toEqual([]);
    cleanup();
    const movers = await open('/movers', (url) =>
      isMoversUrl(url) ? new Error('network down') : publicAnswers(null)(url),
    );
    expect(movers.filter((url) => ACCOUNT.test(url))).toEqual([]);
  });
});

describe('with the cookie, a session read that fails leaves the public page its prices', () => {
  const FAILURES: readonly (readonly [string, Reply])[] = [
    ['a 500', () => problemResponse(serverErrorProblem('/v1/me'))],
    ['a network error', () => new Error('network down')],
    ['a wrong shape (email is a number)', () => jsonResponse({ data: { email: 5 } })],
    ['an empty object', () => jsonResponse({})],
    [
      'a portfolio whose total is a number',
      () =>
        jsonResponse({
          data: { ...signedInMe.data, portfolio: { ...estimateOf(), total_value: 1047.93 } },
        }),
    ],
  ];

  it.each(FAILURES)(
    'the card page after %s: prices stay, no list read, no estimate',
    async (...row) => {
      const [, me] = row;
      setXsrfCookie();
      const urls = await open('/card/ex10-%3F', publicAnswers(me));
      expect(urls.filter((url) => ACCOUNT.test(url))).toEqual(['/v1/me']);
      const prices = screen.getByRole('region', { name: 'Prices' });
      expect(prices.textContent).toMatch(/\$\d/);
      expect(within(prices).getAllByRole('button').length).toBeGreaterThan(0);
      expect(screen.getByRole('heading', { level: 1, name: 'Unown' })).toBeTruthy();
      expect(estimateLink()).toBeNull();
      expect(document.body.textContent).not.toMatch(/\b(NaN|undefined|Infinity)\b/);
    },
  );

  it.each(FAILURES)(
    'home after %s: the page is there, only GET /v1/me beyond its own reads',
    async (...row) => {
      const [, me] = row;
      setXsrfCookie();
      const urls = await open('/', publicAnswers(me));
      expect(urls.filter((url) => ACCOUNT.test(url))).toEqual(['/v1/me']);
      expect(screen.getByRole('main')).toBeTruthy();
      expect(estimateLink()).toBeNull();
      expect(screen.queryByText(/Could not load your account/)).toBeNull();
    },
  );

  it('a slow session read: the card page shows its prices at once and the estimate only when it lands', async () => {
    setXsrfCookie();
    let release: (response: Response) => void = () => undefined;
    const pending = new Promise<Response>((resolve) => {
      release = resolve;
    });
    const answers = publicAnswers(signedIn);
    const calls: string[] = [];
    vi.stubGlobal('fetch', (input: string | URL): Promise<Response> => {
      const url = String(input);
      calls.push(url);
      if (url === '/v1/me') return pending;
      const answer = answers(url);
      return answer instanceof Response ? Promise.resolve(answer) : Promise.reject(answer);
    });
    window.history.replaceState(null, '', '/card/ex10-%3F');
    render(<App />);
    await settle();
    expect(screen.getByRole('region', { name: 'Prices' }).textContent).toMatch(/\$\d/);
    expect(estimateLink()).toBeNull();
    expect(calls).not.toContain('/v1/watchlist');
    expect(calls).not.toContain('/v1/portfolio');
    release(signedIn());
    await settle();
    expect(estimateLink()).not.toBeNull();
    expect(calls.filter((url) => url === '/v1/watchlist')).toHaveLength(1);
    expect(calls.filter((url) => url === '/v1/portfolio')).toHaveLength(1);
  });

  it('a 401 on a card that is not found is still the quiet public page', async () => {
    setXsrfCookie();
    const urls = await open(
      '/card/ex10-%3F',
      publicAnswers(
        () => problemResponse(unauthenticatedProblem),
        () => problemResponse(cardNotFoundProblem),
      ),
    );
    expect(urls.filter((url) => ACCOUNT.test(url))).toEqual(['/v1/me']);
    expect(screen.queryByText(/Request ID req-p4-unauthenticated/)).toBeNull();
  });
});
