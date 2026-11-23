// The search page's paging polish: the loading state holds the room of the results it is about to
// show, a page change keeps keyboard focus on the page's heading, and an address naming a page past
// the end is corrected in place (a replace) to the last page the API answered, with every other
// parameter as it was and no request more.
import { act, cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import App from '../App';
import type { CardSearchPage } from '../api/client';
import {
  FORBIDDEN_WORDS,
  isFreshnessUrl,
  jsonResponse,
  searchBodyOf,
  stubFetch,
  unownExclamation,
  unownQuestion,
} from '../test/fixtures';

function visit(path: string): void {
  window.history.replaceState(null, '', path);
}

afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
  visit('/');
});

function pageOf(page: number, pages = 5, sort: CardSearchPage['meta']['sort'] = 'relevance') {
  return searchBodyOf([unownQuestion, unownExclamation], {
    total: pages * 2,
    page,
    pages,
    sort,
    ordered: sort === 'relevance' ? null : pages * 2,
  });
}

/** The page asked for in a /v1/cards address (1 when it names none). */
function askedPage(url: string): number {
  return Number(new URL(url, 'http://192.0.2.10').searchParams.get('page') ?? '1');
}

function heading(): HTMLElement {
  return screen.getByRole('heading', { level: 1, name: 'Search cards' });
}

function tokens(element: Element): string[] {
  return (element.getAttribute('class') ?? '').split(/\s+/).filter((token) => token !== '');
}

describe('the search loading state reserves the results’ height', () => {
  it('holds one viewport while searching and lets the answer take its own height', async () => {
    let answer: (response: Response) => void = () => undefined;
    vi.stubGlobal('fetch', (input: string | URL) =>
      isFreshnessUrl(String(input))
        ? new Promise<Response>(() => undefined)
        : new Promise<Response>((resolve) => {
            answer = resolve;
          }),
    );
    visit('/search?q=unown');
    render(<App />);
    expect(screen.getByRole('status').textContent).toBe('Searching for “unown”…');
    const loading = tokens(screen.getByRole('main'));
    expect(loading).toContain('min-h-svh');
    await act(async () => {
      answer(jsonResponse(pageOf(1)));
      await Promise.resolve();
    });
    await screen.findByRole('list', { name: 'Search results' });
    expect(screen.getByRole('status').textContent).toBe('10 cards match “unown”');
    expect(screen.getAllByRole('status')).toHaveLength(1);
    const loaded = tokens(screen.getByRole('main'));
    expect(loaded).not.toContain('min-h-svh');
    // The loading frame only adds the reservation: every loaded token is still there.
    expect(loading).toEqual(expect.arrayContaining(loaded));
  });

  it('takes no reservation for a failed search or an empty address', async () => {
    stubFetch(() => new Error('offline'));
    visit('/search?q=unown');
    render(<App />);
    await screen.findByRole('alert');
    expect(tokens(screen.getByRole('main'))).not.toContain('min-h-svh');
    cleanup();

    stubFetch(() => new Error('no request expected'));
    visit('/search');
    render(<App />);
    expect(tokens(screen.getByRole('main'))).not.toContain('min-h-svh');
  });

  it('reserves the same room again while the next page loads', async () => {
    const waiting: ((response: Response) => void)[] = [];
    vi.stubGlobal('fetch', (input: string | URL) => {
      const url = String(input);
      if (isFreshnessUrl(url)) return new Promise<Response>(() => undefined);
      if (askedPage(url) === 1) return Promise.resolve(jsonResponse(pageOf(1)));
      return new Promise<Response>((resolve) => {
        waiting.push(resolve);
      });
    });
    visit('/search?q=unown');
    render(<App />);
    const nav = await screen.findByRole('navigation', { name: 'Result pages' });
    fireEvent.click(within(nav).getByRole('link', { name: 'Next page' }));
    await screen.findByText('Searching for “unown”…');
    expect(tokens(screen.getByRole('main'))).toContain('min-h-svh');
    expect(screen.queryByRole('list', { name: 'Search results' })).toBeNull();
  });
});

