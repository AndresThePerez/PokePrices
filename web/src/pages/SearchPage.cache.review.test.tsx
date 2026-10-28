// Adversarial review of search in the query cache (Q1-i5-T04): URLs that build one request share
// one entry and URLs that build two stay apart, an empty q asks nothing, leaving or switching
// searches cancels and never lets a late answer show for the wrong query, a failed poll keeps the
// last good page, a failed or refused search is asked again on a revisit, and a stale entry shows
// at once while it is asked again.
import { act, cleanup, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import App from '../App';
import type { CardSearchPage } from '../api/client';
import { navigate } from '../router';
import {
  jsonResponse,
  problemResponse,
  sprigatitoPromo,
  stubFetch,
  unownQuestion,
  upstreamUnavailableProblem,
} from '../test/fixtures';

function visit(path: string): void {
  window.history.replaceState(null, '', path);
}

function page(card: CardSearchPage['data'][number]): CardSearchPage {
  return {
    data: [card],
    meta: { total: 1, page: 1, pages: 1, page_size: 24, degraded: false, cached: false },
  };
}

/** Lets requests, answers and renders settle with real zero-delay timeouts. */
async function settle(): Promise<void> {
  for (let round = 0; round < 3; round += 1) {
    await act(async () => {
      await new Promise((resolve) => {
        setTimeout(resolve, 0);
      });
    });
  }
}

async function go(path: string): Promise<void> {
  act(() => {
    navigate(path);
  });
  await settle();
}

interface Held {
  readonly url: string;
  readonly signal: AbortSignal | null | undefined;
  readonly answer: (response: Response) => void;
}

/** A fetch whose answers are held until the test gives them; a signal abort rejects only if asked. */
function heldFetch(rejectOnAbort: boolean): Held[] {
  const held: Held[] = [];
  vi.stubGlobal(
    'fetch',
    (input: string | URL, init?: RequestInit) =>
      new Promise<Response>((resolve, reject) => {
        held.push({ url: String(input), signal: init?.signal, answer: resolve });
        if (rejectOnAbort) {
          init?.signal?.addEventListener('abort', () => {
            reject(new DOMException('aborted', 'AbortError'));
          });
        }
      }),
  );
  return held;
}

afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
  visit('/');
});

describe('which inputs share one search entry (Q1-i5-T04 review)', () => {
  it('sends one request for a, a padded, page 1 and every unreadable page, and a second only for another case or page 2', async () => {
    const calls = stubFetch(() => jsonResponse(page(unownQuestion)));
    visit('/search?q=a');
    render(<App />);
    await settle();
    for (const same of [
      '/search?q=%20a%20',
      '/search?q=a&page=1',
      '/search?q=a&page=0',
      '/search?q=a&page=-3',
      '/search?q=a&page=abc',
      '/search?q=a&page=01',
      '/search?q=a&page=1.5',
      '/search?q=a&page=',
    ]) {
      await go(same);
    }
    expect(calls.map((call) => call.url)).toEqual(['/v1/cards?q=a']);
    await go('/search?q=A');
    await go('/search?q=a&page=2');
    expect(calls.map((call) => call.url)).toEqual([
      '/v1/cards?q=a',
      '/v1/cards?q=A',
      '/v1/cards?q=a&page=2',
    ]);
  });

  it('keeps queries with ?, &, #, plus, spaces and unicode apart, each asked once and answered from the cache on a revisit', async () => {
    const calls = stubFetch(() => jsonResponse(page(unownQuestion)));
    const queries = ['a?b&c=d#x', 'a b', 'a+b', 'Pokémon', 'Pokémon', '日本語', '\u{1F600}'];
    const path = (q: string): string => `/search?${new URLSearchParams({ q }).toString()}`;
    visit(path(queries[0] ?? ''));
    render(<App />);
    await settle();
    for (const q of queries.slice(1)) await go(path(q));
    for (const q of [...queries].reverse()) await go(path(q));
    expect(calls.map((call) => call.url)).toEqual(
      queries.map((q) => `/v1/cards?${new URLSearchParams({ q }).toString()}`),
    );
  });

  it('asks nothing for a blank q and leaves the entry a left behind: a, blank, a is one request', async () => {
    const calls = stubFetch(() => jsonResponse(page(unownQuestion)));
    visit('/search?q=a');
    render(<App />);
    await settle();
    for (const blank of ['/search?q=%20%20%09', '/search', '/search?q=&page=3']) {
      await go(blank);
      expect(screen.getByText('Type a card name, then press Search.')).toBeTruthy();
      expect(screen.queryByRole('status')).toBeNull();
      expect(screen.queryByRole('alert')).toBeNull();
    }
    await go('/search?q=a');
    expect(screen.getByRole('status').textContent).toBe('1 card matches “a”');
    expect(calls.map((call) => call.url)).toEqual(['/v1/cards?q=a']);
  });
});

