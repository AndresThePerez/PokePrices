import { act, cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import App from '../App';
import type { CardSearchPage } from '../api/client';
import {
  degradedSearchPage,
  jsonResponse,
  problemResponse,
  searchPage,
  stubFetch,
  unownExclamation,
  unownQuestion,
  upstreamUnavailableProblem,
} from '../test/fixtures';

const DEGRADED_LINE = 'Catalog data may be out of date: PokéSearch is unavailable';

function visit(path: string): void {
  window.history.replaceState(null, '', path);
}

function goTo(path: string): void {
  act(() => {
    visit(path);
    window.dispatchEvent(new PopStateEvent('popstate'));
  });
}

interface Pending {
  readonly url: string;
  readonly signal: AbortSignal | undefined;
  readonly resolve: (response: Response) => void;
  readonly reject: (error: unknown) => void;
}

/** A fetch whose answers the test settles by hand; it honours the abort signal like a browser. */
function manualFetch(): Pending[] {
  const pending: Pending[] = [];
  vi.stubGlobal('fetch', (input: string | URL, init?: RequestInit): Promise<Response> => {
    return new Promise<Response>((resolve, reject) => {
      const signal = init?.signal ?? undefined;
      signal?.addEventListener('abort', () => {
        reject(new DOMException('aborted', 'AbortError'));
      });
      pending.push({ url: String(input), signal, resolve, reject });
    });
  });
  return pending;
}

function settle(entry: Pending | undefined, response: Response): void {
  if (entry === undefined) throw new Error('no pending request');
  entry.resolve(response);
}

afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
  visit('/');
});

