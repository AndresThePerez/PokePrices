// The search page with prices: each row's headline close in the short-row form under the list's
// one source-and-"as of" stamp, the full price sentence in the row's accessible name, the variant
// named on the row and in its link; the order control and its address; the "top 100" sentence of a
// price order; the word cap under the search box; and the stale-prices banner on search.
import { act, cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import App from '../App';
import { searchKey } from '../queryKeys';
import {
  FORBIDDEN_WORDS,
  degradedSearchPage,
  freshnessBody,
  isFreshnessUrl,
  jsonResponse,
  pricedRow,
  problemResponse,
  searchBodyOf,
  searchPriceOf,
  sprigatitoPromo,
  stubFetch,
  unownExclamation,
  unownQuestion,
  upstreamUnavailableProblem,
} from '../test/fixtures';
import { namedRows, visibleStamps, visibleText } from '../test/rows';

const STAMP_1012 = 'as of 2026-10-12 from TCGCSV (daily close)';
const NO_PRICE = 'No price in the collected snapshots';
const WORD_CAP = 'A search can have at most 32 words.';
const BANNER =
  'Prices are out of date: the newest collected prices are as of 2026-10-13, 950.3 hours old.';

const holo = pricedRow(unownQuestion, 'holo:-:-', searchPriceOf());
const reverse = pricedRow(
  sprigatitoPromo,
  'reverse:-:-',
  searchPriceOf({
    close: '1234.50',
    change: '-0.50',
    change_pct: '-0.04',
    previous_close: '1235.00',
  }),
);
const olderReverse = pricedRow(
  sprigatitoPromo,
  'reverse:-:-',
  searchPriceOf({
    close: '3.00',
    day: '2026-10-11',
    previous_close: null,
    previous_day: null,
    change: null,
    change_pct: null,
  }),
);

const mixed = searchBodyOf([holo, unownExclamation, reverse], { total: 3 });

function visit(path: string): void {
  window.history.replaceState(null, '', path);
}

/** A fake that answers freshness within its SLO (no banner) and every search with `body`. */
function stubSearch(body: (url: string) => Response | Error): ReturnType<typeof stubFetch> {
  return stubFetch((url) =>
    isFreshnessUrl(url) ? jsonResponse(freshnessBody({ within_slo: true })) : body(url),
  );
}

function results(): HTMLElement {
  return screen.getByRole('list', { name: 'Search results' });
}

function searchUrls(calls: ReturnType<typeof stubFetch>): string[] {
  return calls.map((call) => call.url).filter((url) => !isFreshnessUrl(url));
}

afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
  visit('/');
});

