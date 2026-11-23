// The shell survives a page that cannot render (Q1-i2 T01, c1): a wrong-shaped 200 lands in the
// page's own problem block, and a render throw anywhere under the routes leaves the banner, the
// skip target and the footer mounted, with one main holding the fallback.
import { act, cleanup, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import type * as SignUpModule from './account/SignUpPage';
import App from './App';
import { navigate } from './router';
import {
  answerMovers,
  clearXsrfCookie,
  jsonResponse,
  signedInMe,
  stubAccountFetch,
  stubFetch,
} from './test/fixtures';

// The sign-up page stands in for any page that throws while it renders; a flag turns it on.
const crash = vi.hoisted(() => ({ on: false }));
vi.mock('./account/SignUpPage', async (importOriginal) => {
  const actual = await importOriginal<typeof SignUpModule>();
  const Page = actual.default;
  return {
    default: function MaybeCrash() {
      if (crash.on) throw new Error('render failed');
      return <Page />;
    },
  };
});

function visit(path: string): void {
  window.history.replaceState(null, '', path);
}

afterEach(() => {
  cleanup();
  clearXsrfCookie();
  crash.on = false;
  vi.restoreAllMocks();
  visit('/');
});

const FALLBACK = 'This page could not be shown.';
const UNEXPECTED = 'The PokéPrices API sent an unexpected answer (HTTP 200)';

function shellIntact(): void {
  expect(screen.getAllByRole('banner')).toHaveLength(1);
  expect(screen.getAllByRole('contentinfo')).toHaveLength(1);
  expect(screen.getAllByRole('main')).toHaveLength(1);
  const content = document.getElementById('content');
  expect(content?.tagName).toBe('DIV');
  expect(content?.getAttribute('tabindex')).toBe('-1');
  expect(content?.contains(screen.getByRole('main'))).toBe(true);
}

describe('a wrong-shaped 200 shows the page problem block, not a blank page (probe 2)', () => {
  it('/movers with {"data":[]} keeps the shell and shows the movers problem', async () => {
    stubFetch(() => jsonResponse({ data: [] }));
    visit('/movers');
    render(<App />);
    const alert = await screen.findByRole('alert');
    expect(alert.textContent).toContain(`Could not load the movers: ${UNEXPECTED}`);
    shellIntact();
    expect(within(screen.getByRole('main')).getByRole('heading', { level: 1 })).toBeTruthy();
  });

  it('/watchlist with 200 {} keeps the shell and shows the watchlist problem', async () => {
    const calls = stubAccountFetch({
      'GET /v1/me': jsonResponse(signedInMe),
      'GET /v1/watchlist': jsonResponse({}),
    });
    visit('/watchlist');
    render(<App />);
    const alert = await screen.findByRole('alert');
    expect(alert.textContent).toContain(`Could not load your watchlist: ${UNEXPECTED}`);
    shellIntact();
    expect(calls.map((call) => call.key)).toEqual([
      'GET /v1/prices/freshness',
      'GET /v1/me',
      'GET /v1/watchlist',
    ]);
  });
});

describe('the root boundary (c1)', () => {
  it('keeps banner, skip target and footer when a page throws in render, with one main', () => {
    vi.spyOn(console, 'error').mockImplementation(() => undefined);
    crash.on = true;
    stubFetch(() => new TypeError('no request expected'));
    visit('/sign-up');
    render(<App />);
    shellIntact();
    const main = screen.getByRole('main');
    expect(within(main).getByText(FALLBACK)).toBeTruthy();
    const reload = within(main).getByRole('link', { name: 'Reload the page' });
    expect(reload.getAttribute('href')).toBe('/sign-up');
    // Not a live region, not a status: the fallback is plain page content.
    expect(screen.queryByRole('status')).toBeNull();
    expect(screen.queryByRole('alert')).toBeNull();
    expect(main.closest('[aria-live]')).toBeNull();
    expect(within(screen.getByRole('banner')).queryByText(FALLBACK)).toBeNull();
    expect(within(screen.getByRole('contentinfo')).queryByText(FALLBACK)).toBeNull();
    // It says nothing about prices or time.
    expect(main.textContent).not.toMatch(/price|\btoday\b|\bnow\b|\blive\b|daily|close/i);
  });

  it('clears once the pathname changes', async () => {
    vi.spyOn(console, 'error').mockImplementation(() => undefined);
    crash.on = true;
    stubFetch(answerMovers);
    visit('/sign-up');
    render(<App />);
    expect(screen.getByText(FALLBACK)).toBeTruthy();
    crash.on = false;
    act(() => {
      navigate('/');
    });
    await waitFor(() => {
      expect(screen.queryByText(FALLBACK)).toBeNull();
    });
    expect(screen.getByRole('heading', { level: 1 }).textContent).toBe(
      'Pokémon card prices at the daily close',
    );
    shellIntact();
  });
});
