import { act, cleanup, render, screen, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import App from '../App';
import type { CardSearchPage } from '../api/client';
import { navigate } from '../router';
import {
  degradedSearchPage,
  jsonResponse,
  stubFetch,
  sprigatitoPromo,
  unownQuestion,
} from '../test/fixtures';

const DEGRADED_LINE = 'Catalog data may be out of date: PokéSearch is unavailable';

function visit(path: string): void {
  window.history.replaceState(null, '', path);
}

function bodyOf(
  data: CardSearchPage['data'],
  meta: Partial<CardSearchPage['meta']> = {},
): CardSearchPage {
  return {
    data,
    meta: {
      total: data.length,
      page: 1,
      pages: 1,
      page_size: 24,
      degraded: false,
      cached: false,
      ...meta,
    },
  };
}

afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
  visit('/');
});

describe('SearchPage review (D2-i2-T08)', () => {
  it('writes the number line as one span: No. n with the rarity after a middle dot, and plain No. n for a null rarity', async () => {
    stubFetch(() => jsonResponse(bodyOf([unownQuestion, sprigatitoPromo])));
    visit('/search?q=unown');
    render(<App />);
    const list = await screen.findByRole('list', { name: 'Search results' });
    const [ranked, promo] = within(list).getAllByRole('listitem');
    if (ranked === undefined || promo === undefined) throw new Error('rows expected');
    expect(within(ranked).getByText('No. ? · Rare').tagName).toBe('SPAN');
    expect(within(promo).getByText('No. 1').tagName).toBe('SPAN');
    for (const row of within(list).getAllByRole('listitem')) {
      expect(row.textContent).not.toMatch(/null|undefined/u);
    }
    expect(promo.textContent).toBe('SprigatitoScarlet & Violet Black Star PromosNo. 1');
  });

  it('draws a placeholder initial that is only an attribute, for hostile, astral and padded names', async () => {
    const names = ['"><b>x</b>', '\u{1F600} grin', '   padded', 'ñandu'];
    stubFetch(() =>
      jsonResponse(
        bodyOf(
          names.map((name, index) => ({
            ...unownQuestion,
            id: `n-${String(index)}`,
            name,
            image_small: '',
          })),
        ),
      ),
    );
    visit('/search?q=n');
    const { container } = render(<App />);
    const list = await screen.findByRole('list', { name: 'Search results' });
    const initials = Array.from(list.querySelectorAll('[data-initial]')).map((node) =>
      node.getAttribute('data-initial'),
    );
    expect(initials).toEqual(['"', '\u{1F600}', 'P', 'Ñ']);
    expect(list.querySelectorAll('img')).toHaveLength(0);
    expect(container.querySelectorAll('b')).toHaveLength(0);
    for (const node of Array.from(list.querySelectorAll('[data-initial]'))) {
      expect(node.textContent).toBe('');
    }
  });

  it('draws a placeholder, not an img, for a whitespace-only image_small', async () => {
    stubFetch(() => jsonResponse(bodyOf([{ ...unownQuestion, image_small: '   ' }])));
    visit('/search?q=unown');
    render(<App />);
    const list = await screen.findByRole('list', { name: 'Search results' });
    expect(list.querySelectorAll('img')).toHaveLength(0);
    expect(list.querySelectorAll('[data-initial]')).toHaveLength(1);
  });

  it('keeps Previous, the page line and Next in that order on a middle page, with q and page in the hrefs', async () => {
    stubFetch(() =>
      jsonResponse(bodyOf([unownQuestion], { total: 90, page: 3, pages: 5, page_size: 24 })),
    );
    visit('/search?q=a%26b+%23c&page=3');
    render(<App />);
    const nav = await screen.findByRole('navigation', { name: 'Result pages' });
    const links = within(nav).getAllByRole('link');
    expect(links.map((link) => link.textContent)).toEqual(['Previous page', 'Next page']);
    expect(links.map((link) => link.getAttribute('href'))).toEqual([
      '/search?q=a%26b+%23c&page=2',
      '/search?q=a%26b+%23c&page=4',
    ]);
    expect(within(nav).getAllByText('Page 3 of 5')).toHaveLength(1);
    expect(nav.textContent).toBe('Previous pagePage 3 of 5Next page');
  });

  it('links Previous from page 2 back to the bare query, without a page parameter', async () => {
    stubFetch(() =>
      jsonResponse(bodyOf([unownQuestion], { total: 30, page: 2, pages: 2, page_size: 24 })),
    );
    visit('/search?q=unown&page=2');
    render(<App />);
    const nav = await screen.findByRole('navigation', { name: 'Result pages' });
    expect(within(nav).getByRole('link', { name: 'Previous page' }).getAttribute('href')).toBe(
      '/search?q=unown',
    );
    expect(within(nav).queryByRole('link', { name: 'Next page' })).toBeNull();
    expect(nav.textContent).toBe('Previous pagePage 2 of 2');
  });

  it('keeps one status line under the degraded notice, with the count text verbatim', async () => {
    stubFetch(() => jsonResponse(degradedSearchPage));
    visit('/search?q=unown');
    render(<App />);
    await screen.findByRole('list', { name: 'Search results' });
    const status = screen.getByRole('status');
    expect(screen.getAllByRole('status')).toHaveLength(1);
    expect(status.textContent).toBe('1 card matches “unown”');
    const notice = screen.getByText(DEGRADED_LINE);
    expect(notice.compareDocumentPosition(status) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
    expect(
      status.compareDocumentPosition(screen.getByRole('list', { name: 'Search results' })) &
        Node.DOCUMENT_POSITION_FOLLOWING,
    ).toBeTruthy();
  });

  it('makes one list and no thumbnail request for a page of imaged and imageless rows', async () => {
    const calls = stubFetch(() =>
      jsonResponse(bodyOf([unownQuestion, { ...sprigatitoPromo, image_small: '' }])),
    );
    visit('/search?q=a');
    render(<App />);
    await screen.findByRole('list', { name: 'Search results' });
    expect(calls.map((call) => call.url)).toEqual(['/v1/cards?q=a']);
  });
});

/** Answers /v1/cards?q=a with Unown and anything else with Sprigatito; reads the URLs asked so far. */
function byQuery(): () => string[] {
  const calls = stubFetch((url) =>
    jsonResponse(bodyOf([url.includes('q=a') ? unownQuestion : sprigatitoPromo])),
  );
  return () => calls.map((call) => call.url);
}

describe('SearchPage in the query cache (Q1-i5-T04)', () => {
  it('answers a revisited search from the cache: a, b, then a again sends two requests', async () => {
    const urls = byQuery();
    visit('/search?q=a');
    render(<App />);
    await screen.findByText('1 card matches “a”');
    act(() => {
      navigate('/search?q=b');
    });
    await screen.findByText('1 card matches “b”');
    act(() => {
      navigate('/search?q=a');
    });
    await act(async () => {
      await Promise.resolve();
    });
    expect(urls()).toEqual(['/v1/cards?q=a', '/v1/cards?q=b']);
    expect(screen.getByRole('status').textContent).toBe('1 card matches “a”');
    expect(screen.getAllByRole('status')).toHaveLength(1);
  });

  it('keeps each page of one query under its own entry: page 2, page 1, page 2 sends two requests', async () => {
    const calls = stubFetch(() =>
      jsonResponse(bodyOf([unownQuestion], { total: 30, page: 1, pages: 2, page_size: 24 })),
    );
    visit('/search?q=unown&page=2');
    render(<App />);
    await screen.findByRole('list', { name: 'Search results' });
    act(() => {
      navigate('/search?q=unown');
    });
    await screen.findByRole('list', { name: 'Search results' });
    act(() => {
      navigate('/search?q=unown&page=2');
    });
    await act(async () => {
      await Promise.resolve();
    });
    expect(calls.map((call) => call.url)).toEqual([
      '/v1/cards?q=unown&page=2',
      '/v1/cards?q=unown',
    ]);
    expect(screen.getByRole('list', { name: 'Search results' })).toBeTruthy();
  });
});

describe('SearchPage polling (Q1-i5-T04, HG-40 #11)', () => {
  let visibility: DocumentVisibilityState = 'visible';

  /** Lets answers and renders settle with real zero-delay timeouts (see CardPage.polling.test). */
  async function settle(): Promise<void> {
    for (let round = 0; round < 3; round += 1) {
      await act(async () => {
        await new Promise((resolve) => {
          setTimeout(resolve, 0);
        });
      });
    }
  }

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

  it('asks for a shown search again after 300,000 ms while visible, and not before', async () => {
    const urls = byQuery();
    visit('/search?q=a');
    render(<App />);
    await settle();
    expect(screen.getByRole('status').textContent).toBe('1 card matches “a”');
    expect(urls()).toEqual(['/v1/cards?q=a']);
    await advance(299_999);
    expect(urls()).toEqual(['/v1/cards?q=a']);
    await advance(1);
    expect(urls()).toEqual(['/v1/cards?q=a', '/v1/cards?q=a']);
    expect(screen.getByRole('status').textContent).toBe('1 card matches “a”');
  });

  it('asks nothing while the tab is hidden', async () => {
    const urls = byQuery();
    visit('/search?q=a');
    render(<App />);
    await settle();
    visibility = 'hidden';
    act(() => {
      document.dispatchEvent(new Event('visibilitychange', { bubbles: true }));
    });
    await advance(600_000);
    expect(urls()).toEqual(['/v1/cards?q=a']);
  });
});
