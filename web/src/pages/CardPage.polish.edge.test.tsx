// Reviewer edge tests for the card page's image sizes, card-back fallback and TCGplayer product links: odd
// addresses in the srcset, an error then a variant switch or another card, product ids of every kind (the
// decoder shows the problem block or no link, never an address with "undefined" or "NaN" in it), the same links
// signed out, signed in and in the read-only demo beside the list-state lines, and that the product address is
// only ever an anchor's href, never a request.
import { act, cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi, type MockInstance } from 'vitest';
import App from '../App';
import type { Card, CardDetail } from '../api/client';
import { navigate } from '../router';
import {
  FORBIDDEN_WORDS,
  clearXsrfCookie,
  demoMe,
  freshnessBody,
  historyFor,
  holdingOf,
  isFreshnessUrl,
  isHistoryUrl,
  jsonResponse,
  portfolioBody,
  pricedCardBody,
  pricedUnownCard,
  setXsrfCookie,
  signedInMe,
  stubAccountFetch,
  stubFetch,
  unownCard,
  watchItemOf,
  watchlistBody,
} from '../test/fixtures';

vi.mock('lightweight-charts', () => import('../test/lightweightChartsFake'));

const PRODUCT_ADDRESS = /^https:\/\/www\.tcgplayer\.com\/product\/[1-9][0-9]*$/;
const HOLO = 'ex10-?:holo:unlimited:-';

let consoleError: MockInstance<typeof console.error>;

beforeEach(() => {
  consoleError = vi.spyOn(console, 'error');
});

afterEach(() => {
  const lines = consoleError.mock.calls.map((call) => call.map(String).join(' '));
  cleanup();
  clearXsrfCookie();
  vi.restoreAllMocks();
  window.history.replaceState(null, '', '/');
  expect(lines.filter((line) => /Cannot update a component|same key/.test(line))).toEqual([]);
});

function visit(path: string): void {
  window.history.replaceState(null, '', path);
}

function withCard(fields: Partial<Card>): CardDetail {
  return { data: { ...unownCard, ...fields }, meta: { degraded: false } };
}

function quiet(url: string): Response | undefined {
  return isFreshnessUrl(url) ? jsonResponse(freshnessBody({ within_slo: true })) : undefined;
}

async function show(body: CardDetail): Promise<{ main: HTMLElement; urls: string[] }> {
  const calls = stubFetch(
    (url) =>
      quiet(url) ?? (isHistoryUrl(url) ? jsonResponse(historyFor(body)) : jsonResponse(body)),
  );
  visit('/card/ex10-%3F');
  render(<App />);
  await screen.findByRole('heading', { level: 1, name: body.data.name });
  return { main: screen.getByRole('main'), urls: calls.map((call) => call.url) };
}

function pageImages(main: HTMLElement): HTMLImageElement[] {
  return [...main.querySelectorAll('img')];
}

/** The cards' two priced variants, so a variant switch exists. */
function bothPriced(): CardDetail {
  const [holo, reverse] = pricedUnownCard.variants;
  const price = holo?.price;
  if (holo === undefined || reverse === undefined || price === null || price === undefined) {
    throw new Error('the fixture lost a variant');
  }
  return {
    data: {
      ...pricedUnownCard,
      variants: [holo, { ...reverse, price: { ...price, close: '12.34' } }],
    },
    meta: { degraded: false },
  };
}

function withProductId(id: unknown): CardDetail {
  const [holo, reverse] = unownCard.variants;
  const source = holo?.sources[0];
  if (holo === undefined || reverse === undefined || source === undefined) throw new Error('lost');
  const bad = { ...source, product_id: id } as unknown as typeof source;
  return withCard({
    variants: [
      { ...holo, sources: [bad] },
      { ...reverse, sources: [] },
    ],
  });
}

function tcgplayerLinks(root: ParentNode): HTMLAnchorElement[] {
  return [...root.querySelectorAll<HTMLAnchorElement>('a[href*="tcgplayer"]')];
}

