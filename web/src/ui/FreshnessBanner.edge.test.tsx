// Adversarial cases for the stale-prices banner: the ages it can be handed, answers of the wrong kind,
// a request that fails or never answers, the 300 second poll in both directions, one banner across route
// changes, and the routes docs/WEB.md says show it and do not. In none of them may the page show "NaN",
// "undefined" or "null hours", an error block for the banner, or two banners.
import { act, cleanup, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, onTestFinished, vi } from 'vitest';
import App from '../App';
import {
  FORBIDDEN_WORDS,
  answerMovers,
  freshnessBody,
  isFreshnessUrl,
  jsonResponse,
  problemResponse,
  rawResponse,
  serverErrorProblem,
  stubFetch,
  unauthenticatedProblem,
  upstreamUnavailableProblem,
  type FetchCall,
} from '../test/fixtures';

// The docs are read as text. tsconfig.app.json carries no Node types, hence the untyped specifier.
const NODE_FS = 'node:fs';
const { readFileSync } = (await import(/* @vite-ignore */ NODE_FS)) as {
  readonly readFileSync: (path: string, encoding: 'utf8') => string;
};
// Vitest serves a test file as /@fs/<path>: the prefix is dropped to get the path on disk.
const WEB_DOCS = readFileSync(
  new URL('../../../docs/WEB.md', import.meta.url).pathname.replace(/^\/@fs/, ''),
  'utf8',
);

type Answer = () => Response | Error;

let visibility: DocumentVisibilityState = 'visible';

function visit(path: string): void {
  window.history.replaceState(null, '', path);
}

function banners(): HTMLElement[] {
  return screen.queryAllByText(/^Prices are out of date/);
}

/** Lets answers, renders and TanStack's notifications settle (real zero-delay timeouts). */
async function settle(): Promise<void> {
  for (let round = 0; round < 3; round += 1) {
    await act(async () => {
      await new Promise((resolve) => {
        setTimeout(resolve, 0);
      });
    });
  }
}

/** Answers freshness with `freshness`, the movers with one row each way, and /v1/me as signed out. */
function stubWith(freshness: Answer): FetchCall[] {
  return stubFetch((url) => {
    if (isFreshnessUrl(url)) return freshness();
    if (url === '/v1/me') return problemResponse(unauthenticatedProblem);
    return answerMovers(url);
  });
}

async function openHome(): Promise<void> {
  visit('/');
  render(<App />);
  await screen.findByRole('link', { name: 'See all movers' });
  await settle();
}

function freshnessCalls(calls: readonly FetchCall[]): number {
  return calls.filter((call) => isFreshnessUrl(call.url)).length;
}

