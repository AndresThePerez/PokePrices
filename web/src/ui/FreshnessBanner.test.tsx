// The stale-prices banner: one line in the shell, over GET /v1/prices/freshness, on the pages that
// show a price. It says how old the newest collected prices are when the answer is out of its SLO,
// and nothing while the answer is within it, while it loads, when it fails or when it is not the
// documented shape.
import { act, cleanup, render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, onTestFinished, vi } from 'vitest';
import App from '../App';
import { ACCOUNT_KEY, ME_KEY } from '../account/session';
import { freshnessKey } from '../queryKeys';
import {
  FORBIDDEN_WORDS,
  answerMovers,
  cardBody,
  freshnessBody,
  historyFor,
  isFreshnessUrl,
  isHistoryUrl,
  isMoversUrl,
  jsonResponse,
  rawResponse,
  serverErrorProblem,
  problemResponse,
  stubFetch,
} from '../test/fixtures';

const BANNER =
  'Prices are out of date: the newest collected prices are as of 2026-10-13, 950.3 hours old.';

function visit(path: string): void {
  window.history.replaceState(null, '', path);
}

/** Answers the freshness request with `freshness`, the movers, the card and its history. */
function stubWith(freshness: () => Response | Error) {
  return stubFetch((url) => {
    if (isFreshnessUrl(url)) return freshness();
    if (isMoversUrl(url)) return answerMovers(url);
    if (isHistoryUrl(url)) return jsonResponse(historyFor(cardBody));
    return jsonResponse(cardBody);
  });
}

function banners(): HTMLElement[] {
  return screen.queryAllByText(/^Prices are out of date/);
}

afterEach(() => {
  cleanup();
  visit('/');
});

describe('the stale-prices banner', () => {
  it('says how old the newest prices are, once, when the answer is out of its SLO', async () => {
    const calls = stubWith(() => jsonResponse(freshnessBody()));
    visit('/');
    render(<App />);
    expect(await screen.findByText(BANNER)).toBeTruthy();
    expect(banners()).toHaveLength(1);
    expect(calls.filter((call) => isFreshnessUrl(call.url))).toHaveLength(1);
  });

  it('keeps the one decimal of a whole hour and names the day the answer gives', async () => {
    stubWith(() => jsonResponse(freshnessBody({ day: '2026-10-12', age_hours: 26 })));
    visit('/');
    render(<App />);
    expect(
      await screen.findByText(
        'Prices are out of date: the newest collected prices are as of 2026-10-12, 26.0 hours old.',
      ),
    ).toBeTruthy();
  });

  it('shows on the card page and the movers board, outside their main region, and the text passes the forbidden-words list', async () => {
    for (const path of ['/card/ex10-%3F', '/movers']) {
      stubWith(() => jsonResponse(freshnessBody()));
      visit(path);
      render(<App />);
      const banner = await screen.findByText(BANNER);
      expect(banners(), path).toHaveLength(1);
      expect(screen.getByRole('main').contains(banner), path).toBe(false);
      for (const word of FORBIDDEN_WORDS)
        expect(banner.textContent, String(word)).not.toMatch(word);
      cleanup();
    }
  });

  it('shows nothing when the answer is within its SLO', async () => {
    const calls = stubWith(() => jsonResponse(freshnessBody({ within_slo: true, age_hours: 3.5 })));
    visit('/');
    render(<App />);
    await waitFor(() => {
      expect(calls.some((call) => isFreshnessUrl(call.url))).toBe(true);
    });
    await screen.findByRole('link', { name: 'See all movers' });
    await act(async () => {
      await Promise.resolve();
    });
    expect(banners()).toHaveLength(0);
    expect(document.body.textContent).not.toMatch(/out of date: the newest/);
  });

  it('shows nothing while the answer loads', async () => {
    let asked = 0;
    // The freshness request never settles inside this test.
    vi.stubGlobal('fetch', (input: string | URL): Promise<Response> => {
      const url = String(input);
      if (isFreshnessUrl(url)) {
        asked += 1;
        return new Promise<Response>(() => undefined);
      }
      const answer = answerMovers(url);
      return answer instanceof Response ? Promise.resolve(answer) : Promise.reject(answer);
    });
    onTestFinished(() => {
      vi.unstubAllGlobals();
    });
    visit('/');
    render(<App />);
    await screen.findByRole('link', { name: 'See all movers' });
    expect(asked).toBe(1);
    expect(banners()).toHaveLength(0);
  });

  it('shows nothing when the request fails, the server errs or the answer is not the documented shape', async () => {
    const answers: readonly (readonly [string, () => Response | Error])[] = [
      ['a network failure', () => new TypeError('Failed to fetch')],
      ['a 500 problem', () => problemResponse(serverErrorProblem('/v1/prices/freshness'))],
      ['an HTML 502', () => rawResponse(502, '<html>Bad Gateway</html>', 'text/html')],
      ['no data', () => jsonResponse({ within_slo: false })],
      ['within_slo a string', () => jsonResponse(freshnessBody({ within_slo: 'false' as never }))],
      ['age_hours a string', () => jsonResponse(freshnessBody({ age_hours: '950.3' as never }))],
      [
        'no day yet',
        () => jsonResponse(freshnessBody({ day: null, age_hours: null, as_of: null })),
      ],
    ];
    for (const [name, answer] of answers) {
      const calls = stubWith(answer);
      visit('/');
      render(<App />);
      await screen.findByRole('link', { name: 'See all movers' });
      await waitFor(() => {
        expect(
          calls.some((call) => isFreshnessUrl(call.url)),
          name,
        ).toBe(true);
      });
      await act(async () => {
        await new Promise((resolve) => setTimeout(resolve, 0));
      });
      expect(banners(), name).toHaveLength(0);
      cleanup();
    }
  });

  it('keys the answer as a public query, apart from every per-user key', () => {
    expect(freshnessKey()).toEqual(['price-freshness']);
    expect(freshnessKey()[0]).not.toBe(ACCOUNT_KEY[0]);
    expect(freshnessKey()[0]).not.toBe(ME_KEY[0]);
  });

  it('sends no freshness request on a page that shows no price', async () => {
    const calls = stubFetch(() => new Error('no request expected'));
    visit('/no-such-page');
    render(<App />);
    await screen.findByRole('heading', { level: 1, name: 'Page not found' });
    expect(calls.filter((call) => isFreshnessUrl(call.url))).toHaveLength(0);
  });

  it('shows no banner and asks nothing on search, sign-in and account pages, even when prices are out of date', async () => {
    for (const path of ['/search', '/sign-in', '/account']) {
      const calls = stubWith(() => jsonResponse(freshnessBody()));
      visit(path);
      render(<App />);
      await screen.findByRole('heading', { level: 1 });
      await act(async () => {
        await new Promise((resolve) => setTimeout(resolve, 0));
      });
      expect(
        calls.filter((call) => isFreshnessUrl(call.url)),
        path,
      ).toHaveLength(0);
      expect(banners(), path).toHaveLength(0);
      cleanup();
    }
  });

  it('shows the banner on the watchlist and the portfolio, once each', async () => {
    for (const path of ['/watchlist', '/portfolio']) {
      const calls = stubWith(() => jsonResponse(freshnessBody()));
      visit(path);
      render(<App />);
      expect(await screen.findByText(BANNER), path).toBeTruthy();
      expect(banners(), path).toHaveLength(1);
      expect(
        calls.filter((call) => isFreshnessUrl(call.url)),
        path,
      ).toHaveLength(1);
      cleanup();
    }
  });
});
