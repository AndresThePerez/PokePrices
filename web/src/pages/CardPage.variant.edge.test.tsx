import { act, cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi, type MockInstance } from 'vitest';
import App from '../App';
import type { CardDetail } from '../api/client';
import Link from '../Link';
import { navigate } from '../router';
import { cardVariantPath } from '../routes';
import { pageTitle } from '../title';
import {
  answerMovers,
  deoxysCardBody,
  firstClosePrice,
  historyFor,
  isHistoryUrl,
  isMoversUrl,
  jsonResponse,
  pricedCardBody,
  stubFetch,
  unownCard,
  type VariantPrice,
} from '../test/fixtures';
import { resetRecorder } from '../test/lightweightChartsFake';

// jsdom has no canvas: the chart library is replaced by its recording fake.
vi.mock('lightweight-charts', () => import('../test/lightweightChartsFake'));

const DEOXYS_HOLO = 'ex13-3:holo:-:-';
const DEOXYS_REVERSE = 'ex13-3:reverse:-:-';

type Variant = CardDetail['data']['variants'][number];

function variantOf(
  key: string,
  finish: string,
  edition: string,
  treatment: string,
  price: VariantPrice | null,
): Variant {
  return {
    variant_key: key,
    finish,
    edition,
    treatment,
    sources: [
      {
        source: 'tcgplayer',
        product_id: 88123,
        subtype: 'Holofoil',
        group_id: 1399,
        series_epoch: 1,
        map_snapshot_day: '2026-10-11',
      },
    ],
    price,
  };
}

function priceOf(fields: Partial<VariantPrice>): VariantPrice {
  return { ...firstClosePrice, ...fields };
}

// Unown ? (its id needs encoding in the path): four variants apart by finish, edition and
// treatment, each priced on its own day with its own change, and one with no price at all.
const PLAIN = 'ex10-?:holo:unlimited:-';
const FIRST_ED = 'ex10-?:reverse:1st:-';
const STAMPED = 'ex10-?:holo:unlimited:stamped';
const UNPRICED = 'ex10-?:normal:unlimited:-';

const trioBody = {
  data: {
    ...unownCard,
    variants: [
      variantOf(
        PLAIN,
        'holo',
        'unlimited',
        '-',
        priceOf({
          close: '70.81',
          day: '2026-10-12',
          as_of: '2026-10-12T20:05:44Z',
          previous_close: '69.36',
          previous_day: '2026-10-11',
          change: '1.45',
          change_pct: '2.09',
        }),
      ),
      variantOf(
        FIRST_ED,
        'reverse',
        '1st',
        '-',
        priceOf({ close: '12.34', day: '2026-10-11', as_of: '2026-10-11T20:05:44Z' }),
      ),
      variantOf(
        STAMPED,
        'holo',
        'unlimited',
        'stamped',
        priceOf({
          close: '500.00',
          day: '2026-10-10',
          as_of: '2026-10-10T20:05:44Z',
          previous_close: '450.00',
          previous_day: '2026-10-09',
          change: '50.00',
          change_pct: '11.11',
        }),
      ),
      variantOf(UNPRICED, 'normal', 'unlimited', '-', null),
    ],
  },
  meta: { degraded: false },
} satisfies CardDetail;

const singleBody = {
  data: {
    ...unownCard,
    id: 'svp-1',
    name: 'Sprigatito',
    variants: [
      variantOf(
        'svp-1:holo:-:promo',
        'holo',
        '-',
        'promo',
        priceOf({ close: '3.10', day: '2026-10-12', as_of: '2026-10-12T20:05:44Z' }),
      ),
    ],
  },
  meta: { degraded: false },
} satisfies CardDetail;

const BODIES: Readonly<Record<string, CardDetail>> = {
  '/v1/cards/ex13-3': deoxysCardBody,
  '/v1/cards/ex10-%3F': trioBody,
  '/v1/cards/svp-1': singleBody,
};

