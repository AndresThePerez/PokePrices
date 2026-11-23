import { act, cleanup, render, screen, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import App from '../App';
import {
  FORBIDDEN_WORDS,
  cardNotFoundPlainProblem,
  cardWithPrices,
  freshnessBody,
  historyFor,
  isFreshnessUrl,
  isHistoryUrl,
  jsonResponse,
  problemResponse,
  risingPrice,
  stubFetch,
  upstreamUnavailableProblem,
} from '../test/fixtures';

// jsdom has no canvas: the chart library is replaced by its recording fake (T06 helper).
vi.mock('lightweight-charts', () => import('../test/lightweightChartsFake'));

const CARD_URL = '/v1/cards/ex10-%3F';
const AS_OF_1012 = 'as of 2026-10-12 from TCGCSV (daily close)';

/** The next day's close as the API would serve it after the 2026-10-13 ingest. */
const nextDayPrice = {
  ...risingPrice,
  close: '68.06',
  day: '2026-10-13',
  as_of: '2026-10-13T20:05:44Z',
  previous_close: '70.81',
  previous_day: '2026-10-12',
  change: '-2.75',
  change_pct: '-3.88',
};

let visibility: DocumentVisibilityState = 'visible';

function setVisibility(state: DocumentVisibilityState): void {
  visibility = state;
  act(() => {
    document.dispatchEvent(new Event('visibilitychange', { bubbles: true }));
  });
}

/**
 * Lets answers, renders and TanStack's notifications settle. Only the polling clock is faked
 * (setInterval and Date); the zero-delay timeouts TanStack notifies through stay real, because a
 * faked zero-delay timeout created during a tick is pushed 1 ms past the tick and never fires.
 */
async function settle(): Promise<void> {
  for (let round = 0; round < 3; round += 1) {
    await act(async () => {
      await new Promise((resolve) => {
        setTimeout(resolve, 0);
      });
    });
  }
}

/** Moves the polling clock by ms, then lets everything settle. */
async function advance(ms: number): Promise<void> {
  await act(async () => {
    await vi.advanceTimersByTimeAsync(ms);
  });
  await settle();
}

function visit(path: string): void {
  window.history.replaceState(null, '', path);
}

/** The shell's freshness request, answered within its SLO so no banner shows. */
const FRESHNESS_URL = '/v1/prices/freshness';

/** Answers each card request with the next reply; the last reply repeats. */
function replies(...answers: (() => Response)[]): string[] {
  const urls: string[] = [];
  let cardCalls = 0;
  stubFetch((url) => {
    if (isHistoryUrl(url)) return jsonResponse(historyFor(cardWithPrices(risingPrice)));
    urls.push(url);
    if (isFreshnessUrl(url)) {
      return jsonResponse(freshnessBody({ within_slo: true, age_hours: 3.5 }));
    }
    cardCalls += 1;
    const answer = answers[Math.min(cardCalls - 1, answers.length - 1)];
    if (answer === undefined) throw new Error('no reply configured');
    return answer();
  });
  return urls;
}

async function openCard(): Promise<void> {
  visit('/card/ex10-%3F');
  render(<App />);
  await settle();
}

function prices(): HTMLElement {
  return screen.getByRole('region', { name: 'Prices' });
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
  cleanup();
  vi.useRealTimers();
  Reflect.deleteProperty(document, 'visibilityState');
  visit('/');
});