beforeEach(() => {
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

describe('the ages and days the banner is handed', () => {
  const rows: readonly {
    readonly age: number;
    readonly day: string;
    readonly text: string;
  }[] = [
    { age: 0, day: '2026-10-13', text: '0.0 hours old' },
    { age: 0.04, day: '2026-10-13', text: '0.0 hours old' },
    { age: 25.96, day: '2026-10-13', text: '26.0 hours old' },
    { age: 26, day: '2026-10-13', text: '26.0 hours old' },
    { age: 26.1, day: '2026-09-30', text: '26.1 hours old' },
    { age: 9999.9, day: '2025-12-31', text: '9999.9 hours old' },
    { age: 100000, day: '2026-02-28', text: '100000.0 hours old' },
  ];

  it.each(rows)(
    'says "$text" for age_hours $age and names the day $day, once, with no forbidden word',
    async (row) => {
      const { age, day, text } = row;
      stubWith(() => jsonResponse(freshnessBody({ age_hours: age, day, within_slo: false })));
      visit('/');
      render(<App />);
      const line = `Prices are out of date: the newest collected prices are as of ${day}, ${text}.`;
      expect(await screen.findByText(line)).toBeTruthy();
      expect(banners()).toHaveLength(1);
      expect(document.body.textContent).not.toMatch(/NaN|undefined|null/);
      for (const word of FORBIDDEN_WORDS) expect(line).not.toMatch(word);
    },
  );

  it('never prints NaN, undefined or null when the age is negative, and still shows one banner or none', async () => {
    stubWith(() => jsonResponse(freshnessBody({ age_hours: -3.5, within_slo: false })));
    await openHome();
    expect(banners().length).toBeLessThanOrEqual(1);
    expect(document.body.textContent).not.toMatch(/NaN|undefined|null/);
    expect(screen.getByRole('link', { name: 'See all movers' })).toBeTruthy();
  });

  it('says the same, and nothing about a run, while an ingest is running', async () => {
    stubWith(() =>
      jsonResponse(
        freshnessBody({
          last_run: {
            id: 42,
            status: 'running',
            mode: 'replay',
            day: '2026-10-14',
            finished_at: null,
          },
        }),
      ),
    );
    visit('/');
    render(<App />);
    const banner = await screen.findByText(
      'Prices are out of date: the newest collected prices are as of 2026-10-13, 950.3 hours old.',
    );
    expect(banner.textContent).not.toMatch(/running|replay|2026-10-14/);
  });
});

describe('answers the banner must not act on', () => {
  const wrong: readonly (readonly [string, unknown])[] = [
    ['age_hours a string', freshnessBody({ age_hours: '26' as never })],
    ['age_hours an object', freshnessBody({ age_hours: {} as never })],
    ['day a number', freshnessBody({ day: 20261013 as never })],
    ['day an array', freshnessBody({ day: ['2026-10-13'] as never })],
    ['within_slo the number 0', freshnessBody({ within_slo: 0 as never })],
    ['within_slo null', freshnessBody({ within_slo: null as never })],
    ['within_slo the string "false"', freshnessBody({ within_slo: 'false' as never })],
    ['within_slo missing', { data: { day: '2026-10-13', age_hours: 950.3 } }],
    ['data null', { data: null }],
    ['data a string', { data: 'stale' }],
    ['the body an array', [freshnessBody()]],
    ['the body a string', 'out of date'],
    ['an empty object', {}],
    ['no day yet', freshnessBody({ day: null, age_hours: null, as_of: null })],
  ];

  it.each(wrong)(
    'shows no banner, no alert and no placeholder text for %s',
    async (...row: readonly [string, unknown]) => {
      const [, body] = row;
      const calls = stubWith(() => jsonResponse(body));
      await openHome();
      expect(freshnessCalls(calls)).toBe(1);
      expect(banners()).toHaveLength(0);
      expect(screen.queryByRole('alert')).toBeNull();
      expect(document.body.textContent).not.toMatch(/NaN|undefined|null|out of date/);
    },
  );
});

describe('a request that fails or never answers', () => {
  const failures: readonly (readonly [string, Answer])[] = [
    ['a network failure', () => new TypeError('Failed to fetch')],
    ['a 500 problem', () => problemResponse(serverErrorProblem('/v1/prices/freshness'))],
    ['a 503 problem', () => problemResponse(upstreamUnavailableProblem)],
    ['a 200 that is not JSON', () => rawResponse(200, 'not json', 'text/plain')],
    ['a 204 with no body', () => rawResponse(204, null)],
    ['an HTML 502', () => rawResponse(502, '<html>Bad Gateway</html>', 'text/html')],
  ];

  it.each(failures)(
    'leaves the page whole, with no banner and no error block, after %s',
    async (...row: readonly [string, Answer]) => {
      const [, answer] = row;
      const calls = stubWith(answer);
      await openHome();
      expect(freshnessCalls(calls)).toBe(1);
      expect(banners()).toHaveLength(0);
      expect(screen.queryByRole('alert')).toBeNull();
      expect(screen.queryByText(/Could not load/)).toBeNull();
      expect(screen.getByRole('link', { name: 'See all movers' })).toBeTruthy();
    },
  );

  it('keeps the movers on screen while the freshness request hangs, and shows no banner', async () => {
    let asked = 0;
    vi.stubGlobal('fetch', (input: string | URL): Promise<Response> => {
      const url = String(input);
      if (isFreshnessUrl(url)) {
        asked += 1;
        return new Promise<Response>(() => undefined);
      }
      const answer = answerMovers(url);
      return answer instanceof Response ? Promise.resolve(answer) : Promise.reject(answer);
    });
    onTestFinished(() => {
      vi.unstubAllGlobals();
    });
    await openHome();
    expect(asked).toBe(1);
    expect(banners()).toHaveLength(0);
    expect(screen.queryByRole('alert')).toBeNull();
    expect(document.body.textContent).not.toMatch(/Loading prices|freshness|checking/i);
  });
});

describe('the banner follows the 300 second poll in both directions', () => {
  async function advance(ms: number): Promise<void> {
    await act(async () => {
      await vi.advanceTimersByTimeAsync(ms);
    });
    await settle();
  }

  /** Answers freshness from `bodies` in order (the last repeats), counting the requests. */
  function sequence(bodies: readonly Answer[]): FetchCall[] {
    let asked = 0;
    return stubWith(() => {
      const answer = bodies[Math.min(asked, bodies.length - 1)];
      asked += 1;
      if (answer === undefined) throw new Error('no answer configured');
      return answer();
    });
  }

  const stale: Answer = () => jsonResponse(freshnessBody());
  const fresh: Answer = () => jsonResponse(freshnessBody({ within_slo: true, age_hours: 2.5 }));

  beforeEach(() => {
    vi.useFakeTimers({ toFake: ['setInterval', 'clearInterval', 'Date'] });
    vi.setSystemTime(new Date('2031-02-03T04:05:06Z'));
  });

  it('removes the banner when a stale answer is followed by a fresh one, and shows it again when it goes stale', async () => {
    const calls = sequence([stale, fresh, stale]);
    await openHome();
    expect(banners()).toHaveLength(1);
    await advance(299_999);
    expect(freshnessCalls(calls)).toBe(1);
    expect(banners()).toHaveLength(1);
    await advance(1);
    expect(freshnessCalls(calls)).toBe(2);
    expect(banners()).toHaveLength(0);
    await advance(300_000);
    expect(freshnessCalls(calls)).toBe(3);
    expect(banners()).toHaveLength(1);
  });

  it('shows the banner when a fresh answer is followed by a stale one', async () => {
    sequence([fresh, stale]);
    await openHome();
    expect(banners()).toHaveLength(0);
    await advance(300_000);
    expect(banners()).toHaveLength(1);
  });

  it('keeps the last good verdict on screen when a later poll fails, as the docs say of every poll', async () => {
    sequence([stale, () => problemResponse(serverErrorProblem('/v1/prices/freshness')), stale]);
    await openHome();
    expect(banners()).toHaveLength(1);
    await advance(300_000);
    expect(banners()).toHaveLength(1);
    expect(screen.queryByRole('alert')).toBeNull();
  });

  it('does not poll while the tab is hidden', async () => {
    const calls = sequence([stale]);
    await openHome();
    visibility = 'hidden';
    act(() => {
      document.dispatchEvent(new Event('visibilitychange', { bubbles: true }));
    });
    await advance(900_000);
    expect(freshnessCalls(calls)).toBe(1);
  });
});

describe('one banner, across routes', () => {
  /** Moves the app to `path` the way a link does, then lets it settle. */
  async function go(path: string): Promise<void> {
    act(() => {
      window.history.pushState(null, '', path);
      window.dispatchEvent(new PopStateEvent('popstate'));
    });
    await settle();
  }

  it('never shows two banners while the visitor goes home, to movers, to search and back', async () => {
    stubFetch((url) => {
      if (isFreshnessUrl(url)) return jsonResponse(freshnessBody());
      if (url.startsWith('/v1/cards?')) return new Error('search is not under test');
      return answerMovers(url);
    });
    await openHome();
    expect(banners()).toHaveLength(1);
    await go('/movers');
    expect(banners()).toHaveLength(1);
    await go('/search');
    expect(banners()).toHaveLength(0);
    await go('/movers?direction=down');
    expect(banners()).toHaveLength(1);
    await go('/');
    expect(banners()).toHaveLength(1);
  });
});

describe('where the docs say the banner shows', () => {
  const SHOWS = ['/', '/movers', '/movers?direction=down', '/watchlist', '/portfolio'];
  const HIDES = [
    '/search',
    '/sign-in',
    '/sign-up',
    '/forgot-password',
    '/reset-password?token=abc&email=a%40example.com',
    '/account',
    '/account/verify-email',
    '/alerts',
    '/no-such-page',
  ];

  it('names every one of those pages as showing it or not showing it in docs/WEB.md', () => {
    const section = WEB_DOCS.replace(/\s+/g, ' ');
    expect(section).toContain(
      'Prices are out of date: the newest collected prices are as of <day>, <age_hours> hours old.',
    );
    for (const shown of ['home', 'a card page', '/movers', '/watchlist', '/portfolio']) {
      expect(section, shown).toContain(shown);
    }
    for (const hidden of [
      '/search',
      'sign-in',
      'sign-up',
      'the password pages',
      '/account',
      '/account/verify-email',
      '/alerts',
      'the not-found page',
    ]) {
      expect(section, hidden).toContain(hidden);
    }
  });

  it.each(SHOWS)('shows it once on %s when prices are out of date', async (path) => {
    const calls = stubWith(() => jsonResponse(freshnessBody()));
    visit(path);
    render(<App />);
    await waitFor(() => {
      expect(banners()).toHaveLength(1);
    });
    await settle();
    expect(banners()).toHaveLength(1);
    expect(freshnessCalls(calls)).toBe(1);
  });

  it.each(HIDES)('asks nothing and shows nothing on %s', async (path) => {
    const calls = stubWith(() => jsonResponse(freshnessBody()));
    visit(path);
    render(<App />);
    await screen.findByRole('heading', { level: 1 });
    await settle();
    expect(freshnessCalls(calls)).toBe(0);
    expect(banners()).toHaveLength(0);
  });
});
