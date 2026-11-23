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
  validationProblem,
} from '../test/fixtures';

const DEGRADED_LINE = 'Catalog data may be out of date: PokéSearch is unavailable';

/** pages deliberately disagrees with ceil(total / page_size) = 2: the page must trust pages. */
const fivePages = {
  data: [unownQuestion, unownExclamation],
  meta: {
    total: 30,
    page: 1,
    pages: 5,
    page_size: 24,
    degraded: false,
    cached: false,
    sort: 'relevance',
    ordered: null,
  },
} satisfies CardSearchPage;

const lastOfFive = {
  data: [unownExclamation],
  meta: {
    total: 30,
    page: 5,
    pages: 5,
    page_size: 24,
    degraded: false,
    cached: false,
    sort: 'relevance',
    ordered: null,
  },
} satisfies CardSearchPage;

const noMatches = {
  data: [],
  meta: {
    total: 0,
    page: 1,
    pages: 0,
    page_size: 24,
    degraded: false,
    cached: false,
    sort: 'relevance',
    ordered: null,
  },
} satisfies CardSearchPage;

function visit(path: string): void {
  window.history.replaceState(null, '', path);
}

function results(): HTMLElement {
  return screen.getByRole('list', { name: 'Search results' });
}

afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
  visit('/');
});