/** Serves the movers, the cards above and their 1M histories; returns every requested URL. */
function serve(): string[] {
  const urls: string[] = [];
  stubFetch((url) => {
    urls.push(url);
    if (isMoversUrl(url)) return answerMovers(url);
    const cardUrl = url.split('/prices?')[0] ?? url;
    const body = BODIES[cardUrl];
    if (body === undefined) return new Error(`no request expected: ${url}`);
    return jsonResponse(isHistoryUrl(url) ? historyFor(body) : body);
  });
  return urls;
}

function visit(path: string): void {
  window.history.replaceState(null, '', path);
}

function address(): string {
  return window.location.pathname + window.location.search + window.location.hash;
}

async function hero(): Promise<HTMLElement> {
  const label = await screen.findByText(/^Market price · /);
  const block = label.parentElement;
  if (block === null) throw new Error('the hero label has no block');
  return block;
}

async function heroLabel(): Promise<string | null> {
  return (await screen.findByText(/^Market price · /)).textContent;
}

async function historySection(): Promise<HTMLElement> {
  const section = await screen.findByRole('region', { name: 'Price history' });
  await within(section).findByText(/^as of /);
  return section;
}

function pressed(section: HTMLElement): string[] {
  const group = within(section).queryByRole('group', { name: 'Variant' });
  if (group === null) return [];
  return within(group)
    .getAllByRole('button')
    .filter((button) => button.getAttribute('aria-pressed') === 'true')
    .map((button) => button.textContent);
}

function summary(section: HTMLElement): string {
  return section.querySelector('p.sr-only')?.textContent ?? '';
}

function cardRequests(urls: readonly string[], cardUrl: string): number {
  return urls.filter((url) => url === cardUrl).length;
}

let consoleError: MockInstance<typeof console.error>;
let consoleWarn: MockInstance<typeof console.warn>;

beforeEach(() => {
  resetRecorder();
  consoleError = vi.spyOn(console, 'error');
  consoleWarn = vi.spyOn(console, 'warn');
});

afterEach(() => {
  cleanup();
  consoleError.mockRestore();
  consoleWarn.mockRestore();
  visit('/');
});

describe('hostile and odd variant parameters open the default variant, quietly', () => {
  const long = 'x'.repeat(5000);
  const script = '<img src=x onerror="window.__pwned=1"><script>window.__pwned=2</script>';
  const cases: readonly (readonly [string, string])[] = [
    ['whitespace only', '?variant=%20%20%09'],
    ['the wrong letter case', '?variant=EX13-3%3AREVERSE%3A-%3A-'],
    ['a double-encoded colon', '?variant=ex13-3%253Areverse%253A-%253A-'],
    ['a doubled parameter, the unknown one first', `?variant=nope&variant=${DEOXYS_REVERSE}`],
    ['a 5,000 character value', `?variant=${long}`],
    ['markup and script in the value', `?variant=${encodeURIComponent(script)}`],
    ['raw markup in the value', `?variant=<b>bold</b>"'><svg/onload=1>`],
    ['a percent sign before junk', '?variant=%zz%'],
    ['a plus sign', '?variant=ex13-3:reverse:-:-+'],
    ['an encoded hash and ampersand', '?variant=ex13-3%3Areverse%23%26x%3D1'],
    ['a raw ampersand splitting the key', '?variant=ex13-3:rev&erse:-:-'],
    ['an unknown key with a trailing unknown parameter', '?variant=zz&utm=1'],
  ];

  it.each(cases)('opens the holo for %s, rendering nothing of the value', async (_name, search) => {
    const urls = serve();
    visit(`/card/ex13-3${search}`);
    render(<App />);
    expect((await hero()).querySelector('p')?.textContent).toBe('Market price · holo');
    expect(pressed(await historySection())).toEqual(['holo']);
    expect(screen.queryByRole('alert')).toBeNull();
    expect(screen.queryByText(/^Could not load/)).toBeNull();
    expect(document.querySelector('script, svg[onload], img[onerror], b')).toBeNull();
    expect(document.body.innerHTML).not.toContain('pwned');
    expect(document.body.innerHTML).not.toContain('xxxxxxxxxx');
    expect((window as { __pwned?: unknown }).__pwned).toBeUndefined();
    // The value never reaches an API request: the card and its 1M history, as with no parameter.
    expect(urls.filter((url) => url.startsWith('/v1/cards/'))).toEqual([
      '/v1/cards/ex13-3',
      '/v1/cards/ex13-3/prices?range=1M',
    ]);
    expect(consoleError).not.toHaveBeenCalled();
    expect(consoleWarn).not.toHaveBeenCalled();
  });

  it.each([
    [
      'a doubled parameter names it first',
      `?variant=${encodeURIComponent(DEOXYS_REVERSE)}&variant=nope`,
    ],
    [
      'an unknown parameter trails it',
      `?variant=${encodeURIComponent(DEOXYS_REVERSE)}&utm=1#prices`,
    ],
    ['an unknown parameter leads it', `?ref=search&variant=${DEOXYS_REVERSE}`],
  ])('opens the named variant when %s', async (_name, search) => {
    serve();
    visit(`/card/ex13-3${search}`);
    render(<App />);
    expect(await heroLabel()).toBe('Market price · reverse');
    expect(consoleError).not.toHaveBeenCalled();
  });

  it('makes one card request and one history request for five unknown values in one visit', async () => {
    const urls = serve();
    visit('/card/ex13-3?variant=one');
    render(<App />);
    await historySection();
    for (const value of ['two', '%E0%A4%A', 'ex5-17%3Areverse%3A-%3A-', '']) {
      act(() => {
        navigate(`/card/ex13-3?variant=${value}`);
      });
      expect(await heroLabel()).toBe('Market price · holo');
    }
    act(() => {
      navigate(cardVariantPath('ex13-3', DEOXYS_REVERSE));
    });
    expect(await heroLabel()).toBe('Market price · reverse');
    expect(cardRequests(urls, '/v1/cards/ex13-3')).toBe(1);
    expect(cardRequests(urls, '/v1/cards/ex13-3/prices?range=1M')).toBe(1);
    expect(urls.every((url) => !url.includes('variant'))).toBe(true);
    expect(consoleError).not.toHaveBeenCalled();
  });
});