describe('search rows with prices (j, k)', () => {
  it('shows each priced row short, with its variant, and stamps the list once', async () => {
    stubSearch(() => jsonResponse(mixed));
    visit('/search?q=unown');
    render(<App />);
    await screen.findByRole('list', { name: 'Search results' });
    const rows = within(results()).getAllByRole('listitem');
    expect(rows).toHaveLength(3);
    const [first, second, third] = rows.map((row) => visibleText(row));
    expect(first).toContain('$70.81');
    expect(first).toContain('▲ +2.09%');
    expect(first).toContain('Holo');
    expect(first).not.toContain('Market price');
    expect(first).not.toContain('vs previous close');
    expect(second).toContain(NO_PRICE);
    expect(second).not.toContain('$');
    expect(third).toContain('$1,234.50');
    expect(third).toContain('▼ -0.04%');
    expect(third).toContain('Reverse');
    const stamps = visibleStamps(screen.getByRole('main'));
    expect(stamps.map((stamp) => stamp.textContent)).toEqual([STAMP_1012]);
    expect(results().contains(stamps[0] ?? null)).toBe(false);
  });

  it("puts the full price sentence with its source and as-of day in each priced row's name", async () => {
    stubSearch(() => jsonResponse(mixed));
    visit('/search?q=unown');
    render(<App />);
    await screen.findByRole('list', { name: 'Search results' });
    const names = namedRows(results()).map((row) => row.name);
    expect(names[0]).toContain('Unown');
    expect(names[0]).toContain('Holo');
    expect(names[0]).toContain('Market price $70.81');
    expect(names[0]).toContain('▲ +$1.45 (+2.09%) vs previous close (2026-10-11)');
    expect(names[0]).toContain(STAMP_1012);
    expect(names[1]).toContain(NO_PRICE);
    expect(names[1]).not.toContain('Market price');
    expect(names[2]).toContain('Market price $1,234.50');
    expect(names[2]).toContain('▼ -$0.50 (-0.04%) vs previous close (2026-10-11)');
    expect(names[2]).toContain(STAMP_1012);
  });

  it("stamps the span of days once and prints each row's day when the rows differ", async () => {
    stubSearch(() => jsonResponse(searchBodyOf([holo, olderReverse])));
    visit('/search?q=unown');
    render(<App />);
    await screen.findByRole('list', { name: 'Search results' });
    const stamps = visibleStamps(screen.getByRole('main'));
    expect(stamps.map((stamp) => stamp.textContent)).toEqual([
      'Closes from 2026-10-11 to 2026-10-12 from TCGCSV (daily close)',
    ]);
    const rows = within(results()).getAllByRole('listitem');
    expect(visibleText(rows[0] as HTMLElement)).toContain('Close of 2026-10-12');
    expect(visibleText(rows[1] as HTMLElement)).toContain('Close of 2026-10-11');
    const names = namedRows(results()).map((row) => row.name);
    expect(names[1]).toContain('as of 2026-10-11 from TCGCSV (daily close)');
    expect(names[1]).toContain('No previous close yet');
  });

  it('prints no stamp when no row has a price', async () => {
    stubSearch(() => jsonResponse(searchBodyOf([unownQuestion, unownExclamation])));
    visit('/search?q=unown');
    render(<App />);
    await screen.findByRole('list', { name: 'Search results' });
    expect(visibleStamps(screen.getByRole('main'))).toHaveLength(0);
    for (const row of within(results()).getAllByRole('listitem')) {
      expect(visibleText(row)).toContain(NO_PRICE);
    }
  });

  it('links a priced row to the variant it priced and an unpriced row to the card', async () => {
    stubSearch(() => jsonResponse(mixed));
    visit('/search?q=unown');
    render(<App />);
    await screen.findByRole('list', { name: 'Search results' });
    const links = within(results()).getAllByRole('link');
    expect(links.map((link) => link.getAttribute('href'))).toEqual([
      '/card/ex10-%3F?variant=ex10-%3F%3Aholo%3A-%3A-',
      '/card/ex10-!',
      '/card/svp-1?variant=svp-1%3Areverse%3A-%3A-',
    ]);
  });
});

describe('the order control (l)', () => {
  it('offers three orders with the current one pressed, relevance by default', async () => {
    stubSearch(() => jsonResponse(mixed));
    visit('/search?q=unown');
    render(<App />);
    await screen.findByRole('list', { name: 'Search results' });
    const group = screen.getByRole('group', { name: 'Order' });
    const buttons = within(group).getAllByRole('button');
    expect(buttons.map((button) => button.textContent)).toEqual([
      'Best match',
      'Price: high to low',
      'Price: low to high',
    ]);
    expect(buttons.map((button) => button.getAttribute('aria-pressed'))).toEqual([
      'true',
      'false',
      'false',
    ]);
  });

  it('puts the order in the address and the request, back on page 1, and Back restores the last order', async () => {
    const calls = stubSearch((url) =>
      jsonResponse(
        url.includes('sort=')
          ? searchBodyOf([reverse, holo], { sort: 'price_desc', ordered: 2, total: 2 })
          : searchBodyOf([holo, reverse], { total: 60, page: 3, pages: 3 }),
      ),
    );
    visit('/search?q=unown&page=3');
    render(<App />);
    await screen.findByRole('list', { name: 'Search results' });
    fireEvent.click(screen.getByRole('button', { name: 'Price: high to low' }));
    expect(window.location.pathname + window.location.search).toBe(
      '/search?q=unown&sort=price_desc',
    );
    await waitFor(() => {
      expect(
        screen.getByRole('button', { name: 'Price: high to low' }).getAttribute('aria-pressed'),
      ).toBe('true');
    });
    await screen.findByText(/Ordered by price/);
    expect(searchUrls(calls)).toEqual([
      '/v1/cards?q=unown&page=3',
      '/v1/cards?q=unown&sort=price_desc',
    ]);

    act(() => {
      window.history.back();
    });
    await waitFor(() => {
      expect(window.location.search).toBe('?q=unown&page=3');
    });
    await waitFor(() => {
      expect(screen.getByRole('button', { name: 'Best match' }).getAttribute('aria-pressed')).toBe(
        'true',
      );
    });
  });

  it('opens a price order from the address and keeps it on the pager links', async () => {
    const calls = stubSearch(() =>
      jsonResponse(
        searchBodyOf([holo], { sort: 'price_asc', ordered: 100, total: 4800, page: 2, pages: 5 }),
      ),
    );
    visit('/search?q=unown&sort=price_asc&page=2');
    render(<App />);
    await screen.findByRole('list', { name: 'Search results' });
    expect(
      screen.getByRole('button', { name: 'Price: low to high' }).getAttribute('aria-pressed'),
    ).toBe('true');
    expect(searchUrls(calls)).toEqual(['/v1/cards?q=unown&page=2&sort=price_asc']);
    const nav = screen.getByRole('navigation', { name: 'Result pages' });
    expect(within(nav).getByRole('link', { name: 'Next page' }).getAttribute('href')).toBe(
      '/search?q=unown&sort=price_asc&page=3',
    );
    expect(within(nav).getByRole('link', { name: 'Previous page' }).getAttribute('href')).toBe(
      '/search?q=unown&sort=price_asc',
    );
  });

  it('reads an unknown order in the address as relevance and asks for no order', async () => {
    const calls = stubSearch(() => jsonResponse(mixed));
    visit('/search?q=unown&sort=cheapest');
    render(<App />);
    await screen.findByRole('list', { name: 'Search results' });
    expect(screen.getByRole('button', { name: 'Best match' }).getAttribute('aria-pressed')).toBe(
      'true',
    );
    expect(searchUrls(calls)).toEqual(['/v1/cards?q=unown']);
  });

  it('keys each order apart and keeps the relevance key as it was', () => {
    expect(searchKey('unown', 1)).toEqual(['search', 'unown', 1]);
    expect(searchKey('unown', 1, 'relevance')).toEqual(['search', 'unown', 1]);
    expect(searchKey('unown', 1, 'price_desc')).toEqual(['search', 'unown', 1, 'price_desc']);
    expect(searchKey('unown', 2, 'price_asc')).toEqual(['search', 'unown', 2, 'price_asc']);
  });
});

