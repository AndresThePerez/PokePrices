// Review edge cases for the home page's "See it working" section: what a visitor reaches that the
// section's own tests do not: a leftover token cookie, reads that answer one by one, a late failure,
// a falling portfolio, a demo alert whose card is unknown, and the promise that nothing in it writes.
import { cleanup, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, describe, expect, it, onTestFinished, vi } from 'vitest';
import App from '../App';
import {
  clearXsrfCookie,
  demoAlertsRoutes,
  demoSampleRoutes,
  demoSamplesBody,
  fallingMover,
  jsonResponse,
  moversBody,
  portfolioBody,
  problemResponse,
  risingMover,
  setXsrfCookie,
  stubAccountFetch,
  unauthenticatedProblem,
  watchlistBody,
  type AccountAnswer,
} from '../test/fixtures';
import { SEE_IT_RESERVE } from './SeeItWorking';

const ME = 'GET /v1/me';
const HEADING = 'See it working';

const MOVERS: Record<string, AccountAnswer> = {
  'GET /v1/movers?direction=up&limit=5': jsonResponse(moversBody('up', [risingMover])),
  'GET /v1/movers?direction=down&limit=5': jsonResponse(moversBody('down', [fallingMover])),
};

function visit(path: string): void {
  window.history.replaceState(null, '', path);
}

afterEach(() => {
  cleanup();
  clearXsrfCookie();
  visit('/');
});

function main(): HTMLElement {
  return screen.getByRole('main');
}

function section(): HTMLElement {
  return within(main()).getByRole('region', { name: HEADING });
}

function open(routes: Readonly<Record<string, AccountAnswer>>) {
  const calls = stubAccountFetch({ ...MOVERS, ...routes });
  visit('/');
  render(<App />);
  return calls;
}

/** The demo reads wait until `release` is called with the route; the others answer at once. */
function heldDemo(demo: Readonly<Record<string, Response>>) {
  const waiting = new Map<string, () => void>();
  const all: Record<string, Response> = { ...demo };
  for (const [key, value] of Object.entries(MOVERS)) all[key] = value as Response;
  vi.stubGlobal('fetch', (input: string | URL): Promise<Response> => {
    const url = String(input);
    const answer = all[`GET ${url}`];
    if (answer === undefined) return Promise.reject(new TypeError(`no request expected: ${url}`));
    if (!url.startsWith('/v1/demo/')) return Promise.resolve(answer.clone());
    return new Promise<Response>((resolve, reject) => {
      waiting.set(url, () => {
        if (answer.ok) resolve(answer.clone());
        else reject(new TypeError('Failed to fetch'));
      });
    });
  });
  onTestFinished(() => {
    vi.unstubAllGlobals();
  });
  return {
    asked: () => waiting.size,
    release: (url: string) => {
      const settle = waiting.get(url);
      if (settle === undefined) throw new Error(`not asked yet: ${url}`);
      settle();
    },
  };
}