describe('SearchPage edge cases', () => {
  it('aborts the superseded request and shows only the newer query results', async () => {
    const pending = manualFetch();
    visit('/search?q=alpha');
    render(<App />);
    expect(pending).toHaveLength(1);
    goTo('/search?q=beta');
    expect(pending).toHaveLength(2);
    expect(pending[0]?.signal?.aborted).toBe(true);
    expect(pending[1]?.signal?.aborted).toBe(false);
    await act(async () => {
      settle(pending[1], jsonResponse({ ...searchPage, data: [unownExclamation] }));
      await Promise.resolve();
    });
    const list = await screen.findByRole('list', { name: 'Search results' });
    expect(within(list).getAllByRole('listitem')).toHaveLength(1);
    expect(screen.getByRole('status').textContent).toContain('“beta”');
    expect(screen.queryByText(/alpha/)).toBeNull();
  });

  it('drops the degraded line and the problem when the next query succeeds cleanly', async () => {
    const replies: Response[] = [
      jsonResponse(degradedSearchPage),
      problemResponse(upstreamUnavailableProblem),
      jsonResponse(searchPage),
    ];
    const calls = stubFetch(() => replies[calls.length - 1] ?? new Error('unexpected call'));
    visit('/search?q=one');
    const { container } = render(<App />);
    await screen.findByText(DEGRADED_LINE);
    goTo('/search?q=two');
    const alert = await screen.findByRole('alert');
    expect(alert.textContent).toContain('req-t46-upstream-0001');
    expect(container.textContent).not.toContain('out of date');
    goTo('/search?q=three');
    await screen.findByRole('list', { name: 'Search results' });
    expect(screen.queryByRole('alert')).toBeNull();
    expect(container.textContent).not.toContain('out of date');
  });

  it('shows no pager for a single page and does not break when pages is 0', async () => {
    stubFetch(() => jsonResponse(searchPage));
    visit('/search?q=unown');
    const first = render(<App />);
    await screen.findByRole('list', { name: 'Search results' });
    expect(screen.queryByRole('navigation', { name: 'Result pages' })).toBeNull();
    first.unmount();
    cleanup();

    const zeroPages = {
      data: [],
      meta: { total: 0, page: 1, pages: 0, page_size: 24, degraded: false, cached: false },
    } satisfies CardSearchPage;
    stubFetch(() => jsonResponse(zeroPages));
    visit('/search?q=none');
    render(<App />);
    await screen.findByText(/No cards match/);
    expect(screen.queryByRole('navigation', { name: 'Result pages' })).toBeNull();
    expect(within(screen.getByRole('main')).queryByRole('link')).toBeNull();
  });

  it('offers a way back to page 1 when the page is beyond pages and has no cards', async () => {
    const beyond = {
      data: [],
      meta: { total: 30, page: 9, pages: 2, page_size: 24, degraded: false, cached: false },
    } satisfies CardSearchPage;
    stubFetch(() => jsonResponse(beyond));
    visit('/search?q=unown&page=9');
    render(<App />);
    const nav = await screen.findByRole('navigation', { name: 'Result pages' });
    expect(screen.queryByRole('list', { name: 'Search results' })).toBeNull();
    expect(within(nav).queryByRole('link', { name: 'Next page' })).toBeNull();
    const previous = within(nav).getByRole('link', { name: 'Previous page' });
    expect(previous.getAttribute('href')).toBe('/search?q=unown&page=2');
  });

  it.each(['0', '-1', 'abc', '2abc', '1.5', '1e3', '12345678901', ''])(
    'reads page=%j as page 1 and asks the API without a page parameter',
    async (raw) => {
      const calls = stubFetch(() => jsonResponse(searchPage));
      visit(`/search?q=unown&page=${encodeURIComponent(raw)}`);
      render(<App />);
      await screen.findByRole('list', { name: 'Search results' });
      expect(calls.map((call) => call.url)).toEqual(['/v1/cards?q=unown']);
    },
  );

  it('keeps hostile card names inert and links a percent sign id with a single encoding', async () => {
    const hostile = {
      data: [
        {
          ...unownQuestion,
          id: 'ex10-%3F',
          name: '<img src=x onerror="window.__pwned=1">Unown',
          set_name: '<script>window.__pwned=1</script>',
        },
      ],
      meta: { total: 1, page: 1, pages: 1, page_size: 24, degraded: false, cached: false },
    } satisfies CardSearchPage;
    stubFetch(() => jsonResponse(hostile));
    visit('/search?q=unown');
    const { container } = render(<App />);
    const list = await screen.findByRole('list', { name: 'Search results' });
    expect(container.querySelectorAll('script')).toHaveLength(0);
    expect(list.querySelectorAll('img')).toHaveLength(1);
    expect(list.textContent).toContain('<img src=x onerror="window.__pwned=1">Unown');
    expect(within(list).getByRole('link').getAttribute('href')).toBe('/card/ex10-%253F');
    expect(Reflect.get(window, '__pwned')).toBeUndefined();
  });

  it('keeps the row when the API sends an empty image_small, never inventing an image URL', async () => {
    const noImage = {
      data: [{ ...unownQuestion, image_small: '' }],
      meta: { total: 1, page: 1, pages: 1, page_size: 24, degraded: false, cached: false },
    } satisfies CardSearchPage;
    stubFetch(() => jsonResponse(noImage));
    visit('/search?q=unown');
    render(<App />);
    const list = await screen.findByRole('list', { name: 'Search results' });
    expect(within(list).getAllByRole('listitem')).toHaveLength(1);
    expect(list.textContent).toContain('Unown');
    expect(list.querySelector('img')?.getAttribute('src') ?? '').toBe('');
  });

  it('shows the unreachable-API problem without a request id line and recovers on retry', async () => {
    const answers: (Response | Error)[] = [new TypeError('network down'), jsonResponse(searchPage)];
    const calls = stubFetch(() => answers[calls.length - 1] ?? new Error('unexpected call'));
    visit('/search?q=unown');
    render(<App />);
    const alert = await screen.findByRole('alert');
    expect(alert.textContent).toContain('could not be reached');
    expect(alert.textContent).not.toContain('Request ID');
    const box = screen.getByRole('searchbox', { name: 'Card name' });
    fireEvent.change(box, { target: { value: 'unown!' } });
    fireEvent.submit(box);
    await screen.findByRole('list', { name: 'Search results' });
    expect(screen.queryByRole('alert')).toBeNull();
  });

  it('submits an empty box without calling the API and resets to page 1 on a new search', async () => {
    const calls = stubFetch(() => jsonResponse(searchPage));
    visit('/search?q=unown&page=3');
    render(<App />);
    await screen.findByRole('list', { name: 'Search results' });
    expect(calls.map((call) => call.url)).toEqual(['/v1/cards?q=unown&page=3']);
    const box = screen.getByRole('searchbox', { name: 'Card name' });
    fireEvent.change(box, { target: { value: 'sprigatito' } });
    fireEvent.submit(box);
    await waitFor(() => {
      expect(calls).toHaveLength(2);
    });
    expect(calls[1]?.url).toBe('/v1/cards?q=sprigatito');
    fireEvent.change(screen.getByRole('searchbox', { name: 'Card name' }), {
      target: { value: '   ' },
    });
    fireEvent.submit(screen.getByRole('searchbox', { name: 'Card name' }));
    expect(screen.getByRole('main').textContent).toContain('Type a card name');
    expect(screen.queryByRole('list', { name: 'Search results' })).toBeNull();
    expect(calls).toHaveLength(2);
  });
});