describe('search cancellation and the stale answer (Q1-i5-T04 review)', () => {
  it('cancels the request in flight when the page is left', async () => {
    const held = heldFetch(true);
    visit('/search?q=a');
    render(<App />);
    await settle();
    const first = held.find((request) => request.url === '/v1/cards?q=a');
    expect(first?.signal?.aborted).toBe(false);
    await go('/about-nothing');
    expect(first?.signal?.aborted).toBe(true);
  });

  it('never shows a late answer for a under b, even when the transport ignores the cancel', async () => {
    const held = heldFetch(false);
    visit('/search?q=a');
    render(<App />);
    await settle();
    await go('/search?q=b');
    const [forA, forB] = held;
    if (forA === undefined || forB === undefined) throw new Error('two requests expected');
    expect(forA.url).toBe('/v1/cards?q=a');
    expect(forB.url).toBe('/v1/cards?q=b');
    await act(async () => {
      forB.answer(jsonResponse(page(sprigatitoPromo)));
      await Promise.resolve();
    });
    await settle();
    await act(async () => {
      forA.answer(jsonResponse(page(unownQuestion)));
      await Promise.resolve();
    });
    await settle();
    expect(screen.getByRole('status').textContent).toBe('1 card matches “b”');
    expect(screen.getByText('Sprigatito')).toBeTruthy();
    expect(screen.queryByText('Unown')).toBeNull();
  });
});

describe('failures and the loading state (Q1-i5-T04 review)', () => {
  it('shows the Problem and its Request ID for a failed search, then the results on a revisit that succeeds, with one Searching status in between', async () => {
    let asked = 0;
    const held: Held[] = [];
    vi.stubGlobal(
      'fetch',
      (input: string | URL, init?: RequestInit) =>
        new Promise<Response>((resolve) => {
          const url = String(input);
          held.push({ url, signal: init?.signal, answer: resolve });
          if (url === '/v1/cards?q=a') {
            asked += 1;
            if (asked === 1) resolve(problemResponse(upstreamUnavailableProblem));
          } else {
            resolve(jsonResponse(page(sprigatitoPromo)));
          }
        }),
    );
    visit('/search?q=a');
    render(<App />);
    await settle();
    expect(screen.getByRole('alert').textContent).toBe(
      'Search failed: Upstream UnavailableRequest ID req-t46-upstream-0001',
    );
    await go('/search?q=b');
    await go('/search?q=a');
    expect(held.filter((request) => request.url === '/v1/cards?q=a')).toHaveLength(2);
    expect(screen.getAllByRole('status')).toHaveLength(1);
    expect(screen.getByRole('status').textContent).toBe('Searching for “a”…');
    expect(screen.queryByRole('list', { name: 'Search results' })).toBeNull();
    const second = held.filter((request) => request.url === '/v1/cards?q=a')[1];
    await act(async () => {
      second?.answer(jsonResponse(page(unownQuestion)));
      await Promise.resolve();
    });
    await settle();
    expect(screen.getByRole('status').textContent).toBe('1 card matches “a”');
    expect(screen.queryByRole('alert')).toBeNull();
  });

  it('shows the Problem only for a refused page 2 (404, 422, 503) and brings page 1 back from the cache', async () => {
    for (const status of [404, 422, 503]) {
      cleanup();
      const calls = stubFetch((url) =>
        url.includes('page=2')
          ? problemResponse({ ...upstreamUnavailableProblem, status })
          : jsonResponse({
              ...page(unownQuestion),
              meta: { ...page(unownQuestion).meta, pages: 2, total: 30 },
            }),
      );
      visit('/search?q=unown');
      render(<App />);
      await settle();
      await go('/search?q=unown&page=2');
      expect(screen.getByRole('alert').textContent).toBe(
        'Search failed: Upstream UnavailableRequest ID req-t46-upstream-0001',
      );
      expect(screen.queryByRole('list', { name: 'Search results' })).toBeNull();
      expect(screen.queryByRole('status')).toBeNull();
      await go('/search?q=unown');
      expect(screen.getByRole('list', { name: 'Search results' })).toBeTruthy();
      expect(screen.queryByRole('alert')).toBeNull();
      expect(calls.map((call) => call.url)).toEqual([
        '/v1/cards?q=unown',
        '/v1/cards?q=unown&page=2',
      ]);
    }
  });
});

