// The signed-out /watchlist and /portfolio show the demo's collection as labelled, read-only sample
// data under one shared strip (SampleStrip), read from the public demo routes with no cookie and no
// session; when that read fails the pages show the sign-in prompt they showed before.
import { cleanup, render, screen, within } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import App from '../App';
import {
  FORBIDDEN_WORDS,
  clearXsrfCookie,
  demoSampleRoutes,
  demoSampleUnavailableRoutes,
  jsonResponse,
  portfolioBody,
  problemResponse,
  serverErrorProblem,
  setXsrfCookie,
  signedInMe,
  stubAccountFetch,
  unauthenticatedProblem,
  watchlistBody,
  type AccountAnswer,
} from '../test/fixtures';

const STRIP_WORDS =
  'This is sample data from the read-only demo collection, the same for every visitor. Signing in gives you your own';

function visit(path: string): void {
  window.history.replaceState(null, '', path);
}

afterEach(() => {
  cleanup();
  clearXsrfCookie();
  visit('/');
});

function open(path: string, routes: Readonly<Record<string, AccountAnswer>>) {
  const calls = stubAccountFetch(routes);
  visit(path);
  render(<App />);
  return calls;
}

function main(): HTMLElement {
  return screen.getByRole('main');
}

function keys(calls: readonly { readonly key: string }[]): string[] {
  return calls.map((call) => call.key);
}

