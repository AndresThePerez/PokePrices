// Reviewer edge tests for the search page with prices: answers of the wrong kind, the order control through Back and
// Forward and a hand-typed address, a row's link and its text under hostile names, the honesty of every state (a
// source and an "as of" day on every price, one stamp, no forbidden word, no listing note) and the stale-prices banner
// across an order change.
import { act, cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import App from '../App';
import { grouped } from '../format';
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
  validationProblem,
} from '../test/fixtures';
import { namedRows, visibleStamps, visibleText } from '../test/rows';

const NO_PRICE = 'No price in the collected snapshots';
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

function visit(path: string): void {
  window.history.replaceState(null, '', path);
}

/** A fake that answers freshness within its SLO (no banner) and every other request through `reply`. */
function stubSearch(reply: (url: string) => Response | Error): ReturnType<typeof stubFetch> {
  return stubFetch((url) =>
    isFreshnessUrl(url) ? jsonResponse(freshnessBody({ within_slo: true })) : reply(url),
  );
}

function searchUrls(calls: ReturnType<typeof stubFetch>): string[] {
  return calls.map((call) => call.url).filter((url) => !isFreshnessUrl(url));
}

function results(): HTMLElement {
  return screen.getByRole('list', { name: 'Search results' });
}

function hrefs(): (string | null)[] {
  return within(results())
    .getAllByRole('link')
    .map((link) => link.getAttribute('href'));
}

function address(): string {
  return window.location.pathname + window.location.search;
}

/** Card number `n` with a name that tells the cards apart. */
function card(n: number): typeof unownQuestion {
  return { ...unownQuestion, id: `sv1-${String(n)}`, name: `Card ${String(n)}`, number: String(n) };
}

function expectNoGarbage(): void {
  const text = document.body.textContent;
  expect(text).not.toMatch(/NaN|undefined|\[object|Infinity/);
}

afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
  visit('/');
});

describe('answers of the wrong kind on the search page', () => {
  const good = searchBodyOf([holo, unownExclamation], { sort: 'price_desc', ordered: 2, total: 2 });
  const wrong: readonly (readonly [string, unknown])[] = [
    ['a price that is a string', { ...good, data: [{ ...holo, price: '70.81' }] }],
    ['a price that is a list', { ...good, data: [{ ...holo, price: [] }] }],
    [
      'a price without its close',
      { ...good, data: [{ ...holo, price: { ...holo.price, close: undefined } }] },
    ],
    [
      'a price whose close is a number',
      { ...good, data: [{ ...holo, price: { ...holo.price, close: 70.81 } }] },
    ],
    ['a variant that is a number', { ...good, data: [{ ...holo, variant: 5 }] }],
    ['a variant without a key', { ...good, data: [{ ...holo, variant: { finish: 'holo' } }] }],
    [
      'a row without the variant field',
      { ...good, data: [{ ...unownExclamation, variant: undefined }] },
    ],
    ['a sort that no order is', { ...good, meta: { ...good.meta, sort: 'sideways' } }],
    ['an ordered count that is a string', { ...good, meta: { ...good.meta, ordered: '2' } }],
    ['an ordered count that is a list', { ...good, meta: { ...good.meta, ordered: [2] } }],
    ['a meta without the ordered count', { ...good, meta: { ...good.meta, ordered: undefined } }],
    ['data that is an object', { ...good, data: { a: holo } }],
    ['a total that is null', { ...good, meta: { ...good.meta, total: null } }],
  ];

  it.each(wrong)(
    'shows the problem block for %s, with no price and no garbage',
    async (_name, body) => {
      stubSearch(() => jsonResponse(body));
      visit('/search?q=unown&sort=price_desc');
      render(<App />);
      expect(await screen.findByText(/Search failed: /)).toBeTruthy();
      expect(screen.queryByRole('list', { name: 'Search results' })).toBeNull();
      expect(screen.queryByText(/Ordered by price/)).toBeNull();
      expect(document.body.textContent).not.toContain('$');
      expectNoGarbage();
    },
  );

  it('never prints a count of matches that is negative, not a number or "NaN", for a count that is a number but odd', async () => {
    for (const [ordered, total] of [
      [200, 37],
      [5, 0],
      [100, 100],
      [0, 5],
    ] as const) {
      stubSearch(() => jsonResponse(searchBodyOf([holo], { sort: 'price_asc', ordered, total })));
      visit('/search?q=unown&sort=price_asc');
      render(<App />);
      await screen.findByRole('list', { name: 'Search results' });
      expectNoGarbage();
      const sentence = document.body.textContent.match(
        /Ordered by price[^.;]*|No matches to order by price/,
      );
      // Never "the top 100 of 37": the top count never reaches the number of matches in a sentence naming both.
      const named = /top ([\d,]+) of ([\d,]+) matches/.exec(sentence?.[0] ?? '');
      if (named !== null) {
        expect(Number((named[1] ?? '').replaceAll(',', ''))).toBeLessThan(
          Number((named[2] ?? '').replaceAll(',', '')),
        );
      }
      cleanup();
      vi.unstubAllGlobals();
    }
  });

  it('shows a row whose price has no variant, or whose variant has no price, as a row with no price, no "$" and no variant name', async () => {
    stubSearch(() =>
      jsonResponse(
        searchBodyOf([
          { ...unownExclamation, price: holo.price },
          { ...sprigatitoPromo, variant: holo.variant },
        ]),
      ),
    );
    visit('/search?q=unown');
    render(<App />);
    await screen.findByRole('list', { name: 'Search results' });
    for (const row of within(results()).getAllByRole('listitem')) {
      expect(visibleText(row)).toContain(NO_PRICE);
      expect(row.textContent).not.toContain('$');
      expect(row.textContent).not.toContain('Holo');
    }
    expectNoGarbage();
  });
});

