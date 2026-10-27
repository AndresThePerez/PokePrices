// Q1-i2-T01 review: the root boundary is the backstop for a shape the envelope decoder lets
// through (it checks only the top level), and its reset follows the pathname alone.
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

/** One banner, one footer, one main that is not empty, and #content still the skip target. */
async function expectShellWithAPage(): Promise<void> {
  await waitFor(() => {
    expect(screen.getAllByRole('main')).toHaveLength(1);
  });
  expect(screen.getAllByRole('banner')).toHaveLength(1);
  expect(screen.getAllByRole('contentinfo')).toHaveLength(1);
  expect(document.getElementById('content')?.getAttribute('tabindex')).toBe('-1');
  expect(screen.getByRole('main').textContent.trim()).not.toBe('');
}

describe('a page that dereferences inside a well-formed envelope (the decoder checks only the top level)', () => {
  it('/movers with a null row keeps the shell and a non-empty main', async () => {
    vi.spyOn(console, 'error').mockImplementation(() => undefined);
    stubFetch(() => jsonResponse({ data: [null], meta: { degraded: false } }));
    visit('/movers');
    render(<App />);
    await expectShellWithAPage();
  });

  it('/watchlist with a row that is not an object keeps the shell and a non-empty main', async () => {
    vi.spyOn(console, 'error').mockImplementation(() => undefined);
    stubAccountFetch({
      'GET /v1/me': jsonResponse(signedInMe),
      'GET /v1/watchlist': jsonResponse({ data: [7], meta: { count: 1, limit: 100 } }),
    });
    visit('/watchlist');
    render(<App />);
    await expectShellWithAPage();
  });

  it('/card/:id with an empty data object keeps the shell and a non-empty main', async () => {
    vi.spyOn(console, 'error').mockImplementation(() => undefined);
    stubFetch((url) =>
      url === '/v1/cards/ex10-%3F'
        ? jsonResponse({ data: {}, meta: { degraded: false } })
        : jsonResponse({ data: [], meta: {} }),
    );
    visit('/card/ex10-%3F');
    render(<App />);
    await expectShellWithAPage();
  });
});

describe('the root boundary reset (review)', () => {
  it('keeps one main and points the reload link at the new query while the pathname is unchanged', () => {
    vi.spyOn(console, 'error').mockImplementation(() => undefined);
    crash.on = true;
    stubFetch(() => new TypeError('no request expected'));
    visit('/sign-up');
    render(<App />);
    expect(screen.getByRole('link', { name: 'Reload the page' }).getAttribute('href')).toBe(
      '/sign-up',
    );
    act(() => {
      navigate('/sign-up?again=1');
    });
    expect(screen.getAllByRole('main')).toHaveLength(1);
    expect(screen.getByText(FALLBACK)).toBeTruthy();
    expect(screen.getByRole('link', { name: 'Reload the page' }).getAttribute('href')).toBe(
      '/sign-up?again=1',
    );
  });

  it('shows the page again when the visitor leaves the crashed path and comes back', async () => {
    vi.spyOn(console, 'error').mockImplementation(() => undefined);
    crash.on = true;
    stubFetch(answerMovers);
    visit('/sign-up');
    render(<App />);
    expect(screen.getByText(FALLBACK)).toBeTruthy();
    act(() => {
      navigate('/');
    });
    await waitFor(() => {
      expect(screen.queryByText(FALLBACK)).toBeNull();
    });
    crash.on = false;
    act(() => {
      navigate('/sign-up');
    });
    expect(screen.queryByText(FALLBACK)).toBeNull();
    expect(within(screen.getByRole('main')).getByRole('heading', { level: 1 })).toBeTruthy();
  });
});