describe('card page polling (D9, T03)', () => {
  it('asks again after 300,000 ms while the tab is visible, and not before', async () => {
    const urls = replies(() => jsonResponse(cardWithPrices(risingPrice)));
    await openCard();
    expect(screen.getByRole('heading', { level: 1 }).textContent).toBe('Unown');
    expect(urls).toEqual([FRESHNESS_URL, CARD_URL]);
    await advance(299_999);
    expect(urls).toEqual([FRESHNESS_URL, CARD_URL]);
    await advance(1);
    expect(urls).toEqual([FRESHNESS_URL, CARD_URL, FRESHNESS_URL, CARD_URL]);
  });

  it('asks nothing while the tab is hidden, then once on becoming visible with stale data', async () => {
    const urls = replies(() => jsonResponse(cardWithPrices(risingPrice)));
    await openCard();
    expect(urls).toHaveLength(2);
    setVisibility('hidden');
    await advance(600_000);
    expect(urls).toHaveLength(2);
    setVisibility('visible');
    await settle();
    expect(urls).toEqual([FRESHNESS_URL, CARD_URL, FRESHNESS_URL, CARD_URL]);
    expect(within(prices()).getByText(AS_OF_1012)).toBeTruthy();
  });

  it('keeps the card, its prices and as-of line when a poll answers 503, with no alert', async () => {
    const urls = replies(
      () => jsonResponse(cardWithPrices(risingPrice)),
      () => problemResponse(upstreamUnavailableProblem),
    );
    await openCard();
    await advance(300_000);
    expect(urls).toHaveLength(4);
    expect(screen.getByRole('heading', { level: 1 }).textContent).toBe('Unown');
    expect(within(prices()).getByText('Market price $70.81')).toBeTruthy();
    expect(
      within(prices()).getByText('▲ +$1.45 (+2.09%) vs previous close (2026-10-11)'),
    ).toBeTruthy();
    expect(within(prices()).getByText(AS_OF_1012)).toBeTruthy();
    expect(screen.queryByRole('alert')).toBeNull();
    expect(screen.queryByText(/Could not load/)).toBeNull();
  });

  it('still shows a first-load failure with its request id, and a first-load 404 as Card not found', async () => {
    replies(() => problemResponse(upstreamUnavailableProblem));
    await openCard();
    const alert = screen.getByRole('alert');
    expect(alert.textContent).toContain('Could not load this card: Upstream Unavailable');
    expect(alert.textContent).toContain('Request ID req-t46-upstream-0001');
    cleanup();
    replies(() => problemResponse(cardNotFoundPlainProblem));
    await openCard();
    expect(screen.getByRole('heading', { level: 1 }).textContent).toBe('Card not found');
    expect(screen.queryByRole('alert')).toBeNull();
  });

  it('replaces the price lines with the new close, change and day when a poll brings a new day', async () => {
    const urls = replies(
      () => jsonResponse(cardWithPrices(risingPrice)),
      () => jsonResponse(cardWithPrices(nextDayPrice)),
    );
    await openCard();
    const before = within(prices()).getByRole('listitem').textContent;
    expect(before).toBe(
      `finish0, unlimitedMarket price $70.81▲ +$1.45 (+2.09%) vs previous close (2026-10-11)${AS_OF_1012}`,
    );
    await advance(300_000);
    expect(urls).toHaveLength(4);
    expect(within(prices()).getByRole('listitem').textContent).toBe(
      'finish0, unlimitedMarket price $68.06▼ -$2.75 (-3.88%) vs previous close (2026-10-12)as of 2026-10-13 from TCGCSV (daily close)',
    );
    expect(prices().textContent).not.toContain('2026-10-11');
    expect(prices().textContent).not.toMatch(/\bnew\b|updated/i);
  });

  it('never shows a forbidden word or the faked year in the header and the polled card page (D14)', async () => {
    replies(
      () => jsonResponse(cardWithPrices(risingPrice)),
      () => problemResponse(upstreamUnavailableProblem),
      () => jsonResponse(cardWithPrices(nextDayPrice)),
    );
    await openCard();
    await advance(300_000);
    await advance(300_000);
    expect(within(prices()).getByText('as of 2026-10-13 from TCGCSV (daily close)')).toBeTruthy();
    // The hero above the chart repeats the chosen variant's as-of line (D2 T01).
    const hero = screen.getByText(/^Market price · /).parentElement;
    expect(hero).not.toBeNull();
    expect(
      within(hero as HTMLElement).getByText('as of 2026-10-13 from TCGCSV (daily close)'),
    ).toBeTruthy();
    const text = document.body.textContent;
    expect(screen.getByRole('banner').textContent).toContain('PokéPrices');
    for (const pattern of FORBIDDEN_WORDS) expect(text).not.toMatch(pattern);
    expect(text).not.toContain('2031');
  });
});