describe('the order control through Back, Forward and a typed address', () => {
  it('keeps each order with its own rows through Back and Forward, and returns to page 1 on a change', async () => {
    const calls = stubSearch((url) => {
      if (url.includes('sort=price_desc')) {
        return jsonResponse(
          searchBodyOf([reverse, holo], { sort: 'price_desc', ordered: 2, total: 2 }),
        );
      }
      if (url.includes('sort=price_asc')) {
        return jsonResponse(
          searchBodyOf([holo, reverse], { sort: 'price_asc', ordered: 2, total: 2 }),
        );
      }
      return jsonResponse(searchBodyOf([unownExclamation], { total: 49, page: 2, pages: 3 }));
    });
    visit('/search?q=unown&page=2');
    render(<App />);
    await screen.findByRole('list', { name: 'Search results' });
    expect(hrefs()).toEqual(['/card/ex10-!']);

    fireEvent.click(screen.getByRole('button', { name: 'Price: high to low' }));
    await waitFor(() => {
      expect(hrefs()[0]).toBe('/card/svp-1?variant=svp-1%3Areverse%3A-%3A-');
    });
    expect(address()).toBe('/search?q=unown&sort=price_desc');

    fireEvent.click(screen.getByRole('button', { name: 'Price: low to high' }));
    await waitFor(() => {
      expect(hrefs()[0]).toBe('/card/ex10-%3F?variant=ex10-%3F%3Aholo%3A-%3A-');
    });
    expect(address()).toBe('/search?q=unown&sort=price_asc');

    const pressed = (): (string | null)[] =>
      within(screen.getByRole('group', { name: 'Order' }))
        .getAllByRole('button')
        .map((button) => button.getAttribute('aria-pressed'));
    expect(pressed()).toEqual(['false', 'false', 'true']);

    act(() => {
      window.history.back();
    });
    await waitFor(() => {
      expect(address()).toBe('/search?q=unown&sort=price_desc');
    });
    await waitFor(() => {
      expect(hrefs()[0]).toBe('/card/svp-1?variant=svp-1%3Areverse%3A-%3A-');
    });
    expect(pressed()).toEqual(['false', 'true', 'false']);

    act(() => {
      window.history.back();
    });
    await waitFor(() => {
      expect(address()).toBe('/search?q=unown&page=2');
    });
    await waitFor(() => {
      expect(hrefs()).toEqual(['/card/ex10-!']);
    });
    expect(pressed()).toEqual(['true', 'false', 'false']);

    act(() => {
      window.history.forward();
      window.history.forward();
    });
    await waitFor(() => {
      expect(address()).toBe('/search?q=unown&sort=price_desc');
    });
    expect(searchUrls(calls).every((url) => !/sort=price_desc&sort|page=2&sort/.test(url))).toBe(
      true,
    );
    expectNoGarbage();
  });

  it.each<readonly [string, string]>([
    ['/search?q=unown&sort=PRICE_DESC', '/v1/cards?q=unown'],
    ['/search?q=unown&sort=', '/v1/cards?q=unown'],
    ['/search?q=unown&sort=cheapest&page=2', '/v1/cards?q=unown&page=2'],
    ['/search?q=unown&sort[]=price_desc', '/v1/cards?q=unown'],
    ['/search?q=unown&sort=price_desc%00', '/v1/cards?q=unown'],
    ['/search?q=unown&sort=relevance', '/v1/cards?q=unown'],
    ['/search?q=unown&sort=price_asc&sort=price_desc', '/v1/cards?q=unown&sort=price_asc'],
    ['/search?q=unown&sort=price_desc&page=0', '/v1/cards?q=unown&sort=price_desc'],
    ['/search?q=unown&sort=price_desc&page=-3', '/v1/cards?q=unown&sort=price_desc'],
    ['/search?sort=price_desc', ''],
  ])('reads the address %s and asks for %s', async (path, expected) => {
    const calls = stubSearch(() => jsonResponse(searchBodyOf([holo])));
    visit(path);
    render(<App />);
    if (expected === '') {
      await screen.findByText('Type a card name, then press Search.');
      expect(searchUrls(calls)).toEqual([]);
      return;
    }
    await screen.findByRole('list', { name: 'Search results' });
    expect(searchUrls(calls)).toEqual([expected]);
    const wanted = expected.includes('sort=price_asc')
      ? 'Price: low to high'
      : expected.includes('sort=price_desc')
        ? 'Price: high to low'
        : 'Best match';
    expect(screen.getByRole('button', { name: wanted }).getAttribute('aria-pressed')).toBe('true');
  });

  it('answers a page past the end with the last page, links back from it, and never offers a next page', async () => {
    stubSearch(() =>
      jsonResponse(
        searchBodyOf([card(97), card(98), card(99), card(100)], {
          sort: 'price_asc',
          ordered: 100,
          total: 4800,
          page: 5,
          pages: 5,
        }),
      ),
    );
    visit('/search?q=unown&sort=price_asc&page=9');
    render(<App />);
    await screen.findByRole('list', { name: 'Search results' });
    const nav = screen.getByRole('navigation', { name: 'Result pages' });
    expect(nav.textContent).toContain('Page 5 of 5');
    expect(within(nav).queryByRole('link', { name: 'Next page' })).toBeNull();
    expect(within(nav).getByRole('link', { name: 'Previous page' }).getAttribute('href')).toBe(
      '/search?q=unown&sort=price_asc&page=4',
    );
  });
});