describe('the image srcset survives awkward addresses', () => {
  it.each([
    ['a space', 'https://images.example.com/a b.png', 'https://images.example.com/a b_hires.png'],
    ['a comma', 'https://images.example.com/a,b.png', 'https://images.example.com/a,b_hires.png'],
    ['a quote', "https://images.example.com/a'b.png", 'https://images.example.com/a"b_hires.png'],
    [
      'a tab and a newline',
      'https://images.example.com/a\tb.png',
      'https://images.example.com/a\nb_hires.png',
    ],
    ['a protocol-relative address', '//images.example.com/s.png', '//images.example.com/l.png'],
  ])('keeps exactly two candidates, small first, for %s', async (_label, small, large) => {
    const { main } = await show(withCard({ images: { small, large } }));
    const images = pageImages(main);
    expect(images).toHaveLength(1);
    const image = images[0];
    expect(image?.getAttribute('width')).toBe('245');
    expect(image?.getAttribute('height')).toBe('342');
    const srcset = image?.getAttribute('srcset') ?? '';
    // Candidates are split on whitespace and commas: two urls, each with a width, and no stray piece.
    const tokens = srcset.split(/\s+/);
    expect(tokens).toHaveLength(4);
    expect(tokens[1]).toBe('240w,');
    expect(tokens[3]).toBe('600w');
    expect(tokens[0]).not.toMatch(/[,\s]/);
    expect(tokens[2]).not.toMatch(/[,\s]/);
    expect(image?.getAttribute('src')).toBe(large);
  });

  it.each([
    ['javascript:', 'javascript:void(0)', 'javascript:void(1)'],
    [
      'data:',
      'data:image/gif;base64,R0lGODlhAQABAAAAACw=',
      'data:image/gif;base64,R0lGODlhAgACAAAAACw=',
    ],
  ])(
    'draws one image for a %s address and requests nothing outside the API',
    async (_label, small, large) => {
      const { main, urls } = await show(withCard({ images: { small, large } }));
      expect(pageImages(main)).toHaveLength(1);
      expect(urls.every((url) => url.startsWith('/v1/'))).toBe(true);
    },
  );

  it('draws the card back, with no image element, when the card has no image at all', async () => {
    const { main } = await show(withCard({ images: { small: '', large: '' } }));
    expect(pageImages(main)).toHaveLength(0);
    expect(main.querySelectorAll('[data-initial]')).toHaveLength(1);
  });

  it('writes the same width and height whether it offers one image or two', async () => {
    for (const images of [
      { small: 'https://images.example.com/s.png', large: 'https://images.example.com/l.png' },
      { small: 'https://images.example.com/s.png', large: '' },
      { small: '   ', large: 'https://images.example.com/l.png' },
    ]) {
      const { main } = await show(withCard({ images }));
      const [image] = pageImages(main);
      expect([image?.getAttribute('width'), image?.getAttribute('height')]).toEqual(['245', '342']);
      cleanup();
    }
  });
});

describe('a card image that fails', () => {
  it('stays the card back across a variant switch and after more errors, never looping', async () => {
    const { main } = await show(bothPriced());
    const [image] = pageImages(main);
    if (image === undefined) throw new Error('no image');
    fireEvent.error(image);
    expect(pageImages(main)).toHaveLength(0);
    expect(main.querySelectorAll('[data-initial]')).toHaveLength(1);
    const section = await screen.findByRole('region', { name: 'Price history' });
    const group = await within(section).findByRole('group', { name: 'Variant' });
    const other = within(group)
      .getAllByRole('button')
      .find((button) => button.getAttribute('aria-pressed') !== 'true');
    if (other === undefined) throw new Error('no other variant');
    fireEvent.click(other);
    await waitFor(() => {
      expect(window.location.search).toContain('variant=');
    });
    fireEvent.error(image);
    expect(pageImages(main)).toHaveLength(0);
    expect(main.querySelectorAll('[data-initial]')).toHaveLength(1);
    expect(main.querySelector('[data-initial]')?.getAttribute('data-initial')).toBe('U');
  });

  it('tries the next card’s own image again after one card’s image failed', async () => {
    const other = withCard({
      id: 'ex10-2',
      name: 'Unown B',
      images: {
        small: 'https://images.example.com/ex10/b.png',
        large: 'https://images.example.com/ex10/b_hires.png',
      },
    });
    stubFetch((url) => {
      const quietAnswer = quiet(url);
      if (quietAnswer !== undefined) return quietAnswer;
      if (url.includes('ex10-2'))
        return jsonResponse(isHistoryUrl(url) ? historyFor(other) : other);
      return jsonResponse(isHistoryUrl(url) ? historyFor(pricedCardBody) : pricedCardBody);
    });
    visit('/card/ex10-%3F');
    render(<App />);
    await screen.findByRole('heading', { level: 1, name: 'Unown' });
    const [first] = pageImages(screen.getByRole('main'));
    if (first === undefined) throw new Error('no image');
    fireEvent.error(first);
    expect(pageImages(screen.getByRole('main'))).toHaveLength(0);
    act(() => {
      navigate('/card/ex10-2');
    });
    await screen.findByRole('heading', { level: 1, name: 'Unown B' });
    const next = pageImages(screen.getByRole('main'));
    expect(next).toHaveLength(1);
    expect(next[0]?.getAttribute('src')).toBe('https://images.example.com/ex10/b_hires.png');
  });
});

