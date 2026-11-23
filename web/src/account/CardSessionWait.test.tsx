// The card page while the session is not yet known (T09 fix round 1, point B): with the XSRF-TOKEN
// cookie, until GET /v1/me (and, signed in, both lists) has answered, no variant shows an account
// control; each holds the Add row's space (an empty, hidden box of the same height), so nothing
// below moves when the controls arrive. The demo never shows an Add button, at any moment. A 401,
// a failed /me or a failed list read shows what it showed before; without the cookie the Add
// buttons show at once and the page sends what it sent before.
import { act, cleanup, render, screen, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import App from '../App';
import {
  FORBIDDEN_WORDS,
  clearXsrfCookie,
  demoMe,
  emptyPortfolioBody,
  historyFor,
  holdingOf,
  jsonResponse,
  portfolioBody,
  pricedCardBody,
  problemResponse,
  serverErrorProblem,
  setXsrfCookie,
  signedInMe,
  unauthenticatedProblem,
  watchItemOf,
  watchlistBody,
} from '../test/fixtures';

vi.mock('lightweight-charts', () => import('../test/lightweightChartsFake'));

const HOLO = 'ex10-?:holo:unlimited:-';
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

const ADD_BUTTONS = [
  'Add holo, unlimited to your watchlist',
  'Add holo, unlimited to your portfolio',
  'Add reverse, unlimited to your watchlist',
  'Add reverse, unlimited to your portfolio',
];

afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
  clearXsrfCookie();
  window.localStorage.clear();
  window.history.replaceState(null, '', '/');
});

async function settle(): Promise<void> {
  for (let i = 0; i < 6; i += 1) {
    await act(async () => {
      await new Promise((resolve) => setTimeout(resolve, 0));
    });
  }
}

/** A fetch answer the test releases later. */
function later(): { readonly promise: Promise<Response>; release: (response: Response) => void } {
  let release: (response: Response) => void = () => undefined;
  const promise = new Promise<Response>((resolve) => {
    release = resolve;
  });
  return {
    promise,
    release: (response) => {
      release(response);
    },
  };
}

type Answer = Response | Error | Promise<Response>;

/** The card page's requests, by "METHOD path"; each answer is used for every call to its key. */
function stub(answers: Readonly<Record<string, () => Answer>>): string[] {
  const calls: string[] = [];
  const base: Record<string, () => Answer> = {
    'GET /v1/prices/freshness': () =>
      jsonResponse({ data: { day: '2026-10-12', age_hours: 1.5, within_slo: true } }),
    'GET /v1/cards/ex10-%3F': () => jsonResponse(pricedCardBody),
    'GET /v1/cards/ex10-%3F/prices?range=1M': () => jsonResponse(historyFor(pricedCardBody)),
    ...answers,
  };
  vi.stubGlobal('fetch', (input: string | URL, init?: RequestInit): Promise<Response> => {
    const key = `${init?.method ?? 'GET'} ${String(input)}`;
    calls.push(key);
    const answer = base[key]?.() ?? new Error(`no ${key}`);
    if (answer instanceof Error) return Promise.reject(answer);
    return answer instanceof Response ? Promise.resolve(answer.clone()) : answer;
  });
  return calls;
}

/** Every state of the Prices list the page went through: its account buttons and held boxes. */
interface Frame {
  readonly buttons: readonly string[];
  readonly held: number;
  readonly quantity: number;
}

function frameOf(): Frame | null {
  const region = document.getElementById('card-prices')?.closest('section') ?? null;
  if (region === null || region.querySelector('li') === null) return null;
  const buttons = [...region.querySelectorAll('input[type="button"], button')]
    .map((element) => element.getAttribute('aria-label') ?? '')
    .filter((name) => /^(Add|Remove|Edit) /.test(name));
  return {
    buttons,
    held: region.querySelectorAll('[data-held="account-controls"]').length,
    quantity: region.querySelectorAll('input[name="quantity"]').length,
  };
}

/** Records a frame after every DOM change from the first render on. */
function recordFrames(): Frame[] {
  const frames: Frame[] = [];
  const observer = new MutationObserver(() => {
    const frame = frameOf();
    if (frame !== null) frames.push(frame);
  });
  observer.observe(document.body, { childList: true, subtree: true, attributes: true });
  return frames;
}