describe('two order changes before the first answer arrives', () => {
  it('shows the rows of the order last chosen, though the answer of the one before arrives after it', async () => {
    const held: { url: string; resolve: (response: Response) => void }[] = [];
    vi.stubGlobal('fetch', (input: string | URL): Promise<Response> => {
      const url = String(input);
      if (isFreshnessUrl(url)) {
        return Promise.resolve(jsonResponse(freshnessBody({ within_slo: true })));
      }
      if (!url.includes('sort='))
        return Promise.resolve(jsonResponse(searchBodyOf([unownExclamation])));
      return new Promise<Response>((resolve) => {
        held.push({ url, resolve });
      });
    });
    visit('/search?q=unown');
    render(<App />);
    await screen.findByRole('list', { name: 'Search results' });
    fireEvent.click(screen.getByRole('button', { name: 'Price: high to low' }));
    fireEvent.click(screen.getByRole('button', { name: 'Price: low to high' }));
    await waitFor(() => {
      expect(held.map((entry) => entry.url)).toContain('/v1/cards?q=unown&sort=price_asc');
    });
    const asc = held.find((entry) => entry.url.endsWith('sort=price_asc'));
    const desc = held.find((entry) => entry.url.endsWith('sort=price_desc'));
    await act(async () => {
      asc?.resolve(
        jsonResponse(searchBodyOf([holo, reverse], { sort: 'price_asc', ordered: 2, total: 2 })),
      );
      await Promise.resolve();
    });
    await waitFor(() => {
      expect(hrefs()[0]).toBe('/card/ex10-%3F?variant=ex10-%3F%3Aholo%3A-%3A-');
    });
    await act(async () => {
      desc?.resolve(
        jsonResponse(searchBodyOf([reverse, holo], { sort: 'price_desc', ordered: 2, total: 2 })),
      );
      await Promise.resolve();
    });
    expect(address()).toBe('/search?q=unown&sort=price_asc');
    expect(hrefs()[0]).toBe('/card/ex10-%3F?variant=ex10-%3F%3Aholo%3A-%3A-');
    expect(
      screen.getByRole('button', { name: 'Price: low to high' }).getAttribute('aria-pressed'),
    ).toBe('true');
  });
});

