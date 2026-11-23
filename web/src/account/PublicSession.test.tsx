// A public price page asks for the session only when the browser already holds the XSRF-TOKEN
// cookie. Without it, home, movers, search and a card page send no account request at all (the
// same list they sent before); with it, each asks GET /v1/me once through the shared ['me'] query
// and, signed in, the header shows the estimate. /about never asks, cookie or not.
import { act, cleanup, render, screen, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import App from '../App';
import {
  answerMovers,
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
  setXsrfCookie,
  signedInMe,
  stubFetch,
  unauthenticatedProblem,
  watchlistBody,
} from '../test/fixtures';
import { holdsSessionCookie } from './session';

vi.mock('lightweight-charts', () => import('../test/lightweightChartsFake'));

const ACCOUNT = /^\/(v1\/(me|watchlist|portfolio|alerts|webhooks)|sanctum|login|logout)/;

afterEach(() => {
  cleanup();
  clearXsrfCookie();
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

function answer(me: Response): (url: string) => Response | Error {
  return (url) => {
    if (url === '/v1/me') return me.clone();
    if (url === '/v1/watchlist') return jsonResponse(watchlistBody([]));
    if (url === '/v1/portfolio') return jsonResponse(portfolioBody());
    if (isMoversUrl(url)) return answerMovers(url);
    if (url === '/v1/prices/freshness') return jsonResponse(freshnessBody());
    if (isHistoryUrl(url)) return jsonResponse(historyFor(pricedCardBody));
    if (url.startsWith('/v1/cards?')) return jsonResponse(searchPage);
    if (url.startsWith('/v1/cards/')) return jsonResponse(pricedCardBody);
    return new Error(`no request expected: ${url}`);
  };
}

async function requestsOf(path: string, me: Response): Promise<string[]> {
  const calls = stubFetch(answer(me));
  window.history.replaceState(null, '', path);
  render(<App />);
  await settle();
  return calls.map((call) => call.url);
}

const PUBLIC = ['/', '/movers', '/search?q=unown', '/card/ex10-%3F'] as const;
const signedIn = () => jsonResponse({ data: { ...signedInMe.data, portfolio: estimateOf() } });

describe('holdsSessionCookie', () => {
  it('is true only for a non-empty XSRF-TOKEN cookie', () => {
    expect(holdsSessionCookie()).toBe(false);
    document.cookie = 'XSRF-TOKENX=1; path=/';
    expect(holdsSessionCookie()).toBe(false);
    document.cookie = 'XSRF-TOKENX=; path=/; expires=Thu, 01 Jan 1970 00:00:00 GMT';
    document.cookie = 'XSRF-TOKEN=; path=/';
    expect(holdsSessionCookie()).toBe(false);
    setXsrfCookie();
    expect(holdsSessionCookie()).toBe(true);
  });
});

describe('without the cookie', () => {
  it.each(PUBLIC)('%s sends no account request and sets no cookie', async (path) => {
    const urls = await requestsOf(path, signedIn());
    expect(urls.filter((url) => ACCOUNT.test(url))).toEqual([]);
    expect(urls.length).toBeGreaterThan(0);
    expect(document.cookie).toBe('');
    expect(
      within(screen.getByRole('banner')).queryByRole('link', { name: /^Portfolio estimate/ }),
    ).toBeNull();
  });
});

describe('with the cookie', () => {
  it.each(PUBLIC)(
    '%s asks GET /v1/me once, after its own requests, and shows the estimate',
    async (path) => {
      setXsrfCookie();
      const without = await (async () => {
        clearXsrfCookie();
        const urls = await requestsOf(path, signedIn());
        cleanup();
        setXsrfCookie();
        return urls;
      })();
      const urls = await requestsOf(path, signedIn());
      expect(urls.filter((url) => url === '/v1/me')).toHaveLength(1);
      // The page's own requests are the same; the session read and, on a card page, the two
      // list reads come in addition.
      const extra = path.startsWith('/card/')
        ? ['/v1/me', '/v1/watchlist', '/v1/portfolio']
        : ['/v1/me'];
      expect(urls.filter((url) => !extra.includes(url))).toEqual(without);
      expect(urls.filter((url) => ACCOUNT.test(url))).toEqual(extra);
      expect(
        await within(screen.getByRole('banner')).findByRole('link', {
          name: /^Portfolio estimate \$1,047\.93/,
        }),
      ).toBeTruthy();
    },
  );

  it.each(PUBLIC)('%s answered 401 is the signed-out page, with no problem block', async (path) => {
    setXsrfCookie();
    const urls = await requestsOf(path, problemResponse(unauthenticatedProblem));
    expect(urls.filter((url) => ACCOUNT.test(url))).toEqual(['/v1/me']);
    expect(screen.queryByText(/Could not load your account/)).toBeNull();
    expect(screen.queryByText(/Request ID req-p4-unauthenticated/)).toBeNull();
    expect(within(screen.getByRole('banner')).getByRole('link', { name: 'Sign in' })).toBeTruthy();
  });

  it('/about never asks, so its cookie sentence stays true', async () => {
    setXsrfCookie();
    const urls = await requestsOf('/about', signedIn());
    expect(urls.filter((url) => ACCOUNT.test(url))).toEqual([]);
  });
});