async function openCard(): Promise<void> {
  window.history.replaceState(null, '', '/card/ex10-%3F');
  render(<App />);
  await screen.findByRole('heading', { level: 1, name: 'Unown' });
  await settle();
}

function prices(): HTMLElement {
  return screen.getByRole('region', { name: 'Prices' });
}

function rows(): HTMLElement[] {
  return within(prices()).getAllByRole('listitem');
}

function accountButtons(): string[] {
  return frameOf()?.buttons.slice() ?? [];
}

/** Each variant holds one empty, hidden box with the Add row's top gap and height. */
function expectHeld(): void {
  expect(rows()).toHaveLength(2);
  for (const row of rows()) {
    const boxes = row.querySelectorAll<HTMLElement>('[data-held="account-controls"]');
    expect(boxes).toHaveLength(1);
    const box = boxes[0];
    if (box === undefined) throw new Error('no held box');
    expect(box.getAttribute('aria-hidden')).toBe('true');
    expect(box.className.split(' ')).toEqual(expect.arrayContaining(['mt-3', 'min-h-11']));
    expect(box.childElementCount).toBe(0);
    expect(box.textContent).toBe('');
    // The held box is the last thing in the row, where the controls arrive.
    expect(row.lastElementChild?.contains(box) ?? false).toBe(true);
  }
  expect(accountButtons()).toEqual([]);
  expect(within(prices()).queryAllByRole('button')).toEqual([]);
  expect(prices().querySelectorAll('input')).toHaveLength(0);
}