describe('a row under hostile names and keys', () => {
  it('links a card id with ?, ! and a dot and a variant key to the same variant, whatever the characters', () => {
    // Pure round trip: the link's two parts decode to the card id and the variant key the row named.
    const ids = ['ex10-?', 'ex10-!', 'base1-4', 'a.b_c-1', 'sv9-100'];
    const keys = ['holo:-:-', 'normal:1st:-', 'reverse:-:cosmos-holo', 'holo:unlimited:-'];
    stubSearch(() =>
      jsonResponse(
        searchBodyOf(
          ids.flatMap((id, at) => {
            const naming = keys[at % keys.length] ?? 'holo:-:-';
            return [pricedRow({ ...unownQuestion, id }, naming, searchPriceOf())];
          }),
        ),
      ),
    );
    visit('/search?q=unown');
    render(<App />);
    return screen.findByRole('list', { name: 'Search results' }).then(() => {
      const links = hrefs();
      expect(links).toHaveLength(ids.length);
      links.forEach((href, at) => {
        const url = new URL(href ?? '', 'http://example.test');
        expect(decodeURIComponent(url.pathname)).toBe(`/card/${ids[at] ?? ''}`);
        expect(url.searchParams.get('variant')).toBe(
          `${ids[at] ?? ''}:${keys[at % keys.length] ?? ''}`,
        );
        expect([...url.searchParams.keys()]).toEqual(['variant']);
      });
    });
  });

  it('renders markup and a 3,000-character name as text, in one row, with no element made of it', async () => {
    const hostile = {
      ...unownQuestion,
      name: `<img src="x" onerror="window.__t06=1">${'W'.repeat(3000)}`,
      set_name: '</li></ol><script>window.__t06=2</script>',
      variant: {
        variant_key: 'ex10-?:holo:-:<b>bold</b>',
        finish: 'holo',
        edition: '-',
        treatment: '<b>bold</b>',
      },
      price: searchPriceOf(),
    };
    stubSearch(() => jsonResponse(searchBodyOf([hostile])));
    visit('/search?q=unown');
    render(<App />);
    await screen.findByRole('list', { name: 'Search results' });
    const list = results();
    expect(within(list).getAllByRole('listitem')).toHaveLength(1);
    expect(list.querySelector('script')).toBeNull();
    expect(list.querySelector('b')).toBeNull();
    expect(list.querySelector('img[src="x"]')).toBeNull();
    expect(Reflect.get(window, '__t06')).toBeUndefined();
    expect(visibleText(list)).toContain('Holo, <b>bold</b>');
    expect(visibleText(list)).toContain('</li></ol><script>');
    expect(within(list).getByRole('link').getAttribute('href')).toBe(
      `/card/ex10-%3F?variant=${encodeURIComponent('ex10-?:holo:-:<b>bold</b>')}`,
    );
  });
});