describe('See it working: edge cases', () => {
  it('shows the section for a visitor with a leftover token cookie whose session ask answers 401', async () => {
    setXsrfCookie();
    const calls = open({
      [ME]: problemResponse(unauthenticatedProblem),
      ...demoSampleRoutes(),
      ...demoAlertsRoutes(),
    });
    const region = await waitFor(section);
    expect(within(region).getByRole('link', { name: 'Open the sample portfolio' })).toBeTruthy();
    const keys = calls.map((call) => call.key);
    expect(keys.filter((key) => key === ME)).toHaveLength(1);
    expect(
      keys.filter((key) => /^GET \/v1\/(watchlist|portfolio|alerts|webhooks)/.test(key)),
    ).toEqual([]);
    expect(screen.getByRole('link', { name: 'Sign in' })).toBeTruthy();
  });

  it('leaves the page usable while the reads are pending, and holds an empty frame', async () => {
    const reads = heldDemo({
      'GET /v1/demo/watchlist': jsonResponse(watchlistBody()),
      'GET /v1/demo/portfolio': jsonResponse(portfolioBody()),
      'GET /v1/demo/alerts': jsonResponse(demoSamplesBody()),
    });
    visit('/');
    render(<App />);
    await within(main()).findByRole('link', { name: 'See all movers' });
    await waitFor(() => {
      expect(reads.asked()).toBe(3);
    });
    expect(screen.getByRole('heading', { level: 1 }).textContent).not.toBe('');
    expect(within(main()).getByRole('button', { name: 'Search' })).toBeTruthy();
    const frame = main().lastElementChild as HTMLElement;
    expect(frame.className).toBe(SEE_IT_RESERVE);
    expect(frame.childElementCount).toBe(0);
    expect(frame.textContent).toBe('');
    expect(frame.getAttribute('role')).toBeNull();
    expect(frame.getAttribute('aria-live')).toBeNull();
  });

  it('shows no partial section while one read is still out, then none at all when it fails late', async () => {
    const reads = heldDemo({
      'GET /v1/demo/watchlist': jsonResponse(watchlistBody()),
      'GET /v1/demo/portfolio': jsonResponse(portfolioBody()),
      'GET /v1/demo/alerts': problemResponse({
        type: 'https://pokeprices.example/problems/http-500',
        title: 'Internal Server Error',
        status: 500,
        instance: '/v1/demo/alerts',
        request_id: 'req-sample-0500',
      }),
    });
    visit('/');
    render(<App />);
    await within(main()).findByRole('link', { name: 'See all movers' });
    await waitFor(() => {
      expect(reads.asked()).toBe(3);
    });
    reads.release('/v1/demo/watchlist');
    reads.release('/v1/demo/portfolio');
    await new Promise((resolve) => setTimeout(resolve, 50));
    // Two answers are in, the third is out: still the empty frame, never a partial section.
    expect(main().lastElementChild?.className).toBe(SEE_IT_RESERVE);
    expect(main().lastElementChild?.childElementCount).toBe(0);
    expect(main().textContent).not.toContain('Sample portfolio');
    reads.release('/v1/demo/alerts');
    await waitFor(() => {
      expect(main().lastElementChild?.className).not.toBe(SEE_IT_RESERVE);
    });
    expect(within(main()).queryByRole('region', { name: HEADING })).toBeNull();
    expect(main().textContent).not.toContain('Sample watchlist');
  });

  it('prints a falling portfolio in the hundreds of thousands with its fall, in the falling tone', async () => {
    open({
      ...demoSampleRoutes(
        watchlistBody(),
        portfolioBody(undefined, {
          total_value: '654321.09',
          change: '-1234.56',
          change_pct: '-0.19',
        }),
      ),
      ...demoAlertsRoutes(),
    });
    const region = await waitFor(section);
    expect(within(region).getByText('Total value $654,321.09')).toBeTruthy();
    const change = within(region).getByText('▼ -$1,234.56 (-0.19%) vs previous close.');
    expect(change.className).toContain('text-down');
    expect(region.textContent).not.toMatch(/undefined|null|NaN/);
  });

  it('says there is no previous close when the portfolio has none, and keeps the total', async () => {
    open({
      ...demoSampleRoutes(
        watchlistBody(),
        portfolioBody(undefined, {
          change: null,
          change_pct: null,
          no_previous_close: 3,
          first_day: '2026-10-12',
          last_day: '2026-10-12',
        }),
      ),
      ...demoAlertsRoutes(),
    });
    const region = await waitFor(section);
    expect(within(region).getByText('Total value $1,047.93')).toBeTruthy();
    expect(within(region).getByText('No previous close yet, so no change to show.')).toBeTruthy();
    const part = within(region).getByRole('heading', { name: 'Sample portfolio' }).parentElement;
    expect(part?.textContent).not.toMatch(/undefined|null|NaN|▲|▼/);
  });

  it('names an alert whose card is unknown by its id, without "undefined"', async () => {
    const base = demoSamplesBody();
    const alert = base.data.alert;
    expect(alert).not.toBeNull();
    open({
      ...demoSampleRoutes(),
      ...demoAlertsRoutes(
        demoSamplesBody({ alert: alert === null ? null : { ...alert, card: null } }),
      ),
    });
    const region = await waitFor(section);
    expect(within(region).getByText('Card ex5-17 · reverse')).toBeTruthy();
    expect(region.textContent).not.toMatch(/undefined|null|NaN/);
  });

  it('removes the section, and leaves the page standing, when a body has the wrong inner shape', async () => {
    open({
      ...demoSampleRoutes(watchlistBody(), jsonResponse({ data: [] }) as never),
      ...demoAlertsRoutes(),
    });
    await within(main()).findByRole('link', { name: 'See all movers' });
    await waitFor(() => {
      expect(main().lastElementChild?.className).not.toBe(SEE_IT_RESERVE);
    });
    expect(within(main()).queryByRole('region', { name: HEADING })).toBeNull();
    expect(screen.getByRole('heading', { level: 1 })).toBeTruthy();
  });

  it('has no control that writes: no button, field or form, only links to the three pages and cards', async () => {
    const calls = open({ ...demoSampleRoutes(), ...demoAlertsRoutes() });
    const region = await waitFor(section);
    expect(
      region.querySelectorAll('button, input, select, textarea, form, [role="button"]'),
    ).toHaveLength(0);
    const hrefs = [...region.querySelectorAll('a')].map((link) => link.getAttribute('href') ?? '');
    for (const href of hrefs) {
      expect(href).toMatch(/^(\/portfolio|\/watchlist|\/alerts|\/card\/[^/?]+\?variant=.+)$/);
    }
    for (const call of calls) expect(call.init?.method ?? 'GET').toBe('GET');
  });
});