describe('a page change keeps keyboard focus on the page heading', () => {
  it('moves focus to the heading after Next page and after Previous page, outside the tab order', async () => {
    stubFetch((url) => jsonResponse(pageOf(askedPage(url))));
    visit('/search?q=unown&page=2');
    render(<App />);
    let nav = await screen.findByRole('navigation', { name: 'Result pages' });
    const next = within(nav).getByRole('link', { name: 'Next page' });
    next.focus();
    fireEvent.click(next);
    expect(document.activeElement).toBe(heading());
    await waitFor(() => {
      expect(screen.getByRole('navigation', { name: 'Result pages' }).textContent).toContain(
        'Page 3 of 5',
      );
    });
    expect(document.activeElement).toBe(heading());
    expect(heading().getAttribute('tabindex')).toBe('-1');

    nav = screen.getByRole('navigation', { name: 'Result pages' });
    const previous = within(nav).getByRole('link', { name: 'Previous page' });
    previous.focus();
    fireEvent.click(previous);
    await waitFor(() => {
      expect(screen.getByRole('navigation', { name: 'Result pages' }).textContent).toContain(
        'Page 2 of 5',
      );
    });
    expect(document.activeElement).toBe(heading());
    expect(heading().getAttribute('tabindex')).toBe('-1');
    expect(window.location.search).toBe('?q=unown&page=2');
  });

  it('leaves focus alone on a click the browser keeps (a new tab)', async () => {
    stubFetch((url) => jsonResponse(pageOf(askedPage(url))));
    visit('/search?q=unown');
    render(<App />);
    const nav = await screen.findByRole('navigation', { name: 'Result pages' });
    const next = within(nav).getByRole('link', { name: 'Next page' });
    next.focus();
    fireEvent.click(next, { ctrlKey: true });
    expect(document.activeElement).toBe(next);
  });
});

describe('an address naming a page past the end', () => {
  it('shows the last page and replaces only page in the address, in relevance order', async () => {
    const calls = stubFetch((url) => jsonResponse(askedPage(url) > 5 ? pageOf(5) : pageOf(1)));
    visit('/search?q=unown&page=9&from=home');
    const before = window.history.length;
    render(<App />);
    await waitFor(() => {
      expect(window.location.search).toBe('?q=unown&page=5&from=home');
    });
    const nav = screen.getByRole('navigation', { name: 'Result pages' });
    expect(nav.textContent).toContain('Page 5 of 5');
    expect(window.history.length).toBe(before);
    expect(calls.map((call) => call.url)).toEqual([
      '/v1/prices/freshness',
      '/v1/cards?q=unown&page=9',
    ]);
    for (const pattern of FORBIDDEN_WORDS)
      expect(screen.getByRole('main').textContent).not.toMatch(pattern);
  });

  it('keeps q, sort and every other parameter in a price order, and sends no request more', async () => {
    const calls = stubFetch((url) =>
      jsonResponse(askedPage(url) > 3 ? pageOf(3, 3, 'price_desc') : pageOf(1, 3, 'price_desc')),
    );
    visit('/search?sort=price_desc&q=mew%20ex&page=4800&ref=a%2Cb');
    const before = window.history.length;
    render(<App />);
    await waitFor(() => {
      expect(window.location.search).toBe('?sort=price_desc&q=mew%20ex&page=3&ref=a%2Cb');
    });
    const corrected = new URLSearchParams(window.location.search);
    const asked = new URLSearchParams('?sort=price_desc&q=mew%20ex&page=4800&ref=a%2Cb');
    expect([...corrected.keys()]).toEqual([...asked.keys()]);
    for (const key of corrected.keys()) {
      if (key !== 'page') expect(corrected.getAll(key)).toEqual(asked.getAll(key));
    }
    expect(screen.getByRole('navigation', { name: 'Result pages' }).textContent).toContain(
      'Page 3 of 3',
    );
    expect(screen.getByRole('group', { name: 'Order' })).toBeTruthy();
    expect(window.history.length).toBe(before);
    await act(async () => {
      await new Promise((resolve) => setTimeout(resolve, 20));
    });
    expect(calls.map((call) => call.url)).toEqual([
      '/v1/prices/freshness',
      '/v1/cards?q=mew+ex&page=4800&sort=price_desc',
    ]);
  });

  it('drops page from the address when the last page is the first', async () => {
    stubFetch(() => jsonResponse(pageOf(1, 1)));
    visit('/search?q=unown&page=7');
    render(<App />);
    await waitFor(() => {
      expect(window.location.search).toBe('?q=unown');
    });
  });

  it('leaves an in-range address and an empty result as they are', async () => {
    stubFetch((url) =>
      jsonResponse(
        url.includes('q=zzz')
          ? searchBodyOf([], { total: 0, page: 3, pages: 0 })
          : pageOf(askedPage(url)),
      ),
    );
    visit('/search?q=unown&page=4');
    render(<App />);
    await screen.findByRole('list', { name: 'Search results' });
    expect(window.location.search).toBe('?q=unown&page=4');
    cleanup();

    visit('/search?q=zzz&page=3');
    render(<App />);
    await screen.findByText('Page 3 has no cards for “zzz”.');
    expect(window.location.search).toBe('?q=zzz&page=3');
  });
});
