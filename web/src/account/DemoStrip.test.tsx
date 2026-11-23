// D2-i1-T05: the persistent demo strip in the banner (RULING e, A1-7), the per-page demo notice
// gone, the ×n quantity chip beside the visually hidden "Quantity n" (RULING f), the CSS-only row
// placeholder (A1-9, A1-10) and the alert select that keeps an unsaved choice across a refetch.
import { act, cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import App from '../App';
import {
  answerMovers,
  clearXsrfCookie,
  demoMe,
  isMoversUrl,
  jsonResponse,
  portfolioBody,
  problemResponse,
  rawResponse,
  serverErrorProblem,
  signedInMe,
  stubAccountFetch,
  stubFetch,
  unauthenticatedProblem,
  watchlistBody,
  watchlistItems,
  webhooksBody,
} from '../test/fixtures';
import { SESSION_SIGNAL_KEY } from './session';

const DEMO_NOTICE =
  'You are signed in to the read-only demo account. Its watchlist and portfolio cannot be changed.';

function visit(path: string): void {
  window.history.replaceState(null, '', path);
}

beforeEach(() => {
  window.localStorage.clear();
});

afterEach(() => {
  cleanup();
  clearXsrfCookie();
  visit('/');
});

function banner(): HTMLElement {
  return screen.getByRole('banner');
}

function main(): HTMLElement {
  return screen.getByRole('main');
}

function lastNavLink(): string | null {
  const links = within(within(banner()).getByRole('navigation', { name: 'Main' })).getAllByRole(
    'link',
  );
  return links.at(-1)?.textContent ?? null;
}

async function settle(): Promise<void> {
  for (let round = 0; round < 5; round += 1) {
    await act(async () => {
      await new Promise((resolve) => setTimeout(resolve, 0));
    });
  }
}

/** The strip's sentence: one role-less p, one text node, in the banner and outside the nav. */
function expectStrip(): HTMLElement {
  const notices = screen.getAllByText(DEMO_NOTICE);
  expect(notices).toHaveLength(1);
  const notice = notices[0] as HTMLElement;
  expect(notice.tagName).toBe('P');
  expect(notice.hasAttribute('role')).toBe(false);
  expect(notice.childNodes).toHaveLength(1);
  expect(banner().contains(notice)).toBe(true);
  expect(within(banner()).getByRole('navigation', { name: 'Main' }).contains(notice)).toBe(false);
  expect(main().contains(notice)).toBe(false);
  return notice;
}

describe('the persistent demo strip (RULING e)', () => {
  it.each([
    ['/watchlist', 'GET /v1/watchlist', watchlistBody(), 'Watchlist'],
    ['/portfolio', 'GET /v1/portfolio', portfolioBody(), 'Portfolio'],
  ] as const)(
    '%s: the one demo notice is the strip in the banner, with Sign out, and asks nothing more',
    async (path, key, body, listName) => {
      const calls = stubAccountFetch({
        'GET /v1/me': jsonResponse(demoMe),
        [key]: jsonResponse(body),
      });
      visit(path);
      render(<App />);
      await screen.findByRole('list', { name: listName });
      expectStrip();
      expect(within(banner()).getByRole('button', { name: 'Sign out' })).toBeTruthy();
      expect(within(main()).queryByText(DEMO_NOTICE)).toBeNull();
      expect(calls.map((call) => call.key)).toEqual([
        'GET /v1/prices/freshness',
        'GET /v1/me',
        key,
      ]);
    },
  );

  it('/account: the strip without its Sign out, which stays in main, and no notice in main', async () => {
    stubAccountFetch({ 'GET /v1/me': jsonResponse(demoMe) });
    visit('/account');
    render(<App />);
    await screen.findByText('Signed in as demo@example.com');
    expectStrip();
    expect(within(banner()).queryByRole('button', { name: 'Sign out' })).toBeNull();
    expect(within(main()).getByRole('button', { name: 'Sign out' })).toBeTruthy();
    expect(screen.getAllByRole('button', { name: 'Sign out' })).toHaveLength(1);
  });

  it('is absent for an ordinary account', async () => {
    stubAccountFetch({
      'GET /v1/me': jsonResponse(signedInMe),
      'GET /v1/watchlist': jsonResponse(watchlistBody()),
    });
    visit('/watchlist');
    render(<App />);
    await screen.findByRole('list', { name: 'Watchlist' });
    expect(screen.queryByText(DEMO_NOTICE)).toBeNull();
    expect(within(banner()).queryByRole('button', { name: 'Sign out' })).toBeNull();
  });

  it('follows the demo onto a public page, then leaves after another tab signals (A1-7), asking nothing', async () => {
    const calls = stubFetch((url) => {
      if (url === '/v1/me') return jsonResponse(demoMe);
      if (url === '/v1/webhooks') return jsonResponse(webhooksBody());
      if (isMoversUrl(url)) return answerMovers(url);
      return new Error(`no request expected: ${url}`);
    });
    visit('/account');
    render(<App />);
    await screen.findByText('Signed in as demo@example.com');
    fireEvent.click(within(banner()).getByRole('link', { name: 'Movers' }));
    await settle();
    expectStrip();
    expect(within(banner()).getByRole('button', { name: 'Sign out' })).toBeTruthy();
    const before = calls.filter((call) => call.url.startsWith('/v1/me')).length;
    act(() => {
      window.dispatchEvent(
        new StorageEvent('storage', { key: SESSION_SIGNAL_KEY, newValue: '1.1' }),
      );
    });
    await settle();
    expect(screen.queryByText(DEMO_NOTICE)).toBeNull();
    expect(within(banner()).queryByRole('button', { name: 'Sign out' })).toBeNull();
    expect(lastNavLink()).toBe('Account');
    expect(calls.filter((call) => call.url.startsWith('/v1/me'))).toHaveLength(before);
  });

  it('signs out from the strip as the Account page does: POST /logout, signed out, the signal, then /', async () => {
    const calls = stubAccountFetch({
      'GET /v1/me': [jsonResponse(demoMe), problemResponse(unauthenticatedProblem)],
      'GET /v1/watchlist': jsonResponse(watchlistBody()),
      'POST /logout': rawResponse(204, null),
    });
    visit('/watchlist');
    render(<App />);
    await screen.findByRole('list', { name: 'Watchlist' });
    expect(window.localStorage.getItem(SESSION_SIGNAL_KEY)).toBeNull();
    fireEvent.click(within(banner()).getByRole('button', { name: 'Sign out' }));
    await waitFor(() => {
      expect(window.location.pathname).toBe('/');
    });
    expect(calls.filter((call) => call.key === 'POST /logout')).toHaveLength(1);
    expect(window.localStorage.getItem(SESSION_SIGNAL_KEY)).not.toBeNull();
    expect(screen.queryByText(DEMO_NOTICE)).toBeNull();
    expect(lastNavLink()).toBe('Sign in');
  });

  it('shows a failed sign-out from the strip and stays on the page', async () => {
    stubAccountFetch({
      'GET /v1/me': jsonResponse(demoMe),
      'GET /v1/watchlist': jsonResponse(watchlistBody()),
      'POST /logout': problemResponse(serverErrorProblem('/logout')),
    });
    visit('/watchlist');
    render(<App />);
    await screen.findByRole('list', { name: 'Watchlist' });
    fireEvent.click(within(banner()).getByRole('button', { name: 'Sign out' }));
    const alert = await screen.findByRole('alert');
    expect(alert.textContent).toContain('Could not sign out: Internal Server Error');
    expect(window.location.pathname).toBe('/watchlist');
    expectStrip();
  });
});

describe('list rows (RULING f, A1-9, A1-10)', () => {
  it('prints the quantity as a visible aria-hidden ×n chip beside the hidden Quantity n line', async () => {
    stubAccountFetch({
      'GET /v1/me': jsonResponse(demoMe),
      'GET /v1/portfolio': jsonResponse(portfolioBody()),
    });
    visit('/portfolio');
    render(<App />);
    const list = await screen.findByRole('list', { name: 'Portfolio' });
    const rows = within(list).getAllByRole('listitem');
    for (const [index, quantity] of [
      [0, 2],
      [1, 1],
      [2, 3],
    ] as const) {
      const row = rows[index] as HTMLElement;
      const chip = within(row).getByText(`×${String(quantity)}`);
      expect(chip.tagName).toBe('SPAN');
      expect(chip.getAttribute('aria-hidden')).toBe('true');
      expect(chip.childNodes).toHaveLength(1);
      const hidden = within(row).getByText(`Quantity ${String(quantity)}`);
      expect(hidden.childNodes).toHaveLength(1);
      expect(hidden.closest('[aria-hidden]')).toBeNull();
    }
  });

  it.each([
    ['/watchlist', 'GET /v1/watchlist', watchlistBody(), 'Watchlist'],
    ['/portfolio', 'GET /v1/portfolio', portfolioBody(), 'Portfolio'],
  ] as const)(
    '%s: each row leads with a text-free card placeholder carrying the initial, not a link or an image',
    async (path, key, body, listName) => {
      stubAccountFetch({ 'GET /v1/me': jsonResponse(demoMe), [key]: jsonResponse(body) });
      visit(path);
      render(<App />);
      const list = await screen.findByRole('list', { name: listName });
      const initials = within(list)
        .getAllByRole('listitem')
        .map((row) => {
          const placeholder = row.querySelector('[data-initial]');
          if (placeholder === null) throw new Error('a placeholder per row');
          expect(placeholder.textContent).toBe('');
          expect(placeholder.closest('a')).toBeNull();
          expect(placeholder.querySelector('img, svg')).toBeNull();
          expect(placeholder.matches('div[aria-hidden]')).toBe(false);
          return placeholder.getAttribute('data-initial');
        });
      expect(initials).toEqual(['C', 'C', 'C']);
      expect(main().querySelectorAll('div[aria-hidden]')).toHaveLength(0);
    },
  );
});

describe('the alert select across a refetch (P5 carry-forward)', () => {
  it('keeps an unsaved choice when a refetch brings a new threshold for the row', async () => {
    stubAccountFetch({
      'GET /v1/me': jsonResponse(signedInMe),
      'GET /v1/watchlist': [
        jsonResponse(watchlistBody(watchlistItems.map((item) => ({ ...item, alert_pct: 5 })))),
        jsonResponse(
          watchlistBody(
            watchlistItems
              .filter((_, index) => index !== 0)
              .map((item) => ({ ...item, alert_pct: 10 })),
          ),
        ),
      ],
      'DELETE /v1/watchlist/base1-4%3Aholo%3A1st%3A-': rawResponse(204, null),
    });
    visit('/watchlist');
    render(<App />);
    await screen.findByRole('list', { name: 'Watchlist' });
    const select = (): HTMLSelectElement =>
      screen.getByRole<HTMLSelectElement>('combobox', {
        name: 'Alert threshold for Charizard, holo, unlimited',
      });
    fireEvent.change(select(), { target: { value: '20' } });
    fireEvent.click(
      screen.getByRole('button', { name: 'Remove Charizard, holo, 1st from your watchlist' }),
    );
    await waitFor(() => {
      expect(screen.getAllByText('Alert: at 10%')).toHaveLength(2);
    });
    expect(select().value).toBe('20');
    // An untouched select follows the refetched threshold.
    expect(
      screen.getByRole<HTMLSelectElement>('combobox', {
        name: 'Alert threshold for Chimecho, reverse',
      }).value,
    ).toBe('10');
  });
});