describe('honesty in every state of the search page', () => {
  const days = ['2026-10-13', '2026-10-12', '2026-10-09'] as const;
  const mixed = [
    pricedRow(card(1), 'holo:-:-', searchPriceOf({ day: days[0], close: '10.00' })),
    card(2),
    pricedRow(card(3), 'normal:-:-', searchPriceOf({ day: days[1], close: '1000.00' })),
    pricedRow(
      card(4),
      'reverse:-:-',
      searchPriceOf({
        day: days[2],
        close: '0.00',
        previous_close: null,
        previous_day: null,
        change: null,
        change_pct: null,
      }),
    ),
    card(5),
  ];
  const sameDay = [
    pricedRow(card(1), 'holo:-:-', searchPriceOf({ close: '5.00' })),
    card(2),
    pricedRow(card(3), 'holo:-:-', searchPriceOf({ close: '6.00' })),
  ];
  const states: readonly (readonly [string, string, unknown])[] = [
    ['relevance, rows of three days', '/search?q=card', searchBodyOf(mixed)],
    ['relevance, one day', '/search?q=card', searchBodyOf(sameDay)],
    [
      'price_desc, rows of three days',
      '/search?q=card&sort=price_desc',
      searchBodyOf(mixed, { sort: 'price_desc', ordered: 100, total: 4800 }),
    ],
    [
      'price_asc, one day, page 2',
      '/search?q=card&sort=price_asc&page=2',
      searchBodyOf(sameDay, { sort: 'price_asc', ordered: 37, total: 37, page: 2, pages: 2 }),
    ],
    [
      'degraded',
      '/search?q=card',
      { ...searchBodyOf(mixed), meta: { ...degradedSearchPage.meta } },
    ],
  ];

  it.each(states)(
    'puts a source and an "as of" day on every price, one stamp, nothing live, in %s',
    async (_name, path, body) => {
      stubSearch(() => jsonResponse(body));
      visit(path);
      render(<App />);
      await screen.findByRole('list', { name: 'Search results' });
      const rows = (body as ReturnType<typeof searchBodyOf>).data;
      const priced = rows.filter((row) => row.price !== null);
      const named = namedRows(results());
      expect(named).toHaveLength(rows.length);
      named.forEach((entry, at) => {
        const row = rows[at];
        if (row?.price === null || row === undefined) {
          expect(entry.name).toContain(NO_PRICE);
          expect(entry.name).not.toMatch(/\$|as of|TCGCSV|Market price/);
          expect(entry.row.textContent).not.toContain('$');
          expect(visibleText(entry.row)).not.toMatch(/Close of|as of/);
        } else {
          expect(entry.name).toContain(`Market price $${grouped(row.price.close)}`);
          expect(entry.name).toContain(`as of ${row.price.day} from TCGCSV (daily close)`);
        }
      });
      const distinct = new Set(priced.map((row) => row.price?.day));
      const stamps = visibleStamps(screen.getByRole('main'));
      expect(stamps).toHaveLength(1);
      expect(stamps[0]?.textContent).toBe(
        distinct.size === 1
          ? `as of ${[...distinct][0] ?? ''} from TCGCSV (daily close)`
          : `Closes from ${[...distinct].sort()[0] ?? ''} to ${[...distinct].sort().at(-1) ?? ''} from TCGCSV (daily close)`,
      );
      const closeOf = within(results()).queryAllByText(/^Close of /);
      expect(closeOf).toHaveLength(distinct.size === 1 ? 0 : priced.length);
      expect(
        screen.getAllByText(/TCGplayer market prices via TCGCSV, collected once a day\./),
      ).toHaveLength(1);
      for (const word of FORBIDDEN_WORDS) expect(document.body.textContent).not.toMatch(word);
      expectNoGarbage();
    },
  );

  it('shows no listing or thin-market note on a row, even when the answer carries those fields', async () => {
    const flagged = { ...searchPriceOf(), thin_market: true, lowest_listing: '0.10' };
    stubSearch(() => jsonResponse(searchBodyOf([pricedRow(card(1), 'holo:-:-', flagged)])));
    visit('/search?q=card');
    render(<App />);
    await screen.findByRole('list', { name: 'Search results' });
    const text = `${document.body.textContent} ${namedRows(results())[0]?.name ?? ''}`;
    expect(text).not.toMatch(/thin|listing|lowest|0\.10/i);
    expect(visibleText(results())).toContain('$70.81');
  });

  it('shows the order control, the sentence for no match, and no price, stamp or footnote on an empty price order', async () => {
    stubSearch(() =>
      jsonResponse(searchBodyOf([], { sort: 'price_desc', ordered: 0, total: 0, pages: 1 })),
    );
    visit('/search?q=zzzz&sort=price_desc');
    render(<App />);
    expect(await screen.findByText('No matches to order by price.')).toBeTruthy();
    expect(screen.getByRole('group', { name: 'Order' })).toBeTruthy();
    expect(document.body.textContent).not.toMatch(/\$|as of|collected once a day/);
    expect(screen.queryByRole('navigation', { name: 'Result pages' })).toBeNull();
  });
});