describe('the "top 100" sentence (m)', () => {
  it.each<readonly [number, number, string]>([
    [0, 0, 'No matches to order by price.'],
    [1, 1, 'Ordered by price among the only match.'],
    [37, 37, 'Ordered by price among all 37 matches; cards with no price come last.'],
    [100, 100, 'Ordered by price among all 100 matches; cards with no price come last.'],
    [
      4800,
      100,
      'Ordered by price among the top 100 of 4,800 matches; cards with no price come last.',
    ],
    [101, 100, 'Ordered by price among the top 100 of 101 matches; cards with no price come last.'],
  ])('for %i matches of which %i were ordered', async (total, ordered, sentence) => {
    stubSearch(() =>
      jsonResponse(searchBodyOf(total === 0 ? [] : [holo], { sort: 'price_desc', ordered, total })),
    );
    visit('/search?q=unown&sort=price_desc');
    render(<App />);
    expect((await screen.findByText(/order by price|Ordered by price/)).textContent).toBe(sentence);
  });

  it('is absent in relevance order', async () => {
    stubSearch(() => jsonResponse(searchBodyOf([holo], { total: 4800 })));
    visit('/search?q=unown');
    render(<App />);
    await screen.findByRole('list', { name: 'Search results' });
    expect(screen.queryByText(/order by price|Ordered by price/i)).toBeNull();
  });
});

describe('the word cap (n)', () => {
  const thirtyThree = Array.from({ length: 33 }, () => 'a').join(' ');

  it("shows the API's message on q under the search box", async () => {
    stubSearch(() =>
      problemResponse({
        type: 'https://pokeprices.andrestheperez.com/problems/validation-failed',
        title: 'Validation Failed',
        status: 422,
        instance: '/v1/cards',
        request_id: 'req-t06-words-0001',
        errors: { q: [WORD_CAP] },
      }),
    );
    visit(`/search?q=${encodeURIComponent(thirtyThree)}`);
    render(<App />);
    const form = screen.getByRole('search');
    const message = await within(form).findByText(WORD_CAP);
    const box = within(form).getByRole('searchbox', { name: 'Card name' });
    expect(box.getAttribute('aria-invalid')).toBe('true');
    expect(box.getAttribute('aria-describedby')).toBe(message.id);
    expect(screen.queryByText(/Search failed/)).toBeNull();
  });

  it('stops a submit of more than 32 words with the same sentence and sends nothing', () => {
    const calls = stubSearch(() => jsonResponse(mixed));
    visit('/search');
    render(<App />);
    const form = screen.getByRole('search');
    const box = within(form).getByRole('searchbox', { name: 'Card name' });
    fireEvent.change(box, { target: { value: ` ${thirtyThree.replaceAll(' ', ' \t ')} ` } });
    fireEvent.submit(box);
    expect(within(form).getByText(WORD_CAP)).toBeTruthy();
    expect(window.location.pathname + window.location.search).toBe('/search');
    expect(searchUrls(calls)).toEqual([]);
    fireEvent.change(box, {
      target: { value: Array.from({ length: 32 }, () => 'a').join('\u00a0 ') },
    });
    expect(within(form).queryByText(WORD_CAP)).toBeNull();
    fireEvent.submit(box);
    expect(window.location.pathname).toBe('/search');
    expect(new URLSearchParams(window.location.search).get('q')?.split(/\s+/)).toHaveLength(32);
  });
});

