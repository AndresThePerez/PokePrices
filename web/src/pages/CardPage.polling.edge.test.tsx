import { act, cleanup, render, screen, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import App from '../App';
import { navigate } from '../router';
import {
  cardNotFoundPlainProblem,
  cardWithPrices,
  historyFor,
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

let visibility: DocumentVisibilityState = 'visible';

function setVisibility(state: DocumentVisibilityState): void {
  visibility = state;
  act(() => {
    document.dispatchEvent(new Event('visibilitychange', { bubbles: true }));
  });
}

// Only the polling clock is faked (setInterval, clearInterval, Date), as in CardPage.polling.test.
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

function visit(path: string): void {
  window.history.replaceState(null, '', path);
}

function replies(...answers: (() => Response)[]): string[] {
  const urls: string[] = [];
  stubFetch((url) => {
    if (isHistoryUrl(url)) return jsonResponse(historyFor(cardWithPrices(risingPrice)));
    urls.push(url);
    const answer = answers[Math.min(urls.length - 1, answers.length - 1)];
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

describe('card page polling, edge cases (D9)', () => {
  it('does not refetch when the tab returns while the data is still fresh, and polls once at 300,000 ms', async () => {
    const urls = replies(() => jsonResponse(cardWithPrices(risingPrice)));
    await openCard();
    await advance(100_000);
    setVisibility('hidden');
    setVisibility('visible');
    await settle();
    expect(urls).toEqual([CARD_URL]);
    await advance(199_999);
    expect(urls).toEqual([CARD_URL]);
    await advance(1);
    expect(urls).toEqual([CARD_URL, CARD_URL]);
  });

  it('asks exactly once when the tab returns at the staleness boundary, not twice', async () => {
    const urls = replies(() => jsonResponse(cardWithPrices(risingPrice)));
    await openCard();
    setVisibility('hidden');
    await advance(300_000);
    expect(urls).toHaveLength(1);
    setVisibility('visible');
    await settle();
    expect(urls).toHaveLength(2);
    await advance(299_999);
    expect(urls).toHaveLength(2);
  });

  it('replaces a failed first load with the card when a later poll succeeds', async () => {
    const urls = replies(
      () => problemResponse(upstreamUnavailableProblem),
      () => jsonResponse(cardWithPrices(risingPrice)),
    );
    await openCard();
    expect(screen.getByRole('alert').textContent).toContain('Could not load this card');
    await advance(300_000);
    expect(urls).toHaveLength(2);
    expect(screen.queryByRole('alert')).toBeNull();
    expect(screen.getByRole('heading', { level: 1 }).textContent).toBe('Unown');
  });

  it('keeps the card on screen when a later poll answers card-not-found', async () => {
    replies(
      () => jsonResponse(cardWithPrices(risingPrice)),
      () => problemResponse(cardNotFoundPlainProblem),
    );
    await openCard();
    await advance(300_000);
    expect(screen.getByRole('heading', { level: 1 }).textContent).toBe('Unown');
    expect(screen.queryByText('Card not found')).toBeNull();
    expect(
      within(screen.getByRole('region', { name: 'Prices' })).getByText('Market price $70.81'),
    ).toBeTruthy();
  });

  it('keeps the card on screen when a poll cannot reach the API at all', async () => {
    let calls = 0;
    stubFetch((url) => {
      if (isHistoryUrl(url)) return jsonResponse(historyFor(cardWithPrices(risingPrice)));
      calls += 1;
      return calls === 1 ? jsonResponse(cardWithPrices(risingPrice)) : new TypeError('offline');
    });
    await openCard();
    await advance(300_000);
    expect(calls).toBe(2);
    expect(screen.queryByRole('alert')).toBeNull();
    expect(screen.getByRole('heading', { level: 1 }).textContent).toBe('Unown');
  });

  it('shows the loading line, never the previous card, after in-app navigation to another card', async () => {
    const urls: string[] = [];
    vi.stubGlobal('fetch', (input: string | URL): Promise<Response> => {
      if (isHistoryUrl(String(input)))
        return Promise.resolve(jsonResponse(historyFor(cardWithPrices(risingPrice))));
      urls.push(String(input));
      if (urls.length === 1) return Promise.resolve(jsonResponse(cardWithPrices(risingPrice)));
      return new Promise<Response>(() => undefined);
    });
    await openCard();
    expect(screen.getByRole('heading', { level: 1 }).textContent).toBe('Unown');
    act(() => {
      navigate('/card/base1-4');
    });
    await settle();
    expect(urls).toEqual([CARD_URL, '/v1/cards/base1-4']);
    expect(screen.getByRole('status').textContent).toBe('Loading card base1-4…');
    expect(screen.queryByText('Unown')).toBeNull();
    vi.unstubAllGlobals();
  });

  it('starts every App mount with an empty cache: no card data survives a remount', async () => {
    replies(() => jsonResponse(cardWithPrices(risingPrice)));
    await openCard();
    expect(screen.getByRole('heading', { level: 1 }).textContent).toBe('Unown');
    cleanup();
    vi.stubGlobal('fetch', () => new Promise<Response>(() => undefined));
    render(<App />);
    await settle();
    expect(screen.getByRole('status').textContent).toBe('Loading card ex10-?…');
    expect(screen.queryByText('Unown')).toBeNull();
    vi.unstubAllGlobals();
  });

  it('aborts the request in flight when the page is left', async () => {
    let signal: AbortSignal | null | undefined;
    vi.stubGlobal('fetch', (_input: string | URL, init?: RequestInit): Promise<Response> => {
      signal = init?.signal;
      return new Promise<Response>(() => undefined);
    });
    await openCard();
    expect(signal?.aborted).toBe(false);
    cleanup();
    await settle();
    expect(signal?.aborted).toBe(true);
    vi.unstubAllGlobals();
  });

  it('keeps exactly one h1 and one banner on a card that is loading, failed or missing', async () => {
    vi.stubGlobal('fetch', () => new Promise<Response>(() => undefined));
    await openCard();
    expect(screen.getAllByRole('heading', { level: 1 })).toHaveLength(1);
    expect(screen.getAllByRole('banner')).toHaveLength(1);
    cleanup();
    vi.unstubAllGlobals();
    replies(() => problemResponse(upstreamUnavailableProblem));
    await openCard();
    expect(screen.getAllByRole('heading', { level: 1 })).toHaveLength(1);
    expect(screen.getAllByRole('banner')).toHaveLength(1);
    cleanup();
    replies(() => problemResponse(cardNotFoundPlainProblem));
    await openCard();
    expect(screen.getAllByRole('heading', { level: 1 })).toHaveLength(1);
    expect(screen.getAllByRole('banner')).toHaveLength(1);
  });
});