describe('every part of the page follows the variant the link names', () => {
  const expected = [
    {
      key: PLAIN,
      label: 'holo, unlimited',
      close: '$70.81',
      day: '2026-10-12',
      change: '▲ +$1.45 (+2.09%) vs previous close (2026-10-11)',
    },
    {
      key: FIRST_ED,
      label: 'reverse, 1st',
      close: '$12.34',
      day: '2026-10-11',
      change: 'No previous close yet',
    },
    {
      key: STAMPED,
      label: 'holo, unlimited, stamped',
      close: '$500.00',
      day: '2026-10-10',
      change: '▲ +$50.00 (+11.11%) vs previous close (2026-10-09)',
    },
  ] as const;

  it.each(expected)(
    'opens $label in the hero, the chart, the price sentence and the pressed segment',
    async (row) => {
      const { key, label, close, day, change } = row;
      serve();
      const link = cardVariantPath('ex10-?', key);
      visit(link);
      render(<App />);
      const block = await hero();
      expect(within(block).getByText(`Market price · ${label}`)).toBeTruthy();
      expect(within(block).getByText(close)).toBeTruthy();
      expect(within(block).getByText(change)).toBeTruthy();
      expect(within(block).getByText(`as of ${day} from TCGCSV (daily close)`)).toBeTruthy();
      for (const other of expected.filter((one) => one.key !== key)) {
        expect(within(block).queryByText(other.close)).toBeNull();
        expect(
          within(block).queryByText(`as of ${other.day} from TCGCSV (daily close)`),
        ).toBeNull();
      }
      const section = await historySection();
      expect(pressed(section)).toEqual([label]);
      const capital = label.charAt(0).toUpperCase() + label.slice(1);
      expect(summary(section).startsWith(`${capital}, 1M: `)).toBe(true);
      expect(summary(section)).toContain(`${close} on ${day}.`);
      expect(document.title).toBe(pageTitle('Unown'));
      expect(screen.getByRole('heading', { level: 1 }).textContent).toBe('Unown');
      // Every priced and unpriced row keeps its own Add buttons, whichever variant is shown.
      const prices = screen.getByRole('region', { name: 'Prices' });
      expect(within(prices).getAllByRole('listitem')).toHaveLength(4);
      expect(address()).toBe(link);
      expect(consoleError).not.toHaveBeenCalled();
    },
  );

  it('opens the unpriced variant a link names, without an error', async () => {
    serve();
    visit(cardVariantPath('ex10-?', UNPRICED));
    render(<App />);
    expect(await heroLabel()).toBe('Market price · normal, unlimited');
    expect(pressed(await historySection())).toEqual(['normal, unlimited']);
    expect(screen.queryByRole('alert')).toBeNull();
    expect(consoleError).not.toHaveBeenCalled();
  });

  it('opens the unpriced reverse of the fixture card when the link names it', async () => {
    const urls: string[] = [];
    stubFetch((url) => {
      urls.push(url);
      return jsonResponse(isHistoryUrl(url) ? historyFor(pricedCardBody) : pricedCardBody);
    });
    visit(cardVariantPath('ex10-?', 'ex10-?:reverse:unlimited:-'));
    render(<App />);
    expect(await heroLabel()).toBe('Market price · reverse, unlimited');
    expect(screen.queryByRole('alert')).toBeNull();
    expect(consoleError).not.toHaveBeenCalled();
  });

  it('opens a one-variant card on that variant for its own key and for an unknown one', async () => {
    for (const search of ['?variant=svp-1%3Aholo%3A-%3Apromo', '?variant=nope']) {
      serve();
      visit(`/card/svp-1${search}`);
      render(<App />);
      const block = await hero();
      expect(within(block).getByText('Market price · holo, promo')).toBeTruthy();
      expect(within(block).getByText('$3.10')).toBeTruthy();
      expect(within(block).getByText('No previous close yet')).toBeTruthy();
      const section = await historySection();
      // One variant: no Variant group to press, and the chart summarises that variant.
      expect(within(section).queryByRole('group', { name: 'Variant' })).toBeNull();
      expect(summary(section).startsWith('Holo, promo, 1M: ')).toBe(true);
      expect(screen.queryByRole('alert')).toBeNull();
      cleanup();
    }
    expect(consoleError).not.toHaveBeenCalled();
  });
});