describe('the signed-out watchlist (sample)', () => {
  it('asks only for the public demo watchlist and shows its rows under the strip', async () => {
    const calls = open('/watchlist', demoSampleRoutes());
    const list = await screen.findByRole('list', { name: 'Watchlist' });
    expect(within(list).getAllByRole('listitem')).toHaveLength(3);
    const strip = within(main()).getByRole('region', { name: 'Sample data' });
    expect(strip.textContent).toContain(STRIP_WORDS);
    expect(strip.compareDocumentPosition(list) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
    expect(keys(calls)).toContain('GET /v1/demo/watchlist');
    for (const key of keys(calls)) {
      expect(key).not.toMatch(/^GET \/v1\/(me|watchlist|portfolio)$/);
    }
    expect(document.cookie).toBe('');
  });

  it('keeps the row links, the source and the as-of line of the signed-in rows', async () => {
    open('/watchlist', demoSampleRoutes());
    const list = await screen.findByRole('list', { name: 'Watchlist' });
    const rising = within(list).getAllByRole('listitem')[1];
    if (rising === undefined) throw new Error('three rows expected');
    const link = within(rising).getByRole('link', { name: 'Charizard' });
    expect(link.getAttribute('href')).toBe('/card/base1-4?variant=base1-4%3Aholo%3Aunlimited%3A-');
    expect(within(rising).getByText('as of 2026-10-12 from TCGCSV (daily close)')).toBeTruthy();
    expect(within(rising).getByText('Market price $968.91')).toBeTruthy();
  });

  it('renders nothing that writes: no Remove, no alert control, no quantity field, no undo', async () => {
    open('/watchlist', demoSampleRoutes());
    await screen.findByRole('list', { name: 'Watchlist' });
    const region = main();
    expect(within(region).queryAllByRole('combobox')).toHaveLength(0);
    expect(within(region).queryAllByRole('textbox')).toHaveLength(0);
    expect(within(region).queryAllByRole('spinbutton')).toHaveLength(0);
    expect(
      within(region)
        .getAllByRole('button')
        .map((button) => button.textContent),
    ).toEqual(['Try the read-only demo']);
    expect(region.textContent).not.toMatch(/Remove|Undo|Save alert|Alert threshold|Add to/);
    expect(
      within(region)
        .getAllByRole('link')
        .map((link) => link.getAttribute('href')),
    ).toEqual([
      '/sign-in?next=/watchlist',
      '/card/base1-4?variant=base1-4%3Aholo%3A1st%3A-',
      '/card/base1-4?variant=base1-4%3Aholo%3Aunlimited%3A-',
      '/card/ex5-17?variant=ex5-17%3Areverse%3A-%3A-',
    ]);
  });

  it('shows the sign-in prompt and no strip when the public read answers 404 demo-unavailable', async () => {
    const calls = open('/watchlist', demoSampleUnavailableRoutes());
    expect(await screen.findByText('Sign in to keep a watchlist.')).toBeTruthy();
    expect(keys(calls)).toContain('GET /v1/demo/watchlist');
    expect(screen.queryByRole('region', { name: 'Sample data' })).toBeNull();
    expect(screen.queryByRole('list', { name: 'Watchlist' })).toBeNull();
    expect(screen.queryByRole('alert')).toBeNull();
  });

  it('shows the sign-in prompt and no strip when the public read fails', async () => {
    const calls = open('/watchlist', {
      'GET /v1/demo/watchlist': problemResponse(serverErrorProblem('/v1/demo/watchlist')),
    });
    expect(await screen.findByText('Sign in to keep a watchlist.')).toBeTruthy();
    expect(keys(calls)).toContain('GET /v1/demo/watchlist');
    expect(screen.queryByRole('region', { name: 'Sample data' })).toBeNull();
    expect(screen.queryByRole('alert')).toBeNull();
  });

  it('shows the sample to a visitor whose leftover cookie got a 401', async () => {
    setXsrfCookie();
    const calls = open('/watchlist', {
      'GET /v1/me': problemResponse(unauthenticatedProblem),
      ...demoSampleRoutes(),
    });
    await screen.findByRole('region', { name: 'Sample data' });
    expect(await screen.findByRole('list', { name: 'Watchlist' })).toBeTruthy();
    expect(keys(calls)).toContain('GET /v1/me');
    expect(keys(calls)).not.toContain('GET /v1/watchlist');
  });

  it('shows the signed-in list as before and never asks for the sample', async () => {
    const calls = open('/watchlist', {
      'GET /v1/me': jsonResponse(signedInMe),
      'GET /v1/watchlist': jsonResponse(watchlistBody()),
    });
    await screen.findByRole('list', { name: 'Watchlist' });
    expect(screen.queryByRole('region', { name: 'Sample data' })).toBeNull();
    expect(keys(calls).filter((key) => key.includes('/demo/'))).toEqual([]);
    expect(within(main()).getAllByRole('button', { name: /^Remove / }).length).toBeGreaterThan(0);
  });
});

describe('the signed-out portfolio (sample)', () => {
  it('asks only for the public demo portfolio and shows its rows under the strip', async () => {
    const calls = open('/portfolio', demoSampleRoutes());
    const list = await screen.findByRole('list', { name: 'Portfolio' });
    expect(within(list).getAllByRole('listitem')).toHaveLength(3);
    const strip = within(main()).getByRole('region', { name: 'Sample data' });
    expect(strip.textContent).toContain(STRIP_WORDS);
    expect(keys(calls)).toContain('GET /v1/demo/portfolio');
    for (const key of keys(calls)) {
      expect(key).not.toMatch(/^GET \/v1\/(me|watchlist|portfolio)$/);
    }
  });

  it("shows the total and the day's change of the sample", async () => {
    open('/portfolio', demoSampleRoutes());
    await screen.findByRole('list', { name: 'Portfolio' });
    expect(within(main()).getByText('Total value $1,047.93')).toBeTruthy();
    expect(main().textContent).toContain('+$16.93');
    expect(main().textContent).toContain('+1.64%');
    expect(main().textContent).toContain('2026-10-11');
  });

  it('renders nothing that writes: no Remove, no quantity field, no undo', async () => {
    open('/portfolio', demoSampleRoutes());
    await screen.findByRole('list', { name: 'Portfolio' });
    const region = main();
    expect(within(region).queryAllByRole('spinbutton')).toHaveLength(0);
    expect(within(region).queryAllByRole('textbox')).toHaveLength(0);
    expect(within(region).queryAllByRole('combobox')).toHaveLength(0);
    expect(
      within(region)
        .getAllByRole('button')
        .map((button) => button.textContent),
    ).toEqual(['Try the read-only demo']);
    expect(region.textContent).not.toMatch(/Remove|Undo|Quantity owned|Save/);
  });

  it('shows the sign-in prompt and no strip when the public read answers 404 demo-unavailable', async () => {
    const calls = open('/portfolio', demoSampleUnavailableRoutes());
    expect(await screen.findByText('Sign in to keep a portfolio.')).toBeTruthy();
    expect(keys(calls)).toContain('GET /v1/demo/portfolio');
    expect(screen.queryByRole('region', { name: 'Sample data' })).toBeNull();
    expect(screen.queryByRole('alert')).toBeNull();
  });

  it('shows the sign-in prompt, not an empty-list sentence, when the demo holds nothing', async () => {
    const calls = open('/portfolio', demoSampleRoutes(watchlistBody([]), portfolioBody([])));
    expect(await screen.findByText('Sign in to keep a portfolio.')).toBeTruthy();
    expect(keys(calls)).toContain('GET /v1/demo/portfolio');
    expect(screen.queryByRole('region', { name: 'Sample data' })).toBeNull();
    expect(main().textContent).not.toContain('Your portfolio is empty');
  });

  it('shows the signed-in portfolio as before and never asks for the sample', async () => {
    const calls = open('/portfolio', {
      'GET /v1/me': jsonResponse(signedInMe),
      'GET /v1/portfolio': jsonResponse(portfolioBody()),
    });
    await screen.findByRole('list', { name: 'Portfolio' });
    expect(screen.queryByRole('region', { name: 'Sample data' })).toBeNull();
    expect(keys(calls).filter((key) => key.includes('/demo/'))).toEqual([]);
  });
});

describe('the sample strip (one component, both pages)', () => {
  it('is a labelled region, not a live region, with the same words on both pages', async () => {
    const words: string[] = [];
    for (const path of ['/watchlist', '/portfolio']) {
      open(path, demoSampleRoutes());
      const strip = await screen.findByRole('region', { name: 'Sample data' });
      expect(screen.getAllByRole('region', { name: 'Sample data' })).toHaveLength(1);
      expect(within(strip).queryAllByRole('status')).toHaveLength(0);
      expect(within(strip).queryAllByRole('alert')).toHaveLength(0);
      expect(strip.closest('[aria-live]')).toBeNull();
      expect(strip.querySelector('[aria-live]')).toBeNull();
      for (const word of FORBIDDEN_WORDS) expect(strip.textContent).not.toMatch(word);
      words.push(strip.textContent.replace(/watchlist|portfolio/g, 'list'));
      cleanup();
    }
    expect(words[0]).toBe(words[1]);
  });

  it('names the page in its sign-in sentence and links to sign in and back to the page', async () => {
    open('/portfolio', demoSampleRoutes());
    const strip = await screen.findByRole('region', { name: 'Sample data' });
    expect(within(strip).getByRole('link', { name: 'Sign in' }).getAttribute('href')).toBe(
      '/sign-in?next=/portfolio',
    );
    expect(within(strip).getByRole('button', { name: 'Try the read-only demo' })).toBeTruthy();
    expect(strip.textContent).toContain('Signing in gives you your own portfolio.');
  });
});