describe('the "top 100" sentence at its boundaries', () => {
  it.each<readonly [string, number, number, string]>([
    ['price_desc', 2, 2, 'Ordered by price among all 2 matches; cards with no price come last.'],
    ['price_asc', 99, 99, 'Ordered by price among all 99 matches; cards with no price come last.'],
    [
      'price_asc',
      100,
      100,
      'Ordered by price among all 100 matches; cards with no price come last.',
    ],
    [
      'price_desc',
      100,
      101,
      'Ordered by price among the top 100 of 101 matches; cards with no price come last.',
    ],
    [
      'price_asc',
      100,
      1234567,
      'Ordered by price among the top 100 of 1,234,567 matches; cards with no price come last.',
    ],
  ])('says the true counts in %s for %i ordered of %i', async (sort, ordered, total, sentence) => {
    stubSearch(() =>
      jsonResponse(
        searchBodyOf([holo], {
          sort: sort === 'price_asc' ? 'price_asc' : 'price_desc',
          ordered,
          total,
        }),
      ),
    );
    visit(`/search?q=unown&sort=${sort}`);
    render(<App />);
    await screen.findByRole('list', { name: 'Search results' });
    expect(screen.getAllByText(/Ordered by price/)).toHaveLength(1);
    expect(screen.getByText(/Ordered by price/).textContent).toBe(sentence);
    expect(screen.queryByText(/top 100 of (?:[1-9]|[1-9][0-9]|100) matches/)).toBeNull();
  });

  it('prints no sentence when a relevance answer carries an ordered count, and none while loading or failing', async () => {
    stubSearch(() =>
      jsonResponse(searchBodyOf([holo], { sort: 'relevance', ordered: 100, total: 4800 })),
    );
    visit('/search?q=unown');
    render(<App />);
    await screen.findByRole('list', { name: 'Search results' });
    expect(screen.queryByText(/Ordered by price|order by price/)).toBeNull();
    cleanup();
    stubSearch(() => problemResponse({ ...validationProblem, status: 503, instance: '/v1/cards' }));
    visit('/search?q=unown&sort=price_asc');
    render(<App />);
    expect(screen.queryByText(/Ordered by price|order by price/)).toBeNull();
    await screen.findByText(/Search failed: /);
    expect(screen.queryByText(/Ordered by price|order by price/)).toBeNull();
  });
});