describe('search polling and staleness with the clock (Q1-i5-T04 review)', () => {
  let visibility: DocumentVisibilityState = 'visible';

  async function advance(ms: number): Promise<void> {
    await act(async () => {
      await vi.advanceTimersByTimeAsync(ms);
    });
    await settle();
  }

  beforeEach(() => {
    vi.useFakeTimers({ toFake: ['setInterval', 'clearInterval', 'Date'] });
    vi.setSystemTime(new Date('2031-02-03T04:05:06Z'));
    visibility = 'visible';
    Object.defineProperty(document, 'visibilityState', {
      configurable: true,
      get: () => visibility,
    });
  });

  afterEach(() => {
    vi.useRealTimers();
    Reflect.deleteProperty(document, 'visibilityState');
  });

  it('keeps the last good results through a failed poll, shows no Problem, takes the next good answer, and asks nothing on a refocus inside the five minutes', async () => {
    let asked = 0;
    const urls: string[] = [];
    stubFetch((url) => {
      urls.push(url);
      asked += 1;
      if (asked === 2) return problemResponse(upstreamUnavailableProblem);
      return jsonResponse(page(asked === 1 ? unownQuestion : sprigatitoPromo));
    });
    visit('/search?q=a');
    render(<App />);
    await settle();
    expect(screen.getByText('Unown')).toBeTruthy();
    await advance(100_000);
    act(() => {
      window.dispatchEvent(new Event('visibilitychange'));
    });
    await settle();
    expect(urls).toHaveLength(1);
    await advance(200_000);
    expect(urls).toHaveLength(2);
    expect(screen.getByText('Unown')).toBeTruthy();
    expect(screen.queryByRole('alert')).toBeNull();
    expect(screen.getAllByRole('status')).toHaveLength(1);
    await advance(300_000);
    expect(urls).toHaveLength(3);
    expect(screen.getByText('Sprigatito')).toBeTruthy();
    expect(screen.queryByText('Unown')).toBeNull();
  });

  it('shows a stale entry at once on a revisit and asks again in the background', async () => {
    const urls: string[] = [];
    let pendingFor: ((response: Response) => void) | null = null;
    vi.stubGlobal('fetch', (input: string | URL) => {
      const url = String(input);
      urls.push(url);
      if (urls.length === 3) {
        return new Promise<Response>((resolve) => {
          pendingFor = resolve;
        });
      }
      return Promise.resolve(
        jsonResponse(page(url.includes('q=a') ? unownQuestion : sprigatitoPromo)),
      );
    });
    visit('/search?q=a');
    render(<App />);
    await settle();
    await advance(200_000);
    await go('/search?q=b');
    await advance(100_001);
    await go('/search?q=a');
    expect(urls).toEqual(['/v1/cards?q=a', '/v1/cards?q=b', '/v1/cards?q=a']);
    expect(screen.getByText('Unown')).toBeTruthy();
    expect(screen.getAllByRole('status')).toHaveLength(1);
    expect(screen.getByRole('status').textContent).toBe('1 card matches “a”');
    expect(pendingFor).not.toBeNull();
  });
});