describe('navigation keeps the address as the one source of the variant', () => {
  it('opens another card on its own default, then this card again on its default, after a choice', async () => {
    serve();
    visit(cardVariantPath('ex13-3', DEOXYS_REVERSE));
    render(<App />);
    expect(await heroLabel()).toBe('Market price · reverse');
    act(() => {
      navigate('/card/ex10-%3F');
    });
    await screen.findByRole('heading', { level: 1, name: 'Unown' });
    expect(await heroLabel()).toBe('Market price · holo, unlimited');
    act(() => {
      navigate('/card/ex13-3');
    });
    await screen.findByRole('heading', { level: 1, name: 'Deoxys δ' });
    expect(await heroLabel()).toBe('Market price · holo');
    // Back twice: the earlier entries reopen what each address says.
    act(() => {
      window.history.back();
    });
    await waitFor(() => {
      expect(address()).toBe('/card/ex10-%3F');
    });
    expect(await heroLabel()).toBe('Market price · holo, unlimited');
    act(() => {
      window.history.back();
    });
    await waitFor(() => {
      expect(address()).toBe(cardVariantPath('ex13-3', DEOXYS_REVERSE));
    });
    await screen.findByRole('heading', { level: 1, name: 'Deoxys δ' });
    await waitFor(async () => {
      expect(await heroLabel()).toBe('Market price · reverse');
    });
    expect(consoleError).not.toHaveBeenCalled();
  });

  it('switches variant on an in-app link to the same card, with no new card request and no focus move', async () => {
    const urls = serve();
    visit('/card/ex13-3');
    render(
      <>
        <App />
        <Link to={cardVariantPath('ex13-3', DEOXYS_REVERSE)}>to reverse</Link>
      </>,
    );
    expect(await heroLabel()).toBe('Market price · holo');
    await historySection();
    const h1 = screen.getByRole('heading', { level: 1 });
    const link = screen.getByRole('link', { name: 'to reverse' });
    link.focus();
    fireEvent.click(link);
    expect(address()).toBe(cardVariantPath('ex13-3', DEOXYS_REVERSE));
    expect(await heroLabel()).toBe('Market price · reverse');
    // The same card page stays mounted: its heading is the same node, focus is not taken.
    expect(screen.getByRole('heading', { level: 1 })).toBe(h1);
    expect(document.activeElement).toBe(link);
    expect(cardRequests(urls, '/v1/cards/ex13-3')).toBe(1);
    expect(cardRequests(urls, '/v1/cards/ex13-3/prices?range=1M')).toBe(1);
  });

  it('a choice on the page asks for nothing, keeps title and focus, and does not scroll', async () => {
    const scrollTo = vi.fn();
    const scrollIntoView = vi.fn();
    vi.stubGlobal('scrollTo', scrollTo);
    const original = Object.getOwnPropertyDescriptor(Element.prototype, 'scrollIntoView');
    Object.defineProperty(Element.prototype, 'scrollIntoView', {
      configurable: true,
      writable: true,
      value: scrollIntoView,
    });
    try {
      const urls = serve();
      visit(cardVariantPath('ex10-?', PLAIN));
      render(<App />);
      const section = await historySection();
      const before = urls.length;
      const length = window.history.length;
      const title = document.title;
      const h1 = screen.getByRole('heading', { level: 1 });
      const group = within(section).getByRole('group', { name: 'Variant' });
      for (const [name, key] of [
        ['holo, unlimited, stamped', STAMPED],
        ['reverse, 1st', FIRST_ED],
      ] as const) {
        const button = within(group).getByRole('button', { name });
        button.focus();
        fireEvent.click(button);
        expect(address()).toBe(cardVariantPath('ex10-?', key));
        expect(await heroLabel()).toBe(`Market price · ${name}`);
        expect(document.activeElement).toBe(within(group).getByRole('button', { name }));
      }
      expect(urls.length).toBe(before);
      expect(window.history.length).toBe(length);
      expect(document.title).toBe(title);
      expect(screen.getByRole('heading', { level: 1 })).toBe(h1);
      expect(scrollTo).not.toHaveBeenCalled();
      expect(scrollIntoView).not.toHaveBeenCalled();
    } finally {
      if (original === undefined) {
        Reflect.deleteProperty(Element.prototype, 'scrollIntoView');
      } else {
        Object.defineProperty(Element.prototype, 'scrollIntoView', original);
      }
    }
  });

  it('a choice on a card address with another parameter and a hash still opens the choice after a reload', async () => {
    serve();
    visit('/card/ex13-3?ref=search#prices');
    render(<App />);
    const section = await historySection();
    expect(await heroLabel()).toBe('Market price · holo');
    const length = window.history.length;
    fireEvent.click(
      within(within(section).getByRole('group', { name: 'Variant' })).getByRole('button', {
        name: 'reverse',
      }),
    );
    expect(window.location.pathname).toBe('/card/ex13-3');
    expect(new URLSearchParams(window.location.search).get('variant')).toBe(DEOXYS_REVERSE);
    expect(window.history.length).toBe(length);
    expect(await heroLabel()).toBe('Market price · reverse');
    // A reload: a fresh app on the address as it now stands.
    const now = address();
    cleanup();
    serve();
    visit(now);
    render(<App />);
    expect(await heroLabel()).toBe('Market price · reverse');
  });

  it('leaves the address untouched by a choice when the variant was already the one named', async () => {
    serve();
    const link = cardVariantPath('ex13-3', DEOXYS_HOLO);
    visit(link);
    render(<App />);
    const section = await historySection();
    const length = window.history.length;
    fireEvent.click(
      within(within(section).getByRole('group', { name: 'Variant' })).getByRole('button', {
        name: 'holo',
      }),
    );
    expect(address()).toBe(link);
    expect(window.history.length).toBe(length);
    expect(await heroLabel()).toBe('Market price · holo');
  });
});