describe('the word cap and the other 422s under the search box', () => {
  it('shows the problem block, not an empty page or a message under the box, for a 422 on sort', async () => {
    stubSearch(() =>
      problemResponse({
        ...validationProblem,
        instance: '/v1/cards',
        errors: { sort: ['The selected sort is invalid.'] },
      }),
    );
    visit('/search?q=unown&sort=price_desc');
    render(<App />);
    expect(await screen.findByText(/Search failed: /)).toBeTruthy();
    expect(
      screen.getByRole('searchbox', { name: 'Card name' }).getAttribute('aria-invalid'),
    ).toBeNull();
  });

  it('shows the problem block for a 422 on q whose message list is empty', async () => {
    stubSearch(() =>
      problemResponse({ ...validationProblem, instance: '/v1/cards', errors: { q: [] } }),
    );
    visit('/search?q=unown');
    render(<App />);
    expect(await screen.findByText(/Search failed: /)).toBeTruthy();
    expect(
      screen.getByRole('searchbox', { name: 'Card name' }).getAttribute('aria-invalid'),
    ).toBeNull();
  });

  it('clears the message when the text changes, stops 33 words again on submit, and sends 32 words to the page', async () => {
    const cap = 'A search can have at most 32 words.';
    const calls = stubSearch(() =>
      problemResponse({ ...validationProblem, instance: '/v1/cards', errors: { q: [cap] } }),
    );
    visit(`/search?q=${encodeURIComponent(Array.from({ length: 33 }, () => 'a').join(' '))}`);
    render(<App />);
    const form = screen.getByRole('search');
    expect(await within(form).findByText(cap)).toBeTruthy();
    const box = within(form).getByRole('searchbox', { name: 'Card name' });
    fireEvent.change(box, { target: { value: 'unown' } });
    expect(within(form).queryByText(cap)).toBeNull();
    expect(box.getAttribute('aria-invalid')).toBeNull();
    fireEvent.change(box, { target: { value: Array.from({ length: 33 }, () => 'b').join('　') } });
    fireEvent.submit(box);
    expect(within(form).getByText(cap)).toBeTruthy();
    expect(searchUrls(calls)).toHaveLength(1);
    fireEvent.change(box, { target: { value: Array.from({ length: 32 }, () => 'b').join(' ') } });
    fireEvent.submit(box);
    expect(address()).toContain('/search?q=');
  });
});

describe('the stale-prices banner on search', () => {
  it('shows one banner and asks freshness once, through an order change and a page change', async () => {
    const calls = stubFetch((url) =>
      isFreshnessUrl(url)
        ? jsonResponse(freshnessBody())
        : jsonResponse(
            url.includes('sort=')
              ? searchBodyOf([reverse, holo], { sort: 'price_desc', ordered: 2, total: 2 })
              : searchBodyOf([holo, reverse]),
          ),
    );
    visit('/search?q=unown');
    render(<App />);
    expect(await screen.findByText(BANNER)).toBeTruthy();
    await screen.findByRole('list', { name: 'Search results' });
    fireEvent.click(screen.getByRole('button', { name: 'Price: high to low' }));
    await waitFor(() => {
      expect(hrefs()[0]).toBe('/card/svp-1?variant=svp-1%3Areverse%3A-%3A-');
    });
    fireEvent.click(screen.getByRole('button', { name: 'Best match' }));
    await waitFor(() => {
      expect(hrefs()[0]).toBe('/card/ex10-%3F?variant=ex10-%3F%3Aholo%3A-%3A-');
    });
    expect(screen.getAllByText(BANNER)).toHaveLength(1);
    expect(calls.filter((call) => isFreshnessUrl(call.url))).toHaveLength(1);
    // The banner sits outside the page's main region, so the one stamp of the list is not the banner.
    expect(screen.getByRole('main').textContent).not.toContain('Prices are out of date');
    expect(visibleStamps(screen.getByRole('main'))).toHaveLength(1);
  });

  it('shows no banner when the freshness answer is not the documented shape, and still shows the rows', async () => {
    stubFetch((url) =>
      isFreshnessUrl(url)
        ? jsonResponse({ data: { within_slo: 'no' } })
        : jsonResponse(searchBodyOf([holo])),
    );
    visit('/search?q=unown');
    render(<App />);
    await screen.findByRole('list', { name: 'Search results' });
    expect(screen.queryByText(/Prices are out of date/)).toBeNull();
    expectNoGarbage();
  });
});