describe('SearchPage', () => {
  it('renders each result with name, number, set name, rarity and the API image', async () => {
    const calls = stubFetch(() => jsonResponse(searchPage));
    visit('/search?q=unown');
    render(<App />);
    await screen.findByRole('list', { name: 'Search results' });
    expect(calls.map((call) => call.url)).toEqual(['/v1/prices/freshness', '/v1/cards?q=unown']);
    const items = within(results()).getAllByRole('listitem');
    expect(items).toHaveLength(3);
    const first = items[0];
    if (first === undefined) throw new Error('no first result');
    expect(first.textContent).toContain('Unown');
    expect(first.textContent).toContain('Unseen Forces');
    expect(first.textContent).toContain('No. ?');
    expect(first.textContent).toContain('Rare');
    expect(first.querySelector('img')?.getAttribute('src')).toBe(
      'https://images.example.com/ex10/question.png',
    );
    const promo = items[2];
    if (promo === undefined) throw new Error('no third result');
    expect(promo.textContent).toContain('Scarlet & Violet Black Star Promos');
    expect(promo.textContent).toContain('No. 1');
    expect(promo.textContent).not.toContain('null');
  });

  it('links ex10-? to /card/ex10-%3F and navigates there in place', async () => {
    stubFetch(() => jsonResponse(searchPage));
    visit('/search?q=unown');
    render(<App />);
    await screen.findByRole('list', { name: 'Search results' });
    const links = within(results()).getAllByRole('link');
    expect(links.map((link) => link.getAttribute('href'))).toEqual([
      '/card/ex10-%3F',
      '/card/ex10-!',
      '/card/svp-1',
    ]);
    const unown = links[0];
    if (unown === undefined) throw new Error('no first link');
    expect(fireEvent.click(unown)).toBe(false);
    expect(window.location.pathname).toBe('/card/ex10-%3F');
    expect(screen.getByRole('heading', { level: 1 }).textContent).toBe('Card ex10-?');
  });

  it('pages by meta.pages, never total / page_size', async () => {
    const calls = stubFetch((url) => jsonResponse(url.includes('page=5') ? lastOfFive : fivePages));
    visit('/search?q=unown');
    render(<App />);
    const nav = await screen.findByRole('navigation', { name: 'Result pages' });
    expect(nav.textContent).toContain('Page 1 of 5');
    expect(within(nav).queryByRole('link', { name: 'Previous page' })).toBeNull();
    const next = within(nav).getByRole('link', { name: 'Next page' });
    expect(next.getAttribute('href')).toBe('/search?q=unown&page=2');

    act(() => {
      visit('/search?q=unown&page=5');
      window.dispatchEvent(new PopStateEvent('popstate'));
    });
    await waitFor(() => {
      expect(screen.getByRole('navigation', { name: 'Result pages' }).textContent).toContain(
        'Page 5 of 5',
      );
    });
    expect(calls.map((call) => call.url)).toEqual([
      '/v1/prices/freshness',
      '/v1/cards?q=unown',
      '/v1/cards?q=unown&page=5',
    ]);
    const last = screen.getByRole('navigation', { name: 'Result pages' });
    expect(within(last).queryByRole('link', { name: 'Next page' })).toBeNull();
    expect(within(last).getByRole('link', { name: 'Previous page' }).getAttribute('href')).toBe(
      '/search?q=unown&page=4',
    );
  });

  it('follows the next-page link and asks the API for that page', async () => {
    const calls = stubFetch(() => jsonResponse(fivePages));
    visit('/search?q=unown%20%3F');
    render(<App />);
    const nav = await screen.findByRole('navigation', { name: 'Result pages' });
    const next = within(nav).getByRole('link', { name: 'Next page' });
    expect(next.getAttribute('href')).toBe('/search?q=unown+%3F&page=2');
    fireEvent.click(next);
    await waitFor(() => {
      expect(calls).toHaveLength(3);
    });
    expect(calls[2]?.url).toBe('/v1/cards?q=unown+%3F&page=2');
    expect(window.location.search).toBe('?q=unown+%3F&page=2');
  });

  it('shows the degraded line verbatim when meta.degraded is true', async () => {
    stubFetch(() => jsonResponse(degradedSearchPage));
    visit('/search?q=unown');
    render(<App />);
    await screen.findByRole('list', { name: 'Search results' });
    expect(screen.getByText(DEGRADED_LINE).textContent).toBe(DEGRADED_LINE);
  });

  it('never shows the degraded line on a fresh page', async () => {
    stubFetch(() => jsonResponse(searchPage));
    visit('/search?q=unown');
    const { container } = render(<App />);
    await screen.findByRole('list', { name: 'Search results' });
    expect(container.textContent).not.toContain('out of date');
  });

  it('shows the problem title and request_id when the API answers a problem', async () => {
    stubFetch(() => problemResponse(upstreamUnavailableProblem));
    visit('/search?q=unown');
    render(<App />);
    const alert = await screen.findByRole('alert');
    expect(alert.textContent).toContain('Upstream Unavailable');
    expect(alert.textContent).toContain('req-t46-upstream-0001');
    expect(screen.queryByRole('list', { name: 'Search results' })).toBeNull();
  });

  it('shows a validation problem the same way', async () => {
    stubFetch(() => problemResponse(validationProblem));
    visit('/search?q=unown&page=2');
    render(<App />);
    const alert = await screen.findByRole('alert');
    expect(alert.textContent).toContain('Validation Failed');
    expect(alert.textContent).toContain('req-t46-validation-0002');
  });

  it('asks for a query and calls nothing while the query is empty', () => {
    const calls = stubFetch(() => jsonResponse(searchPage));
    visit('/search?q=%20%20');
    render(<App />);
    expect(screen.getByRole('main').textContent).toContain('Type a card name');
    expect(screen.queryByRole('list', { name: 'Search results' })).toBeNull();
    expect(calls.map((call) => call.url)).toEqual(['/v1/prices/freshness']);
  });

  it('shows a loading status until the API answers', async () => {
    let answer: (response: Response) => void = () => undefined;
    vi.stubGlobal(
      'fetch',
      () =>
        new Promise<Response>((resolve) => {
          answer = resolve;
        }),
    );
    visit('/search?q=unown');
    render(<App />);
    expect(screen.getByRole('status').textContent).toContain('Searching');
    await act(async () => {
      answer(jsonResponse(searchPage));
      await Promise.resolve();
    });
    await screen.findByRole('list', { name: 'Search results' });
    expect(screen.queryByText(/Searching/)).toBeNull();
  });

  it('says so when nothing matches', async () => {
    stubFetch(() => jsonResponse(noMatches));
    visit('/search?q=zzzz');
    render(<App />);
    expect((await screen.findByText(/No cards match/)).textContent).toContain('zzzz');
    expect(screen.queryByRole('navigation', { name: 'Result pages' })).toBeNull();
  });

  it('submits the query box to /search?q= and fetches the trimmed query', async () => {
    const calls = stubFetch(() => jsonResponse(searchPage));
    visit('/search');
    render(<App />);
    const box = screen.getByRole('searchbox', { name: 'Card name' });
    fireEvent.change(box, { target: { value: '  unown ?  ' } });
    fireEvent.submit(box);
    await screen.findByRole('list', { name: 'Search results' });
    expect(window.location.search).toBe('?q=unown+%3F');
    expect(calls.map((call) => call.url)).toEqual([
      '/v1/prices/freshness',
      '/v1/cards?q=unown+%3F',
    ]);
  });

  it('never shows live or real-time wording', async () => {
    stubFetch(() => jsonResponse(degradedSearchPage));
    visit('/search?q=unown');
    render(<App />);
    await screen.findByRole('list', { name: 'Search results' });
    const text = screen.getByRole('main').textContent.toLowerCase();
    for (const word of ['live', 'real-time', 'realtime']) {
      expect(text).not.toContain(word);
    }
  });
});
