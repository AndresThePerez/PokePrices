import { act, cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import App from '../App';
import type { CardDetail } from '../api/client';
import {
  FORBIDDEN_WORDS,
  cardBody,
  cardWithPrices,
  degradedCardBody,
  fallingPrice,
  freshnessBody,
  historyBody,
  isFreshnessUrl,
  isHistoryUrl,
  jsonResponse,
  pointOf,
  problemResponse,
  risingPrice,
  seriesOf,
  stubFetch,
  unmappedCardBody,
  upstreamUnavailableProblem,
} from '../test/fixtures';

// jsdom has no canvas: the chart library is replaced by its recording fake (T06 helper).
vi.mock('lightweight-charts', () => import('../test/lightweightChartsFake'));

const CARD_URL = '/v1/cards/ex10-%3F';
const HISTORY_1M = '/v1/cards/ex10-%3F/prices?range=1M';
const KEY = (index: number): string => `ex10-?:finish${String(index)}:unlimited:-`;
const FOOTNOTE = 'TCGplayer market prices via TCGCSV, collected once a day.';

type Card = CardDetail['data'];

function visit(path: string): void {
  window.history.replaceState(null, '', path);
}

function withCard(body: CardDetail, fields: Partial<Card>): CardDetail {
  return { ...body, data: { ...body.data, ...fields } };
}

function serve(card: CardDetail, reply: () => Response): string[] {
  const urls: string[] = [];
  stubFetch((url) => {
    urls.push(url);
    return isHistoryUrl(url) ? reply() : jsonResponse(card);
  });
  return urls;
}

/** Three priced variants finish0..2; the series of each is given by the caller. */
const threePriced = cardWithPrices(
  risingPrice,
  { ...fallingPrice, close: '68.06' },
  { ...risingPrice, close: '1234567.89' },
);

const series = (index: number, begins: string | null = '2026-10-11') =>
  seriesOf(
    KEY(index),
    begins === null ? [] : [pointOf('2026-10-11', '1.00'), pointOf('2026-10-12', '2.00')],
    begins,
  );

async function openCard(): Promise<void> {
  visit('/card/ex10-%3F');
  render(<App />);
  await screen.findByRole('region', { name: 'Sources' });
}

function heroOf(): HTMLElement {
  const block = screen.getByText(/^Market price · /).parentElement;
  if (block === null) throw new Error('no hero');
  return block;
}

function pressedVariant(): string[] {
  const group = screen.getByRole('group', { name: 'Variant' });
  return within(group)
    .getAllByRole('button')
    .filter((button) => button.getAttribute('aria-pressed') === 'true')
    .map((button) => button.textContent);
}

afterEach(() => {
  cleanup();
  visit('/');
});

describe('Card hero, review edge cases (D2-i1-T01)', () => {
  it('prints hostile card names and variant labels as text in the h1, hero label and Sources, and the placeholder initial stays an attribute', async () => {
    const hostile = '<b>x</b> &amp; ‮evil';
    const long = 'L'.repeat(200);
    const base = cardWithPrices(risingPrice, fallingPrice);
    const card = withCard(base, {
      name: '<img src=x onerror=alert(1)>&lt;',
      images: { small: '', large: '' },
      variants: base.data.variants.map((variant, index) => ({
        ...variant,
        finish: index === 0 ? hostile : long,
      })),
    });
    serve(card, () => jsonResponse(historyBody([])));
    await openCard();
    const main = screen.getByRole('main');
    expect(screen.getByRole('heading', { level: 1 }).textContent).toBe(
      '<img src=x onerror=alert(1)>&lt;',
    );
    expect(main.querySelector('b')).toBeNull();
    expect(main.querySelectorAll('img')).toHaveLength(0);
    expect(within(heroOf()).getByText(`Market price · ${hostile}, unlimited`)).toBeTruthy();
    const sources = screen.getByRole('region', { name: 'Sources' });
    expect(within(sources).getByText(`${hostile}, unlimited`)).toBeTruthy();
    expect(within(sources).getByText(`${long}, unlimited`)).toBeTruthy();
    const back = main.querySelector('[data-initial]');
    expect(back).not.toBeNull();
    expect(back?.getAttribute('data-initial')).toBe('<');
    expect(back?.textContent).toBe('');
  });

  it('gives an empty card name a card-back with an empty initial, no text, no img and no word undefined', async () => {
    const card = withCard(cardBody, { name: '   ', images: { small: '', large: '' } });
    serve(card, () => jsonResponse(historyBody([])));
    await openCard();
    const back = screen.getByRole('main').querySelector('[data-initial]');
    expect(back?.getAttribute('data-initial')).toBe('');
    expect(back?.textContent).toBe('');
    expect(back?.hasAttribute('aria-hidden')).toBe(false);
    expect(back?.getAttribute('role')).toBeNull();
    expect(screen.getByRole('main').textContent).not.toContain('undefined');
    expect(screen.queryAllByRole('img')).toHaveLength(0);
  });

  it('leaves no div[aria-hidden] on the page and no presentation role in Prices once the card has loaded, in every card state', async () => {
    const states: CardDetail[] = [
      threePriced,
      withCard(threePriced, { images: { small: '', large: '' } }),
      cardBody,
      unmappedCardBody,
      degradedCardBody,
      withCard(degradedCardBody, { images: { small: '', large: '' } }),
    ];
    for (const card of states) {
      serve(card, () => jsonResponse(historyBody([series(0), series(1), series(2)])));
      await openCard();
      // Let the history settle, so a late chart node cannot hide a violation.
      await new Promise((resolve) => {
        setTimeout(resolve, 20);
      });
      expect(document.querySelectorAll('div[aria-hidden]')).toHaveLength(0);
      const prices = screen.getByRole('region', { name: 'Prices' });
      expect(prices.querySelectorAll('[role="presentation"], [role="none"]')).toHaveLength(0);
      cleanup();
    }
  });

  it('keeps aria-pressed on the hero variant at all times, defaulting to the first priced variant that has a series', async () => {
    const urls = serve(threePriced, () =>
      jsonResponse(historyBody([series(0, null), series(1), series(2)])),
    );
    await openCard();
    await screen.findByRole('group', { name: 'Variant' });
    // finish0 is priced but holds no series: the default is finish1, not finish0 and not blank.
    expect(pressedVariant()).toEqual(['finish1, unlimited']);
    expect(within(heroOf()).getByText('Market price · finish1, unlimited')).toBeTruthy();
    expect(screen.queryByRole('button', { name: 'finish0, unlimited' })).toBeNull();
    for (const label of ['finish2, unlimited', 'finish1, unlimited', 'finish2, unlimited']) {
      fireEvent.click(screen.getByRole('button', { name: label }));
      expect(pressedVariant()).toEqual([label]);
      expect(within(heroOf()).getByText(`Market price · ${label}`)).toBeTruthy();
    }
    expect(urls).toEqual(['/v1/prices/freshness', CARD_URL, HISTORY_1M]);
  });

  it('shows the hero figure exactly as the Prices row does, grouped from the API string and never re-rounded', async () => {
    serve(threePriced, () => jsonResponse(historyBody([series(2)])));
    await openCard();
    // finish2 holds the only series: once the history lands the hero follows the chart onto it.
    expect(await within(screen.getByRole('main')).findByText('$1,234,567.89')).toBeTruthy();
    const hero = heroOf();
    expect(within(hero).getByText('$1,234,567.89')).toBeTruthy();
    const row = within(screen.getByRole('region', { name: 'Prices' })).getByText(
      'Market price $1,234,567.89',
    );
    expect(row.closest('li')?.textContent).toContain('finish2, unlimited');
    expect(hero.textContent).not.toMatch(/[eE]\+|NaN|undefined/);
  });

  it('asks only for the card when no variant is priced or the card has no variants, and never draws a hero', async () => {
    for (const card of [cardBody, unmappedCardBody]) {
      const urls = serve(card, () => jsonResponse(historyBody([])));
      await openCard();
      await new Promise((resolve) => {
        setTimeout(resolve, 20);
      });
      expect(urls).toEqual(['/v1/prices/freshness', CARD_URL]);
      expect(screen.queryByText(/^Market price/)).toBeNull();
      expect(screen.queryByRole('region', { name: 'Price history' })).toBeNull();
      expect(screen.queryByText(FOOTNOTE)).toBeNull();
      cleanup();
    }
  });

  it('puts the hero outside every region, role and Prices; Sources is one labelled region headed exactly Sources, in the pinned order', async () => {
    serve(threePriced, () => jsonResponse(historyBody([series(0), series(1), series(2)])));
    await openCard();
    const hero = heroOf();
    expect(hero.hasAttribute('role')).toBe(false);
    expect(hero.closest('section, [role]:not([role="article"])')).toBeNull();
    expect(hero.querySelectorAll('[role], [aria-hidden]')).toHaveLength(0);
    const sources = screen.getByRole('region', { name: 'Sources' });
    expect(sources.contains(hero)).toBe(false);
    const heading = within(sources).getByRole('heading', { name: 'Sources' });
    expect(heading.textContent).toBe('Sources');
    expect(screen.getAllByRole('heading', { name: 'Sources' })).toHaveLength(1);
    const text = sources.textContent;
    const at = [
      text.indexOf(FOOTNOTE),
      text.indexOf('TCGplayer product 88123'),
      text.indexOf('Catalog'),
    ];
    expect(at[0]).toBe('Sources'.length);
    expect(at[1]).toBeGreaterThan(at[0] ?? 0);
    expect(at[2]).toBeGreaterThan(at[1] ?? 0);
    expect(within(sources).getAllByText(FOOTNOTE)).toHaveLength(1);
    expect(screen.queryByText('TCGplayer products')).toBeNull();
  });

  it('keeps loading, failed and not-found free of the hero and Sources, with one status line that no skeleton wraps', async () => {
    visit('/card/ex10-%3F');
    stubFetch(() => new Promise<Response>(() => undefined) as unknown as Response);
    render(<App />);
    const status = await screen.findByRole('status');
    expect(screen.getAllByRole('status')).toHaveLength(1);
    expect(status.querySelector('[aria-hidden]')).toBeNull();
    const bars = Array.from(document.querySelectorAll('div[aria-hidden]'));
    expect(bars.length).toBeGreaterThan(0);
    for (const bar of bars) {
      expect(bar.contains(status)).toBe(false);
      expect(status.compareDocumentPosition(bar) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
    }
    expect(screen.queryByRole('region', { name: 'Sources' })).toBeNull();
    expect(screen.queryByText(/^Market price/)).toBeNull();
    cleanup();

    stubFetch(() => problemResponse(upstreamUnavailableProblem));
    visit('/card/ex10-%3F');
    render(<App />);
    await screen.findByRole('alert');
    expect(screen.getByRole('heading', { level: 1 }).textContent).toBe('Card ex10-?');
    expect(screen.queryByRole('region', { name: 'Sources' })).toBeNull();
    expect(screen.queryByText(/^Market price/)).toBeNull();
    expect(document.querySelectorAll('div[aria-hidden]')).toHaveLength(0);
  });

  it('never says live, real-time, today, now or 24h anywhere on a priced card page with a hero', async () => {
    serve(threePriced, () => jsonResponse(historyBody([series(0), series(1), series(2)])));
    await openCard();
    await screen.findByRole('group', { name: 'Variant' });
    fireEvent.click(screen.getByRole('button', { name: 'finish2, unlimited' }));
    const text = document.body.textContent;
    for (const pattern of FORBIDDEN_WORDS) expect(text).not.toMatch(pattern);
  });
});

async function settle(): Promise<void> {
  for (let round = 0; round < 3; round += 1) {
    await act(async () => {
      await new Promise((resolve) => {
        setTimeout(resolve, 0);
      });
    });
  }
}

describe('Card hero across the five-minute poll (D2-i1-T01, A1-4)', () => {
  beforeEach(() => {
    vi.useFakeTimers({ toFake: ['setInterval', 'clearInterval', 'Date'] });
    vi.setSystemTime(new Date('2031-02-03T04:05:06Z'));
  });

  afterEach(() => {
    vi.useRealTimers();
  });

  it('falls back to a priced variant, with no crash and no stale figure, when the chosen variant loses its price on a poll', async () => {
    const first = cardWithPrices(risingPrice, { ...fallingPrice, close: '68.06' });
    const second = cardWithPrices(risingPrice, null);
    const cards = [first, second];
    let asked = 0;
    const calls = stubFetch((url) => {
      if (isFreshnessUrl(url))
        return jsonResponse(freshnessBody({ within_slo: true, age_hours: 3.5 }));
      if (isHistoryUrl(url)) {
        return jsonResponse(
          historyBody([
            seriesOf(KEY(0), [pointOf('2026-10-11', '69.36'), pointOf('2026-10-12', '70.81')]),
            seriesOf(KEY(1), [pointOf('2026-10-11', '69.00'), pointOf('2026-10-12', '68.06')]),
          ]),
        );
      }
      const card = cards[Math.min(asked, cards.length - 1)];
      asked += 1;
      return jsonResponse(card);
    });
    window.history.replaceState(null, '', '/card/ex10-%3F');
    render(<App />);
    await settle();
    const variants = screen.getByRole('group', { name: 'Variant' });
    fireEvent.click(within(variants).getByRole('button', { name: 'finish1, unlimited' }));
    expect(screen.getByText('Market price · finish1, unlimited')).toBeTruthy();
    expect(screen.getByText('$68.06')).toBeTruthy();

    await act(async () => {
      await vi.advanceTimersByTimeAsync(300_000);
    });
    await settle();

    expect(asked).toBe(2);
    expect(calls.filter((call) => isFreshnessUrl(call.url))).toHaveLength(2);
    const label = screen.getByText(/^Market price · /);
    expect(label.textContent).toBe('Market price · finish0, unlimited');
    expect(within(label.parentElement as HTMLElement).getByText('$70.81')).toBeTruthy();
    expect(screen.queryByText('$68.06')).toBeNull();
    expect(screen.queryByText('Market price · finish1, unlimited')).toBeNull();
    // One priced variant is left: no Variant group, and the chart follows the hero.
    expect(screen.queryByRole('group', { name: 'Variant' })).toBeNull();
    const history = screen.getByRole('region', { name: 'Price history' });
    expect(history.querySelector('p.sr-only')?.textContent).toMatch(/^Finish0, unlimited, 1M: /);
  });
});