describe('the stale-prices banner on search (addendum)', () => {
  it('asks for freshness and shows the one line when prices are out of date', async () => {
    const calls = stubFetch((url) =>
      isFreshnessUrl(url) ? jsonResponse(freshnessBody()) : jsonResponse(mixed),
    );
    visit('/search?q=unown');
    render(<App />);
    expect(await screen.findByText(BANNER)).toBeTruthy();
    expect(screen.getAllByText(BANNER)).toHaveLength(1);
    expect(calls.filter((call) => isFreshnessUrl(call.url))).toHaveLength(1);
  });

  it('asks for freshness and shows no line when prices are within their limit', async () => {
    const calls = stubSearch(() => jsonResponse(mixed));
    visit('/search?q=unown');
    render(<App />);
    await screen.findByRole('list', { name: 'Search results' });
    await waitFor(() => {
      expect(calls.filter((call) => isFreshnessUrl(call.url))).toHaveLength(1);
    });
    expect(screen.queryByText(/Prices are out of date/)).toBeNull();
  });
});

describe('the forbidden words on every search state (o)', () => {
  function expectClean(): void {
    const text = document.body.textContent;
    for (const word of FORBIDDEN_WORDS) expect(text).not.toMatch(word);
  }

  const states: readonly (readonly [string, string, (url: string) => Response | Error])[] = [
    ['loading', '/search?q=unown', () => new Error('never answers in time')],
    ['relevance results', '/search?q=unown', () => jsonResponse(mixed)],
    [
      'price_desc results',
      '/search?q=unown&sort=price_desc',
      () =>
        jsonResponse(
          searchBodyOf([reverse, holo, unownExclamation], {
            sort: 'price_desc',
            ordered: 100,
            total: 4800,
          }),
        ),
    ],
    [
      'price_asc results',
      '/search?q=unown&sort=price_asc',
      () =>
        jsonResponse(searchBodyOf([holo, reverse], { sort: 'price_asc', ordered: 2, total: 2 })),
    ],
    ['empty', '/search?q=zzzz', () => jsonResponse(searchBodyOf([], { total: 0, pages: 0 }))],
    [
      'empty price order',
      '/search?q=zzzz&sort=price_desc',
      () => jsonResponse(searchBodyOf([], { sort: 'price_desc', ordered: 0, total: 0 })),
    ],
    ['degraded', '/search?q=unown', () => jsonResponse(degradedSearchPage)],
    ['error', '/search?q=unown', () => problemResponse(upstreamUnavailableProblem)],
    [
      'word cap',
      `/search?q=${'a%20'.repeat(33)}`,
      () =>
        problemResponse({
          type: 'https://pokeprices.andrestheperez.com/problems/validation-failed',
          title: 'Validation Failed',
          status: 422,
          instance: '/v1/cards',
          request_id: 'req-t06-words-0002',
          errors: { q: [WORD_CAP] },
        }),
    ],
  ];

  it.each(states)('passes in the %s state', async (name, path, reply) => {
    stubFetch((url) => (isFreshnessUrl(url) ? jsonResponse(freshnessBody()) : reply(url)));
    visit(path);
    render(<App />);
    if (name === 'loading') {
      // Checked before the fake's answer is read: the page is still searching.
      expect(screen.getByText(/Searching for/)).toBeTruthy();
    } else {
      await screen.findByText(BANNER);
      await waitFor(() => {
        expect(screen.queryByText(/Searching for/)).toBeNull();
      });
    }
    expectClean();
  });
});