describe('the TCGplayer product link', () => {
  it.each([
    ['one', 1, true],
    ['a hundred thousand written as 1e5', 1e5, true],
    ['the largest safe integer', Number.MAX_SAFE_INTEGER, true],
    ['zero', 0, false],
    ['minus one', -1, false],
    ['one and a half', 1.5, false],
    ['a number past the safe integers', Number.MAX_SAFE_INTEGER + 2, false],
    ['a numeric string', '42382', false],
    ['a string with letters after the number', '42382abc', false],
    ['a padded string', ' 42382 ', false],
    ['null', null, false],
    ['a missing field', undefined, false],
    ['an object', { id: 42382 }, false],
    ['a list', [42382], false],
    ['markup', '<a href="javascript:alert(1)">x</a>', false],
  ])(
    'shows a link only for a positive whole number, and never a broken address: %s',
    async (_label, id, linked) => {
      const body = withProductId(id);
      const calls = stubFetch(
        (url) =>
          quiet(url) ?? (isHistoryUrl(url) ? jsonResponse(historyFor(body)) : jsonResponse(body)),
      );
      visit('/card/ex10-%3F');
      render(<App />);
      await waitFor(() => {
        expect(
          screen.queryByRole('heading', { level: 1, name: 'Unown' }) ?? screen.queryByRole('alert'),
        ).not.toBeNull();
      });
      expect(screen.queryByText('This page could not be shown.')).toBeNull();
      const links = tcgplayerLinks(document.body);
      for (const link of links) {
        expect(link.getAttribute('href')).toMatch(PRODUCT_ADDRESS);
        expect(link.getAttribute('href')).not.toMatch(/undefined|NaN|null|[?#]/);
        expect(link.getAttribute('rel') ?? '').toContain('noopener');
      }
      expect(document.body.innerHTML).not.toMatch(/products?\/(undefined|NaN|null|\[object)/);
      if (linked) {
        expect(links).toHaveLength(1);
        expect(links[0]?.getAttribute('href')).toBe(
          `https://www.tcgplayer.com/product/${typeof id === 'number' ? String(id) : ''}`,
        );
      } else {
        expect(links).toHaveLength(0);
      }
      expect(calls.every((call) => !call.url.includes('tcgplayer'))).toBe(true);
    },
  );

  it('gives each variant with an id its own link, even when two variants share one product', async () => {
    const [holo, reverse] = unownCard.variants;
    const source = holo?.sources[0];
    if (holo === undefined || reverse === undefined || source === undefined)
      throw new Error('lost');
    const { main } = await show(
      withCard({
        variants: [
          { ...holo, sources: [source] },
          { ...reverse, sources: [{ ...source, subtype: 'Reverse Holofoil' }] },
        ],
      }),
    );
    const sources = within(main).getByRole('region', { name: 'Sources' });
    const links = tcgplayerLinks(sources);
    expect(links).toHaveLength(2);
    expect(links.map((link) => link.getAttribute('href'))).toEqual([
      'https://www.tcgplayer.com/product/88123',
      'https://www.tcgplayer.com/product/88123',
    ]);
    expect(tcgplayerLinks(main)).toHaveLength(2);
    for (const link of links) {
      expect(link.getAttribute('target')).toBe('_blank');
      expect(link.getAttribute('rel')?.split(/\s+/)).toEqual(expect.arrayContaining(['noopener']));
      // The name says where it goes and that it opens a new tab; it is no price and no promise to buy.
      expect(link.textContent).toBe('Product 88123 on TCGplayer (opens in a new tab)');
      expect(link.textContent).not.toMatch(/[$€£]|\bbuy\b|\bsale\b|\bdeal\b|\bcheap/i);
    }
    for (const pattern of FORBIDDEN_WORDS) expect(main.textContent).not.toMatch(pattern);
  });

  it('is only an anchor: clicking it, and rendering the page, send no request to TCGplayer', async () => {
    const calls = stubFetch(
      (url) =>
        quiet(url) ??
        (isHistoryUrl(url)
          ? jsonResponse(historyFor(pricedCardBody))
          : jsonResponse(pricedCardBody)),
    );
    visit('/card/ex10-%3F');
    render(<App />);
    await screen.findByRole('heading', { level: 1, name: 'Unown' });
    const before = calls.length;
    const [link] = screen.getAllByRole('link', { name: /on TCGplayer/ });
    if (link === undefined) throw new Error('no link');
    fireEvent.click(link);
    expect(calls.length).toBe(before);
    expect(window.location.pathname).toBe('/card/ex10-%3F');
    expect(calls.every((call) => call.url.startsWith('/v1/'))).toBe(true);
  });

  it('keeps the product address out of every source but the one that defines it and the card page anchor', () => {
    const sources = import.meta.glob<string>(
      ['../**/*.{ts,tsx}', '!../**/*.test.{ts,tsx}', '!../test/**', '!../**/*.d.ts'],
      { query: '?raw', import: 'default', eager: true },
    );
    const users = Object.entries(sources)
      .filter(([, text]) => /tcgplayer\.com|TCGPLAYER_PRODUCT_URL/.test(text))
      .map(([name]) => name)
      .sort();
    expect(users).toEqual(['../ui/siteLinks.ts', './CardPage.tsx']);
    const card = sources['./CardPage.tsx'] ?? '';
    const uses = card.split('\n').filter((line) => line.includes('TCGPLAYER_PRODUCT_URL'));
    // The import and the one template that builds the address; neither is a call to fetch.
    expect(uses).toHaveLength(2);
    expect(uses.some((line) => /fetch|request|get[A-Z]/.test(line))).toBe(false);
    expect(card).toContain('href={address}');
    expect(card).not.toMatch(/fetch\s*\(/);
  });
});

describe('the product links beside the list-state lines', () => {
  const unown = {
    variant_key: HOLO,
    card_id: 'ex10-?',
    finish: 'holo',
    edition: 'unlimited',
    treatment: '-',
    card: { name: 'Unown', set_name: 'Unseen Forces', number: '?' },
  };
  const watched = watchlistBody([watchItemOf(unown)]);
  const held = portfolioBody([holdingOf({ ...unown, quantity: 3, value: '212.43' })], {
    total_value: '212.43',
    priced: 1,
    unpriced: 0,
  });

  async function open(me: unknown): Promise<HTMLElement> {
    setXsrfCookie();
    stubAccountFetch({
      'GET /v1/prices/freshness': jsonResponse(freshnessBody({ within_slo: true })),
      'GET /v1/cards/ex10-%3F': jsonResponse(pricedCardBody),
      'GET /v1/cards/ex10-%3F/prices?range=1M': jsonResponse(historyFor(pricedCardBody)),
      'GET /v1/me': jsonResponse(me),
      'GET /v1/watchlist': jsonResponse(watched),
      'GET /v1/portfolio': jsonResponse(held),
    });
    visit('/card/ex10-%3F');
    render(<App />);
    await screen.findByRole('heading', { level: 1, name: 'Unown' });
    const prices = await screen.findByRole('region', { name: 'Prices' });
    await within(prices).findByText('In your portfolio: 3.');
    return screen.getByRole('main');
  }

  it.each([
    ['a signed-in session', signedInMe],
    ['the read-only demo', demoMe],
  ])(
    'shows the same two links and still finds both lines by their exact text for %s',
    async (_label, me) => {
      const main = await open(me);
      expect(within(main).getAllByText('On your watchlist.')).toHaveLength(1);
      expect(within(main).getAllByText('In your portfolio: 3.')).toHaveLength(1);
      expect(tcgplayerLinks(main).map((link) => link.getAttribute('href'))).toEqual([
        'https://www.tcgplayer.com/product/88123',
        'https://www.tcgplayer.com/product/88124',
      ]);
      const prices = within(main).getByRole('region', { name: 'Prices' });
      expect(tcgplayerLinks(prices)).toHaveLength(0);
      for (const pattern of FORBIDDEN_WORDS) expect(main.textContent).not.toMatch(pattern);
    },
  );
});
