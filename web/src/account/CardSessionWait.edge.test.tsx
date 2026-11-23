// Adversarial tests for the card page's held account controls: every committed frame (a
// MutationObserver) of the demo in every state shows no Add, Remove, Edit or quantity control; a /me
// that never answers holds the space and keeps the prices; a /me refetch while a session is cached
// never makes a control vanish; a signed-in session with one list pending or failing; a cookie that
// appears mid-page; the first commit of a card page whose data is already cached.
import type { QueryClient } from '@tanstack/react-query';
import { act, cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import App from '../App';
import { navigate } from '../router';
import type * as QueryModule from '../query';
import {
  FORBIDDEN_WORDS,
  clearXsrfCookie,
  demoMe,
  historyFor,
  holdingOf,
  jsonResponse,
  portfolioBody,
  pricedCardBody,
  problemResponse,
  rawResponse,
  serverErrorProblem,
  setXsrfCookie,
  signedInMe,
  unauthenticatedProblem,
  watchItemOf,
  watchlistBody,
} from '../test/fixtures';

vi.mock('lightweight-charts', () => import('../test/lightweightChartsFake'));

const created = vi.hoisted(() => ({ clients: [] as QueryClient[] }));
vi.mock('../query', async (importOriginal) => {
  const actual = await importOriginal<typeof QueryModule>();
  return {
    ...actual,
    createQueryClient: (): QueryClient => {
      const client = actual.createQueryClient();
      created.clients.push(client);
      return client;
    },
  };
});

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

afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
  clearXsrfCookie();
  created.clients.length = 0;
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

type Answer = Response | Error | Promise<Response>;

function later() {
  let release: (response: Response) => void = () => undefined;
  const promise = new Promise<Response>((resolve) => {
    release = resolve;
  });
  return { promise, release };
}

/** The card page's requests by "METHOD path"; each key's answer is a function of the call's number. */
function stub(answers: Readonly<Record<string, (call: number) => Answer>>): string[] {
  const calls: string[] = [];
  const count = new Map<string, number>();
  const base: Record<string, (call: number) => Answer> = {
    'GET /v1/prices/freshness': () =>
      jsonResponse({ data: { day: '2026-10-12', age_hours: 1.5, within_slo: true } }),
    'GET /v1/cards/ex10-%3F': () => jsonResponse(pricedCardBody),
    'GET /v1/cards/ex10-%3F/prices?range=1M': () => jsonResponse(historyFor(pricedCardBody)),
    'GET /sanctum/csrf-cookie': () => {
      setXsrfCookie();
      return rawResponse(204, null);
    },
    ...answers,
  };
  vi.stubGlobal('fetch', (input: string | URL, init?: RequestInit): Promise<Response> => {
    const key = `${init?.method ?? 'GET'} ${String(input)}`;
    calls.push(key);
    const call = count.get(key) ?? 0;
    count.set(key, call + 1);
    const answer = base[key]?.(call) ?? new Error(`no ${key}`);
    if (answer instanceof Error) return Promise.reject(answer);
    return answer instanceof Response ? Promise.resolve(answer.clone()) : answer;
  });
  return calls;
}

interface Frame {
  readonly controls: readonly string[];
  readonly held: number;
  readonly lines: number;
}

function frameOf(): Frame | null {
  const region = document.getElementById('card-prices')?.closest('section') ?? null;
  if (region === null || region.querySelector('li') === null) return null;
  const controls = [...region.querySelectorAll('input')]
    .map(
      (element) =>
        element.getAttribute('aria-label') ?? `input:${element.getAttribute('name') ?? ''}`,
    )
    .filter((name) => /^(Add|Remove|Edit) |^input:/.test(name));
  return {
    controls,
    held: region.querySelectorAll('[data-held="account-controls"]').length,
    lines: [...region.querySelectorAll('p[role="status"]')].filter((p) =>
      /On your watchlist|In your portfolio/.test(p.textContent),
    ).length,
  };
}

function recordFrames(): Frame[] {
  const frames: Frame[] = [];
  const observer = new MutationObserver(() => {
    const frame = frameOf();
    if (frame !== null) frames.push(frame);
  });
  observer.observe(document.body, { childList: true, subtree: true, attributes: true });
  onTestFinishedDisconnect(observer);
  return frames;
}

function onTestFinishedDisconnect(observer: MutationObserver): void {
  afterEachDisconnect.push(observer);
}
const afterEachDisconnect: MutationObserver[] = [];
afterEach(() => {
  for (const observer of afterEachDisconnect.splice(0)) observer.disconnect();
});

async function openCard(): Promise<void> {
  window.history.replaceState(null, '', '/card/ex10-%3F');
  render(<App />);
  await screen.findByRole('heading', { level: 1, name: 'Unown' });
  await settle();
}

function prices(): HTMLElement {
  return screen.getByRole('region', { name: 'Prices' });
}

function writingControlsIn(frames: readonly Frame[]): string[] {
  return frames.flatMap((frame) => frame.controls);
}

describe('the demo, at every committed frame, never has a control that writes', () => {
  const cases: readonly (readonly [string, Record<string, (call: number) => Answer>])[] = [
    [
      'lists answered',
      {
        'GET /v1/me': () => jsonResponse(demoMe),
        'GET /v1/watchlist': () => jsonResponse(watched),
        'GET /v1/portfolio': () => jsonResponse(held),
      },
    ],
    [
      'the watchlist read failing',
      {
        'GET /v1/me': () => jsonResponse(demoMe),
        'GET /v1/watchlist': () => problemResponse(serverErrorProblem('/v1/watchlist')),
        'GET /v1/portfolio': () => jsonResponse(held),
      },
    ],
    [
      'the portfolio read failing',
      {
        'GET /v1/me': () => jsonResponse(demoMe),
        'GET /v1/watchlist': () => jsonResponse(watched),
        'GET /v1/portfolio': () => new Error('network down'),
      },
    ],
    [
      'both reads failing',
      {
        'GET /v1/me': () => jsonResponse(demoMe),
        'GET /v1/watchlist': () => problemResponse(serverErrorProblem('/v1/watchlist')),
        'GET /v1/portfolio': () => problemResponse(serverErrorProblem('/v1/portfolio')),
      },
    ],
    [
      'the portfolio read wrongly shaped',
      {
        'GET /v1/me': () => jsonResponse(demoMe),
        'GET /v1/watchlist': () => jsonResponse(watched),
        'GET /v1/portfolio': () => jsonResponse({ data: 'x' }),
      },
    ],
  ];

  it.each(cases)('with %s', async (...row) => {
    const [, answers] = row;
    setXsrfCookie();
    stub(answers);
    const frames = recordFrames();
    await openCard();
    expect(frames.length).toBeGreaterThan(0);
    expect(writingControlsIn(frames)).toEqual([]);
    expect(prices().querySelectorAll('input')).toHaveLength(0);
    expect(screen.queryByRole('alert')).toBeNull();
  });

  it('with the watchlist read still pending, and again with the session read still pending', async () => {
    setXsrfCookie();
    const pendingList = later();
    stub({
      'GET /v1/me': () => jsonResponse(demoMe),
      'GET /v1/watchlist': () => pendingList.promise,
      'GET /v1/portfolio': () => jsonResponse(held),
    });
    const frames = recordFrames();
    await openCard();
    expect(writingControlsIn(frames)).toEqual([]);
    expect(prices().querySelectorAll('[data-held="account-controls"]').length).toBeGreaterThan(0);
    cleanup();
    stub({ 'GET /v1/me': () => later().promise });
    const second = recordFrames();
    await openCard();
    expect(writingControlsIn(second)).toEqual([]);
    expect(prices().querySelectorAll('[data-held="account-controls"]')).toHaveLength(2);
  });
});

describe('a session read that never answers', () => {
  it('holds the space, keeps the prices and the heading, shows no error and no control, and one box per variant', async () => {
    setXsrfCookie();
    stub({ 'GET /v1/me': () => later().promise });
    const frames = recordFrames();
    await openCard();
    expect(writingControlsIn(frames)).toEqual([]);
    const rows = within(prices()).getAllByRole('listitem');
    expect(rows).toHaveLength(2);
    for (const row of rows) {
      const boxes = row.querySelectorAll<HTMLElement>('[data-held="account-controls"]');
      expect(boxes).toHaveLength(1);
      expect(boxes[0]?.getAttribute('aria-hidden')).toBe('true');
      expect(boxes[0]?.querySelector('a, button, input, select, textarea, [tabindex]')).toBeNull();
    }
    expect(prices().textContent).toMatch(/\$\d/);
    expect(screen.queryByRole('alert')).toBeNull();
    for (const word of FORBIDDEN_WORDS) expect(prices().textContent).not.toMatch(word);
  });
});

describe('a session already cached is never held again', () => {
  it('a /me refetch that is pending leaves the lines and their controls on screen in every frame', async () => {
    setXsrfCookie();
    const refetch = later();
    stub({
      'GET /v1/me': (call) => (call === 0 ? jsonResponse(signedInMe) : refetch.promise),
      'GET /v1/watchlist': () => jsonResponse(watched),
      'GET /v1/portfolio': () => jsonResponse(held),
    });
    await openCard();
    expect(frameOf()?.lines).toBe(2);
    const frames = recordFrames();
    await act(async () => {
      void created.clients[0]?.refetchQueries({ queryKey: ['me'] });
      await new Promise((resolve) => setTimeout(resolve, 0));
    });
    await settle();
    expect(frames.every((frame) => frame.held === 0)).toBe(true);
    expect(frameOf()?.held).toBe(0);
    expect(frameOf()?.controls).toHaveLength(5);
  });

  it('a /me that failed and is asked again holds the space while the second ask is out, and gives the Add buttons back when it answers 401', async () => {
    setXsrfCookie();
    const again = later();
    stub({
      'GET /v1/me': (call) =>
        call === 0 ? problemResponse(serverErrorProblem('/v1/me')) : again.promise,
    });
    await openCard();
    expect(frameOf()?.controls).toHaveLength(4);
    await act(async () => {
      void created.clients[0]?.refetchQueries({ queryKey: ['me'] });
      await new Promise((resolve) => setTimeout(resolve, 0));
    });
    await settle();
    // The session is not answered while the second ask is out: the space is held, nothing writes.
    expect(frameOf()).toEqual({ controls: [], held: 2, lines: 0 });
    expect(screen.queryByRole('alert')).toBeNull();
    expect(prices().textContent).toMatch(/\$\d/);
    await act(async () => {
      again.release(problemResponse(unauthenticatedProblem));
      await new Promise((resolve) => setTimeout(resolve, 0));
    });
    await settle();
    expect(frameOf()?.held).toBe(0);
    expect(frameOf()?.controls).toHaveLength(4);
  });
});

describe('a signed-in session with one list answered and the other pending or failing', () => {
  it('the watchlist answered, the portfolio pending: held, then the lines and controls once it lands', async () => {
    setXsrfCookie();
    const portfolio = later();
    stub({
      'GET /v1/me': () => jsonResponse(signedInMe),
      'GET /v1/watchlist': () => jsonResponse(watched),
      'GET /v1/portfolio': () => portfolio.promise,
    });
    const frames = recordFrames();
    await openCard();
    expect(writingControlsIn(frames)).toEqual([]);
    expect(frameOf()?.held).toBe(2);
    await act(async () => {
      portfolio.release(jsonResponse(held));
      await new Promise((resolve) => setTimeout(resolve, 0));
    });
    await settle();
    expect(frameOf()?.held).toBe(0);
    expect(frameOf()?.lines).toBe(2);
  });

  it('the portfolio failing: the watchlist line with its Remove, an Add for the portfolio, no held box', async () => {
    setXsrfCookie();
    stub({
      'GET /v1/me': () => jsonResponse(signedInMe),
      'GET /v1/watchlist': () => jsonResponse(watched),
      'GET /v1/portfolio': () => problemResponse(serverErrorProblem('/v1/portfolio')),
    });
    await openCard();
    const frame = frameOf();
    expect(frame?.held).toBe(0);
    expect(frame?.controls).toContain('Remove holo, unlimited from your watchlist');
    expect(frame?.controls).toContain('Add holo, unlimited to your portfolio');
    expect(screen.queryByRole('alert')).toBeNull();
  });
});

describe('the first committed frame and a cookie that appears mid-page', () => {
  it('a card page whose data is already cached, with the session forgotten: no Add button in the first commit', async () => {
    setXsrfCookie();
    stub({
      'GET /v1/me': () => jsonResponse(signedInMe),
      'GET /v1/watchlist': () => jsonResponse(watched),
      'GET /v1/portfolio': () => jsonResponse(held),
    });
    await openCard();
    act(() => {
      navigate('/about');
    });
    await settle();
    // Every cached session answer is gone (another tab's first-load cancel leaves the same state).
    created.clients[0]?.removeQueries({ queryKey: ['me'], exact: true });
    const frames = recordFrames();
    act(() => {
      navigate('/card/ex10-%3F');
    });
    const firstCommit = frameOf();
    await settle();
    expect(firstCommit?.controls ?? []).toEqual([]);
    // Every frame before the lines arrived holds the space; Add buttons come only with the answer
    // (the variant that is on neither list then gets its Add buttons next to the other's lines).
    const early = frames.filter((frame) => frame.lines === 0);
    expect(early.length).toBeGreaterThan(0);
    expect(early.flatMap((frame) => frame.controls)).toEqual([]);
  });

  it('a signed-out visitor presses Add, the write sets the cookie, and the answer to that press stays on screen', async () => {
    const calls = stub({
      'PUT /v1/watchlist/ex10-%3F%3Aholo%3Aunlimited%3A-': () =>
        problemResponse({ ...unauthenticatedProblem, instance: '/v1/watchlist/x' }),
      'GET /v1/me': () => problemResponse(unauthenticatedProblem),
    });
    await openCard();
    expect(document.cookie).toBe('');
    const frames = recordFrames();
    fireEvent.click(
      within(prices()).getByRole('button', { name: 'Add holo, unlimited to your watchlist' }),
    );
    await settle();
    expect(document.cookie).toContain('XSRF-TOKEN');
    expect(frames.filter((frame) => frame.held > 0)).toEqual([]);
    expect(within(prices()).getAllByText(/Sign in/i).length).toBeGreaterThan(0);
    expect(calls).not.toContain('GET /v1/me');
  });

  it('the same press, then the visitor picks another variant (a navigation with the cookie now set): the page shows Add buttons again and no error', async () => {
    stub({
      'PUT /v1/watchlist/ex10-%3F%3Aholo%3Aunlimited%3A-': () =>
        problemResponse({ ...unauthenticatedProblem, instance: '/v1/watchlist/x' }),
      'GET /v1/me': () => problemResponse(unauthenticatedProblem),
    });
    await openCard();
    fireEvent.click(
      within(prices()).getByRole('button', { name: 'Add holo, unlimited to your watchlist' }),
    );
    await settle();
    act(() => {
      navigate('/card/ex10-%3F?variant=ex10-%3F%3Areverse%3Aunlimited%3A-');
    });
    await settle();
    expect(screen.queryByRole('alert')).toBeNull();
    expect(frameOf()?.held).toBe(0);
    expect(frameOf()?.controls).toHaveLength(4);
  });
});
