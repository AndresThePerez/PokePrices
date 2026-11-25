// The About page's cookie sentences, pinned against what the app asks for: "Opening and reading the
// site sets no cookies. Sending a sign-in, sign-up or password form, or pressing the demo button,
// sets two", and a later visit that still holds the security token asks, on opening a page.
// A signed-out render with no cookie of a price page, the About page or an account page sends no
// session request and no CSRF-cookie request. A later change that makes a page ask for the session
// without the cookie fails here, and the sentence is revisited with it.
import { act, cleanup, render, screen, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import App from '../App';
import {
  answerMovers,
  clearXsrfCookie,
  freshnessBody,
  historyFor,
  isHistoryUrl,
  isMoversUrl,
  jsonResponse,
  pricedCardBody,
  problemResponse,
  rawResponse,
  searchPage,
  stubFetch,
} from '../test/fixtures';

// The card page draws its price chart: jsdom has no canvas, so the shared fake stands in.
vi.mock('lightweight-charts', () => import('../test/lightweightChartsFake'));

const SESSION = '/v1/me';
const CSRF = '/sanctum/csrf-cookie';

const UNAUTHENTICATED = {
  type: 'https://pokeprices.andrestheperez.com/problems/unauthenticated',
  title: 'Unauthenticated',
  status: 401,
  instance: '/v1/me',
  request_id: 'req-edge-cookies-0001',
};

const COOKIE_SENTENCE =
  'Opening and reading the site sets no cookies. Sending a sign-in, sign-up or password form, or pressing the demo button, sets two: a session cookie, which keeps you signed in once you are, and a security token that protects the site’s forms. The security token is not removed when you sign out, so a later visit from a browser that still holds it asks, on opening a page, whether you are signed in.';

const OLD_OPENING = 'Opening a sign-in or account page';

function visit(path: string): void {
  window.history.replaceState(null, '', path);
}

async function settle(): Promise<void> {
  for (let round = 0; round < 6; round += 1) {
    await act(async () => {
      await new Promise((resolve) => setTimeout(resolve, 0));
    });
  }
}

/** A signed-out visitor's answers: the session is a 401, every public read answers. */
function answerSignedOut(url: string): Response | Error {
  if (url === SESSION) return problemResponse(UNAUTHENTICATED);
  if (url === CSRF) return rawResponse(204, null);
  if (isMoversUrl(url)) return answerMovers(url);
  if (url === '/v1/prices/freshness') return jsonResponse(freshnessBody());
  if (isHistoryUrl(url)) return jsonResponse(historyFor(pricedCardBody));
  if (url.startsWith('/v1/cards?')) return jsonResponse(searchPage);
  if (url.startsWith('/v1/cards/')) return jsonResponse(pricedCardBody);
  return new Error('no request expected');
}

async function requestsOf(path: string): Promise<string[]> {
  const calls = stubFetch(answerSignedOut);
  visit(path);
  render(<App />);
  await settle();
  return calls.map((call) => call.url);
}

beforeEach(() => {
  window.localStorage.clear();
});

afterEach(() => {
  cleanup();
  clearXsrfCookie();
  visit('/');
});

describe('the About page states the ruled cookie sentence', () => {
  it('as the first sentences of its privacy paragraph', () => {
    stubFetch(() => new Error('no request expected'));
    visit('/about');
    render(<App />);
    const privacy = within(screen.getByRole('main')).getByRole('region', { name: 'Privacy' });
    expect(privacy.textContent).toContain(COOKIE_SENTENCE);
    expect(privacy.querySelector('p')?.textContent.startsWith(COOKIE_SENTENCE)).toBe(true);
  });

  it('without the sentence that said opening a sign-in or account page sets cookies', () => {
    stubFetch(() => new Error('no request expected'));
    visit('/about');
    render(<App />);
    const text = within(screen.getByRole('main')).getByRole('region', {
      name: 'Privacy',
    }).textContent;
    expect(text).not.toContain(OLD_OPENING);
    expect(text).not.toContain('Browsing prices sets no cookies');
  });
});

describe('browsing prices asks for no session, signed out', () => {
  it.each([
    '/',
    '/movers',
    '/movers?direction=down',
    '/search?q=x',
    '/card/ex10-%3F',
    '/about',
    '/sign-up',
  ])('%s sends no session request and no CSRF-cookie request', async (path) => {
    const urls = await requestsOf(path);
    expect(urls).not.toContain(SESSION);
    expect(urls).not.toContain(CSRF);
    expect(urls.filter((url) => url.startsWith('/sanctum') || url === '/login')).toEqual([]);
  });

  it('the price pages still load their prices, so the pin is not vacuous', async () => {
    expect(await requestsOf('/')).toContain('/v1/movers?direction=up&limit=5');
    cleanup();
    expect(await requestsOf('/card/ex10-%3F')).toContain('/v1/cards/ex10-%3F');
    cleanup();
    expect(await requestsOf('/search?q=x')).toContain('/v1/cards?q=x');
  });

  it('moving between price pages and About in place adds no session request', async () => {
    const calls = stubFetch(answerSignedOut);
    visit('/');
    render(<App />);
    await settle();
    for (const path of ['/movers', '/search?q=x', '/card/ex10-%3F', '/about', '/']) {
      act(() => {
        window.history.pushState(null, '', path);
        window.dispatchEvent(new PopStateEvent('popstate'));
      });
      await settle();
    }
    const urls = calls.map((call) => call.url);
    expect(urls).not.toContain(SESSION);
    expect(urls).not.toContain(CSRF);
  });
});

describe('the sign-in and account pages, signed out with no cookie, ask for nothing', () => {
  it.each(['/sign-in', '/account', '/watchlist', '/portfolio', '/alerts'])(
    '%s sends no session request and no CSRF-cookie request on opening',
    async (path) => {
      const urls = await requestsOf(path);
      expect(urls.filter((url) => url === SESSION)).toEqual([]);
      expect(urls).not.toContain(CSRF);
    },
  );
});