describe('with the cookie, while GET /v1/me has not answered', () => {
  it('shows no account control on any variant and holds their space; signed in, the lines arrive in it', async () => {
    setXsrfCookie();
    const me = later();
    const calls = stub({
      'GET /v1/me': () => me.promise,
      'GET /v1/watchlist': () => jsonResponse(watched),
      'GET /v1/portfolio': () => jsonResponse(held),
    });
    const frames = recordFrames();
    await openCard();
    expect(prices().textContent).toMatch(/\$\d/);
    expectHeld();

    me.release(jsonResponse(signedInMe));
    await within(rows()[0] ?? prices()).findByText('In your portfolio: 3.');
    await settle();
    expect(within(rows()[0] ?? prices()).getByText('On your watchlist.')).toBeTruthy();
    expect(prices().querySelectorAll('[data-held="account-controls"]')).toHaveLength(0);
    expect(accountButtons()).toEqual([
      'Remove holo, unlimited from your watchlist',
      'Edit how many holo, unlimited you hold',
      'Remove holo, unlimited from your portfolio',
      'Add reverse, unlimited to your watchlist',
      'Add reverse, unlimited to your portfolio',
    ]);
    // No Add button showed at any moment before the lists said what they hold.
    const beforeLines = frames.slice(
      0,
      frames.findIndex((frame) => frame.held === 0),
    );
    expect(beforeLines.length).toBeGreaterThan(0);
    expect(beforeLines.every((frame) => frame.buttons.length === 0)).toBe(true);
    expect(frames.every((frame) => frame.held === 0 || frame.held === 2)).toBe(true);
    expect(calls).toEqual([
      'GET /v1/prices/freshness',
      'GET /v1/cards/ex10-%3F',
      'GET /v1/me',
      'GET /v1/cards/ex10-%3F/prices?range=1M',
      'GET /v1/watchlist',
      'GET /v1/portfolio',
    ]);
    for (const word of FORBIDDEN_WORDS) expect(prices().textContent).not.toMatch(word);
  });

  it('keeps holding the space, signed in, while either list has not answered', async () => {
    setXsrfCookie();
    const watchlist = later();
    stub({
      'GET /v1/me': () => jsonResponse(signedInMe),
      'GET /v1/watchlist': () => watchlist.promise,
      'GET /v1/portfolio': () => jsonResponse(held),
    });
    await openCard();
    expectHeld();
    watchlist.release(jsonResponse(watched));
    await within(rows()[0] ?? prices()).findByText('On your watchlist.');
    expect(within(rows()[0] ?? prices()).getByText('In your portfolio: 3.')).toBeTruthy();
    expect(prices().querySelectorAll('[data-held="account-controls"]')).toHaveLength(0);
  });

  it('the demo never shows an Add button: held space, then its two lines and no control that writes', async () => {
    setXsrfCookie();
    const me = later();
    stub({
      'GET /v1/me': () => me.promise,
      'GET /v1/watchlist': () => jsonResponse(watched),
      'GET /v1/portfolio': () => jsonResponse(held),
    });
    const frames = recordFrames();
    await openCard();
    expectHeld();
    me.release(jsonResponse(demoMe));
    await within(rows()[0] ?? prices()).findByText('In your portfolio: 3.');
    await settle();
    expect(within(rows()[0] ?? prices()).getByText('On your watchlist.')).toBeTruthy();
    expect(accountButtons()).toEqual([]);
    expect(prices().querySelectorAll('input')).toHaveLength(0);
    expect(frames.length).toBeGreaterThan(0);
    expect(frames.every((frame) => frame.buttons.length === 0 && frame.quantity === 0)).toBe(true);
  });

  it('a 401 gives the Add buttons, calmly', async () => {
    setXsrfCookie();
    stub({ 'GET /v1/me': () => problemResponse(unauthenticatedProblem) });
    await openCard();
    expect(accountButtons()).toEqual(ADD_BUTTONS);
    expect(prices().querySelectorAll('[data-held="account-controls"]')).toHaveLength(0);
    expect(screen.queryByRole('alert')).toBeNull();
  });

  it.each([
    ['a 500', () => problemResponse(serverErrorProblem('/v1/me'))],
    ['a network error', () => new Error('network down')],
    ['a wrong shape', () => jsonResponse({ data: 7 })],
  ] as const)('%s on /v1/me gives the Add buttons and no page-wide error', async (...row) => {
    const [, answer] = row;
    setXsrfCookie();
    stub({ 'GET /v1/me': answer });
    await openCard();
    expect(prices().textContent).toMatch(/\$\d/);
    expect(accountButtons()).toEqual(ADD_BUTTONS);
    expect(prices().querySelectorAll('[data-held="account-controls"]')).toHaveLength(0);
    expect(screen.queryByRole('alert')).toBeNull();
  });

  it('a failing list read shows what it showed before: the other list line, an Add for the failed one', async () => {
    setXsrfCookie();
    stub({
      'GET /v1/me': () => jsonResponse(signedInMe),
      'GET /v1/watchlist': () => problemResponse(serverErrorProblem('/v1/watchlist')),
      'GET /v1/portfolio': () => jsonResponse(held),
    });
    await openCard();
    await within(rows()[0] ?? prices()).findByText('In your portfolio: 3.');
    expect(accountButtons()).toEqual([
      'Add holo, unlimited to your watchlist',
      'Edit how many holo, unlimited you hold',
      'Remove holo, unlimited from your portfolio',
      'Add reverse, unlimited to your watchlist',
      'Add reverse, unlimited to your portfolio',
    ]);
    expect(prices().querySelectorAll('[data-held="account-controls"]')).toHaveLength(0);
    expect(screen.queryByRole('alert')).toBeNull();
  });
});

describe('without the cookie', () => {
  it('shows the Add buttons in the first render of the rows and sends what it sent before', async () => {
    const calls = stub({
      'GET /v1/me': () => jsonResponse(signedInMe),
      'GET /v1/watchlist': () => jsonResponse(watchlistBody([])),
      'GET /v1/portfolio': () => jsonResponse(emptyPortfolioBody),
    });
    const frames = recordFrames();
    await openCard();
    expect(frames.length).toBeGreaterThan(0);
    expect(frames[0]?.buttons).toEqual(ADD_BUTTONS);
    expect(frames.every((frame) => frame.held === 0)).toBe(true);
    expect(accountButtons()).toEqual(ADD_BUTTONS);
    expect(calls).toEqual([
      'GET /v1/prices/freshness',
      'GET /v1/cards/ex10-%3F',
      'GET /v1/cards/ex10-%3F/prices?range=1M',
    ]);
    expect(document.cookie).toBe('');
  });
});
