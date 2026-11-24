// Reviewer edge tests for the search page's paging polish: the address correction (counted replaces, odd
// page values, encoded and repeated parameters, Back, a stale answer, an error), keyboard focus after a page
// change (plain, modified and keyboard clicks, an absent control, a failing page), and the loading reserve in
// every state. Every fetch is stubbed and answered explicitly.
import { act, cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import App from '../App';
import type { CardSearchPage } from '../api/client';
import { navigate } from '../router';
import {
  FORBIDDEN_WORDS,
  freshnessBody,
  isFreshnessUrl,
  jsonResponse,
  problemResponse,
  searchBodyOf,
  stubFetch,
  unownExclamation,
  unownQuestion,
  upstreamUnavailableProblem,
} from '../test/fixtures';

type Sort = CardSearchPage['meta']['sort'];

function visit(path: string): void {
  window.history.replaceState(null, '', path);
}

beforeEach(() => {
  visit('/');
});

afterEach(() => {
  cleanup();
  vi.restoreAllMocks();
  vi.unstubAllGlobals();
  visit('/');
});

/** One page of a `pages`-page result; `sort` says the order the answer is in. */
function pageOf(page: number, pages = 5, sort: Sort = 'relevance') {
  return searchBodyOf([unownQuestion, unownExclamation], {
    total: pages * 2,
    page,
    pages,
    sort,
    ordered: sort === 'relevance' ? null : pages * 2,
  });
}

function askedPage(url: string): number {
  return Number(new URL(url, 'http://192.0.2.10').searchParams.get('page') ?? '1');
}

/** A fetch answer: the freshness banner's request stays quiet, a search gets the last page when it asks past it. */
function answerSearch(pages: number, sort: Sort = 'relevance') {
  return (url: string): Response | Error =>
    isFreshnessUrl(url)
      ? jsonResponse(freshnessBody({ within_slo: true, age_hours: 3 }))
      : jsonResponse(pageOf(Math.min(askedPage(url), pages), pages, sort));
}

function searchCalls(calls: readonly { readonly url: string }[]): string[] {
  return calls.map((call) => call.url).filter((url) => url.startsWith('/v1/cards'));
}

function heading(): HTMLElement {
  return screen.getByRole('heading', { level: 1, name: 'Search cards' });
}

async function settle(): Promise<void> {
  await act(async () => {
    await new Promise((resolve) => setTimeout(resolve, 40));
  });
}

describe('the address correction settles: one replace, no push, no request more', () => {
  it('replaces the address once, never pushes, and sends one search for a page past the end', async () => {
    const calls = stubFetch(answerSearch(5));
    visit('/search?q=unown&page=9');
    const replace = vi.spyOn(window.history, 'replaceState');
    const push = vi.spyOn(window.history, 'pushState');
    render(<App />);
    await waitFor(() => {
      expect(window.location.search).toBe('?q=unown&page=5');
    });
    await settle();
    expect(replace.mock.calls.map((call) => call[2])).toEqual(['/search?q=unown&page=5']);
    expect(push).not.toHaveBeenCalled();
    expect(searchCalls(calls)).toEqual(['/v1/cards?q=unown&page=9']);
    expect(heading()).toBeTruthy();
  });

  it.each([
    ['zero', 'page=0'],
    ['negative', 'page=-1'],
    ['an exponent', 'page=1e3'],
    ['a decimal', 'page=5.5'],
    ['a leading space', 'page=%205'],
    ['a full-width digit', 'page=%EF%BC%95'],
    ['empty', 'page='],
    ['forty digits', `page=${'9'.repeat(40)}`],
    ['the first of two', 'page=2&page=9'],
  ])(
    'does not correct an address whose page reads as page 1 or in range: %s',
    async (_label, part) => {
      const calls = stubFetch(answerSearch(5));
      visit(`/search?q=unown&${part}`);
      const replace = vi.spyOn(window.history, 'replaceState');
      const push = vi.spyOn(window.history, 'pushState');
      render(<App />);
      await screen.findByRole('list', { name: 'Search results' });
      await settle();
      expect(window.location.search).toBe(`?q=unown&${part}`);
      expect(replace).not.toHaveBeenCalled();
      expect(push).not.toHaveBeenCalled();
      expect(searchCalls(calls)).toHaveLength(1);
    },
  );

  it('corrects a nine-digit page and keeps every other part of the address byte for byte', async () => {
    const calls = stubFetch(answerSearch(5, 'price_asc'));
    visit('/search?a=&q=caf%C3%A9+ex&tag=1&tag=2&page=999999999&sort=price_asc&empty&x=%E2%9C%93');
    const replace = vi.spyOn(window.history, 'replaceState');
    render(<App />);
    await waitFor(() => {
      expect(window.location.search).toBe(
        '?a=&q=caf%C3%A9+ex&tag=1&tag=2&page=5&sort=price_asc&empty&x=%E2%9C%93',
      );
    });
    await settle();
    expect(replace).toHaveBeenCalledTimes(1);
    expect(searchCalls(calls)).toHaveLength(1);
  });

  it('keeps one page part when the address repeats page past the end', async () => {
    stubFetch(answerSearch(5));
    visit('/search?q=unown&page=9&page=2');
    render(<App />);
    await waitFor(() => {
      expect(window.location.search).toBe('?q=unown&page=5');
    });
    await settle();
    expect(window.location.search).toBe('?q=unown&page=5');
  });

  it('corrects a price order past the top 100 and drops page when the last page is the first', async () => {
    stubFetch(answerSearch(5, 'price_desc'));
    visit('/search?q=unown&sort=price_desc&page=4800');
    render(<App />);
    await waitFor(() => {
      expect(window.location.search).toBe('?q=unown&sort=price_desc&page=5');
    });
    const nav = screen.getByRole('navigation', { name: 'Result pages' });
    expect(within(nav).queryByRole('link', { name: 'Next page' })).toBeNull();
    expect(within(nav).getByRole('link', { name: 'Previous page' })).toBeTruthy();
    cleanup();

    stubFetch(answerSearch(1, 'price_asc'));
    visit('/search?q=unown&sort=price_asc&page=4800');
    render(<App />);
    await waitFor(() => {
      expect(window.location.search).toBe('?q=unown&sort=price_asc');
    });
  });

  it('sends Back to the page before the search, not to the address that was corrected', async () => {
    stubFetch(answerSearch(5));
    visit('/');
    render(<App />);
    act(() => {
      navigate('/search?q=unown&page=9');
    });
    await waitFor(() => {
      expect(window.location.search).toBe('?q=unown&page=5');
    });
    await settle();
    act(() => {
      window.history.back();
    });
    await waitFor(() => {
      expect(window.location.pathname).toBe('/');
    });
    expect(window.location.search).toBe('');
  });

  it('leaves the Order control and Previous page working after a correction', async () => {
    const calls = stubFetch(answerSearch(5));
    visit('/search?q=unown&page=9');
    render(<App />);
    await waitFor(() => {
      expect(window.location.search).toBe('?q=unown&page=5');
    });
    const nav = screen.getByRole('navigation', { name: 'Result pages' });
    fireEvent.click(within(nav).getByRole('link', { name: 'Previous page' }));
    await waitFor(() => {
      expect(window.location.search).toBe('?q=unown&page=4');
    });
    await screen.findByText('Page 4 of 5');
    fireEvent.click(screen.getByRole('button', { name: 'Price: high to low' }));
    await waitFor(() => {
      expect(window.location.search).toBe('?q=unown&sort=price_desc');
    });
    await screen.findByRole('list', { name: 'Search results' });
    expect(searchCalls(calls)).toEqual([
      '/v1/cards?q=unown&page=9',
      '/v1/cards?q=unown&page=4',
      '/v1/cards?q=unown&sort=price_desc',
    ]);
  });

  it('does not let a late answer for a query the visitor left rewrite the new address', async () => {
    let late: (response: Response) => void = () => undefined;
    vi.stubGlobal('fetch', (input: string | URL) => {
      const url = String(input);
      if (isFreshnessUrl(url))
        return Promise.resolve(jsonResponse(freshnessBody({ within_slo: true })));
      if (url.includes('q=unown')) {
        return new Promise<Response>((resolve) => {
          late = resolve;
        });
      }
      return Promise.resolve(jsonResponse(pageOf(1, 2)));
    });
    visit('/search?q=unown&page=9');
    render(<App />);
    await screen.findByText('Searching for “unown”…');
    act(() => {
      navigate('/search?q=mew');
    });
    await screen.findByRole('list', { name: 'Search results' });
    await act(async () => {
      late(jsonResponse(pageOf(5)));
      await Promise.resolve();
    });
    await settle();
    expect(window.location.search).toBe('?q=mew');
    expect(screen.getByRole('status').textContent).toBe('4 cards match “mew”');
  });

  it('does not correct while loading, nor on an error answer', async () => {
    stubFetch((url) =>
      isFreshnessUrl(url)
        ? jsonResponse(freshnessBody({ within_slo: true }))
        : problemResponse(upstreamUnavailableProblem),
    );
    visit('/search?q=unown&page=9');
    const replace = vi.spyOn(window.history, 'replaceState');
    render(<App />);
    expect(replace).not.toHaveBeenCalled();
    await screen.findByRole('alert');
    await settle();
    expect(replace).not.toHaveBeenCalled();
    expect(window.location.search).toBe('?q=unown&page=9');
  });

  it('leaves an answer that is not past the end alone even when it names a smaller page', async () => {
    stubFetch((url) =>
      isFreshnessUrl(url)
        ? jsonResponse(freshnessBody({ within_slo: true }))
        : jsonResponse(pageOf(2, 5)),
    );
    visit('/search?q=unown&page=4');
    const replace = vi.spyOn(window.history, 'replaceState');
    render(<App />);
    await screen.findByRole('list', { name: 'Search results' });
    await settle();
    expect(replace).not.toHaveBeenCalled();
  });
});

describe('keyboard focus after a page change', () => {
  it('moves focus to the heading for a keyboard activation of Next and Previous, and never leaves it on the body', async () => {
    stubFetch(answerSearch(5));
    visit('/search?q=unown&page=2');
    render(<App />);
    let nav = await screen.findByRole('navigation', { name: 'Result pages' });
    const next = within(nav).getByRole('link', { name: 'Next page' });
    next.focus();
    // A keyboard Enter on a link is a click with no pointer position (detail 0).
    fireEvent.click(next, { detail: 0 });
    expect(document.activeElement).toBe(heading());
    await screen.findByText('Page 3 of 5');
    expect(document.activeElement).not.toBe(document.body);
    nav = screen.getByRole('navigation', { name: 'Result pages' });
    fireEvent.click(within(nav).getByRole('link', { name: 'Previous page' }), { detail: 0 });
    await screen.findByText('Page 2 of 5');
    expect(document.activeElement).toBe(heading());
  });

  it('keeps focus on the heading on the first and the last page, where one control is absent', async () => {
    stubFetch(answerSearch(3));
    visit('/search?q=unown');
    render(<App />);
    let nav = await screen.findByRole('navigation', { name: 'Result pages' });
    expect(within(nav).queryByRole('link', { name: 'Previous page' })).toBeNull();
    fireEvent.click(within(nav).getByRole('link', { name: 'Next page' }));
    await screen.findByText('Page 2 of 3');
    nav = screen.getByRole('navigation', { name: 'Result pages' });
    fireEvent.click(within(nav).getByRole('link', { name: 'Next page' }));
    await screen.findByText('Page 3 of 3');
    nav = screen.getByRole('navigation', { name: 'Result pages' });
    expect(within(nav).queryByRole('link', { name: 'Next page' })).toBeNull();
    expect(document.activeElement).toBe(heading());
    fireEvent.click(within(nav).getByRole('link', { name: 'Previous page' }));
    expect(document.activeElement).toBe(heading());
  });

  it('moves no focus and swallows no navigation for a modified or middle click', () => {
    stubFetch(answerSearch(5));
    visit('/search?q=unown&page=2');
    render(<App />);
    return screen.findByRole('navigation', { name: 'Result pages' }).then((nav) => {
      const next = within(nav).getByRole('link', { name: 'Next page' });
      for (const init of [
        { ctrlKey: true },
        { metaKey: true },
        { shiftKey: true },
        { altKey: true },
        { button: 1 },
      ]) {
        next.focus();
        // dispatchEvent answers false when the page cancelled the click (the browser's new tab was swallowed).
        expect(fireEvent.click(next, init)).toBe(true);
        expect(document.activeElement).toBe(next);
        expect(window.location.search).toBe('?q=unown&page=2');
      }
    });
  });

  it('keeps the heading out of the tab order and focuses it only on a page change', async () => {
    stubFetch(answerSearch(5));
    visit('/search?q=unown&page=2');
    render(<App />);
    await screen.findByRole('list', { name: 'Search results' });
    expect(document.activeElement).not.toBe(heading());
    expect(heading().tabIndex).toBe(-1);
    expect(heading().getAttribute('tabindex')).toBe('-1');
    expect(document.querySelectorAll('[tabindex="0"]')).not.toContain(heading());

    // A new query and an order change never steal focus.
    const box = screen.getByRole('searchbox', { name: 'Card name' });
    box.focus();
    fireEvent.change(box, { target: { value: 'mew' } });
    fireEvent.submit(box);
    await waitFor(() => {
      expect(window.location.search).toBe('?q=mew');
    });
    await screen.findByRole('list', { name: 'Search results' });
    expect(document.activeElement).not.toBe(heading());
    const order = screen.getByRole('button', { name: 'Price: low to high' });
    order.focus();
    fireEvent.click(order);
    await waitFor(() => {
      expect(window.location.search).toBe('?q=mew&sort=price_asc');
    });
    await screen.findByRole('list', { name: 'Search results' });
    expect(document.activeElement).not.toBe(heading());
  });

  it('keeps focus on the heading while the next page is slow and after it fails', async () => {
    let fail: (response: Response) => void = () => undefined;
    vi.stubGlobal('fetch', (input: string | URL) => {
      const url = String(input);
      if (isFreshnessUrl(url))
        return Promise.resolve(jsonResponse(freshnessBody({ within_slo: true })));
      if (askedPage(url) === 2) {
        return new Promise<Response>((resolve) => {
          fail = resolve;
        });
      }
      return Promise.resolve(jsonResponse(pageOf(1)));
    });
    visit('/search?q=unown');
    render(<App />);
    const nav = await screen.findByRole('navigation', { name: 'Result pages' });
    fireEvent.click(within(nav).getByRole('link', { name: 'Next page' }));
    await screen.findByText('Searching for “unown”…');
    expect(document.activeElement).toBe(heading());
    await act(async () => {
      fail(problemResponse(upstreamUnavailableProblem));
      await Promise.resolve();
    });
    await screen.findByRole('alert');
    expect(document.activeElement).toBe(heading());
    expect(document.activeElement).not.toBe(document.body);
  });

  it('does not move focus when an address is corrected', async () => {
    stubFetch(answerSearch(5));
    visit('/search?q=unown&page=9');
    render(<App />);
    await waitFor(() => {
      expect(window.location.search).toBe('?q=unown&page=5');
    });
    expect(document.activeElement).not.toBe(heading());
  });
});

describe('the loading reserve is there only while a search loads, and nothing renders twice', () => {
  const STALE =
    /Prices are out of date: the newest collected prices are as of 2026-10-13, 950\.3 hours old\./;

  function reserved(): boolean {
    return (screen.getByRole('main').getAttribute('class') ?? '')
      .split(/\s+/)
      .includes('min-h-svh');
  }

  it.each([
    ['a stale-prices banner', true],
    ['no banner', false],
  ])('reserves while loading and not once the answer is in, with %s', async (_label, stale) => {
    let answer: (response: Response) => void = () => undefined;
    vi.stubGlobal('fetch', (input: string | URL) =>
      isFreshnessUrl(String(input))
        ? Promise.resolve(
            jsonResponse(
              stale ? freshnessBody() : freshnessBody({ within_slo: true, age_hours: 3 }),
            ),
          )
        : new Promise<Response>((resolve) => {
            answer = resolve;
          }),
    );
    visit('/search?q=unown');
    render(<App />);
    await screen.findByText('Searching for “unown”…');
    expect(reserved()).toBe(true);
    if (stale) await screen.findByText(STALE);
    expect(screen.getAllByRole('main')).toHaveLength(1);
    expect(screen.getAllByRole('heading', { level: 1 })).toHaveLength(1);
    expect(screen.getAllByRole('status')).toHaveLength(1);
    expect(screen.queryAllByText(STALE)).toHaveLength(stale ? 1 : 0);
    await act(async () => {
      answer(jsonResponse(pageOf(1)));
      await Promise.resolve();
    });
    await screen.findByRole('list', { name: 'Search results' });
    expect(reserved()).toBe(false);
    expect(screen.queryAllByText(STALE)).toHaveLength(stale ? 1 : 0);
    expect(screen.getAllByRole('main')).toHaveLength(1);
    expect(screen.getAllByRole('searchbox')).toHaveLength(1);
    expect(screen.getAllByRole('group', { name: 'Order' })).toHaveLength(1);
    for (const pattern of FORBIDDEN_WORDS)
      expect(screen.getByRole('main').textContent).not.toMatch(pattern);
  });

  it.each([
    [
      'an error',
      () => problemResponse(upstreamUnavailableProblem),
      async () => screen.findByRole('alert'),
    ],
    [
      'an empty result',
      () => jsonResponse(searchBodyOf([], { total: 0, page: 1, pages: 0 })),
      async () => screen.findByText(/^No cards match “unown”\./),
    ],
    [
      'the 32-word 400',
      () =>
        problemResponse({
          type: 'https://pokeprices.andrestheperez.com/problems/validation-failed',
          title: 'Validation Failed',
          status: 422,
          instance: '/v1/cards',
          request_id: 'req-edge-words-0001',
          errors: { q: ['A search can have at most 32 words.'] },
        }),
      async () => screen.findByText('A search can have at most 32 words.'),
    ],
  ])(
    'does not reserve once the search ended in %s, and the banner shows once',
    async (_label, reply, ready) => {
      stubFetch((url) => (isFreshnessUrl(url) ? jsonResponse(freshnessBody()) : reply()));
      visit('/search?q=unown');
      render(<App />);
      await ready();
      await screen.findByText(STALE);
      expect(reserved()).toBe(false);
      expect(screen.queryAllByText(STALE)).toHaveLength(1);
      expect(screen.getAllByRole('main')).toHaveLength(1);
      expect(screen.getAllByRole('heading', { level: 1 })).toHaveLength(1);
      for (const pattern of FORBIDDEN_WORDS)
        expect(screen.getByRole('main').textContent).not.toMatch(pattern);
    },
  );
});

describe('an answer whose page lies outside its own pages (relevance and price order)', () => {
  const SHAPES: readonly (readonly [string, number, number])[] = [
    ['page 54 of 10', 54, 10],
    ['page 0 of 3', 0, 3],
    ['page -1 of 3', -1, 3],
    ['page 4 of 3', 4, 3],
    ['page 2 of 1', 2, 1],
  ];

  function broken(page: number, pages: number, sort: Sort) {
    return searchBodyOf([unownQuestion], {
      total: pages * 24,
      page,
      pages,
      sort,
      ordered: sort === 'relevance' ? null : pages * 24,
    });
  }

  function expectProblemOnly(): void {
    const alerts = screen.getAllByRole('alert');
    expect(alerts).toHaveLength(1);
    expect(alerts[0]?.textContent).toContain('Search failed');
    expect(screen.queryByRole('navigation', { name: 'Result pages' })).toBeNull();
    expect(screen.queryByRole('list', { name: 'Search results' })).toBeNull();
    const text = screen.getByRole('main').textContent;
    expect(text).not.toMatch(/Page -?\d+ of \d+|NaN|undefined/);
    for (const pattern of FORBIDDEN_WORDS) expect(text).not.toMatch(pattern);
  }

  it.each(
    SHAPES.flatMap(
      ([name, page, pages]) =>
        [
          [`${name}, relevance`, page, pages, 'relevance'],
          [`${name}, price_desc`, page, pages, 'price_desc'],
        ] as const,
    ),
  )(
    'shows the problem block once on first load, with no pager, rows, rewrite, loop or request more: %s',
    async (_name, page, pages, sort) => {
      const calls = stubFetch((url) =>
        isFreshnessUrl(url)
          ? jsonResponse(freshnessBody({ within_slo: true }))
          : jsonResponse(broken(page, pages, sort)),
      );
      const address = `/search?q=unown${sort === 'relevance' ? '' : `&sort=${sort}`}&page=4800`;
      visit(address);
      const replace = vi.spyOn(window.history, 'replaceState');
      const push = vi.spyOn(window.history, 'pushState');
      render(<App />);
      await screen.findByRole('alert');
      await settle();
      expectProblemOnly();
      expect(window.location.pathname + window.location.search).toBe(address);
      expect(replace).not.toHaveBeenCalled();
      expect(push).not.toHaveBeenCalled();
      expect(searchCalls(calls)).toHaveLength(1);
      expect(screen.getByRole('group', { name: 'Order' })).toBeTruthy();
    },
  );

  it.each(['relevance', 'price_desc'] as const)(
    'shows the problem block on a page change in %s, keeps focus on the heading and asks for nothing more',
    async (sort) => {
      const calls = stubFetch((url) => {
        if (isFreshnessUrl(url)) return jsonResponse(freshnessBody({ within_slo: true }));
        return jsonResponse(askedPage(url) === 2 ? broken(54, 10, sort) : pageOf(1, 5, sort));
      });
      visit(`/search?q=unown${sort === 'relevance' ? '' : `&sort=${sort}`}`);
      render(<App />);
      const nav = await screen.findByRole('navigation', { name: 'Result pages' });
      fireEvent.click(within(nav).getByRole('link', { name: 'Next page' }));
      await screen.findByRole('alert');
      await settle();
      expectProblemOnly();
      expect(window.location.search).toContain('page=2');
      expect(document.activeElement).toBe(heading());
      expect(searchCalls(calls)).toHaveLength(2);
    },
  );

  it('is cleared by a later consistent answer for a new query', async () => {
    stubFetch((url) => {
      if (isFreshnessUrl(url)) return jsonResponse(freshnessBody({ within_slo: true }));
      return jsonResponse(url.includes('q=mew') ? pageOf(1, 1) : broken(54, 10, 'relevance'));
    });
    visit('/search?q=unown&page=4800');
    render(<App />);
    await screen.findByRole('alert');
    const box = screen.getByRole('searchbox', { name: 'Card name' });
    fireEvent.change(box, { target: { value: 'mew' } });
    fireEvent.submit(box);
    await screen.findByRole('list', { name: 'Search results' });
    expect(screen.queryByRole('alert')).toBeNull();
    expect(window.location.search).toBe('?q=mew');
  });
});
