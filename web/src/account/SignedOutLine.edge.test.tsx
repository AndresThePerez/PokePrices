// The home page's "You are signed out." line at its edges: a sign-out the API answers 401 (the
// session had already ended) from the demo strip of the home page itself, Back and Forward over
// the home entry that showed it, and a sign-out that failed.
import { act, cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import App from '../App';
import { HOME_H1 } from '../routes';
import {
  answerMovers,
  clearXsrfCookie,
  demoMe,
  isMoversUrl,
  jsonResponse,
  problemResponse,
  rawResponse,
  serverErrorProblem,
  setXsrfCookie,
  signedInMe,
  stubAccountFetch,
  unauthenticatedProblem,
  type AccountAnswer,
} from '../test/fixtures';

const SIGNED_OUT_LINE = 'You are signed out.';

function visit(path: string): void {
  window.history.replaceState(null, '', path);
}

async function settle(): Promise<void> {
  for (let round = 0; round < 5; round += 1) {
    await act(async () => {
      await new Promise((resolve) => setTimeout(resolve, 0));
    });
  }
}

/** The account stub plus the home page's movers reads. */
function stub(routes: Readonly<Record<string, AccountAnswer>>) {
  return stubAccountFetch(
    new Proxy(routes, {
      get(target, key) {
        if (typeof key !== 'string') return undefined;
        const url = key.replace(/^GET /, '');
        if (key.startsWith('GET ') && isMoversUrl(url)) return answerMovers(url);
        return target[key];
      },
    }),
  );
}

function lastNavName(): string | null {
  const nav = within(screen.getByRole('banner')).getByRole('navigation', { name: 'Main' });
  return within(nav).getAllByRole('link').at(-1)?.textContent ?? null;
}

afterEach(() => {
  cleanup();
  clearXsrfCookie();
  window.localStorage.clear();
  visit('/');
});

describe('the signed-out line at its edges (review)', () => {
  it('shows once when the demo strip of the home page signs out and the API answers 401', async () => {
    let me: Response = jsonResponse(demoMe);
    const calls = stub({
      'GET /v1/me': () => me,
      'POST /logout': () => {
        me = problemResponse(unauthenticatedProblem);
        return problemResponse({ ...unauthenticatedProblem, instance: '/logout' });
      },
    });
    // A reload of the home page in the demo's browser: the cookie is held, so the page asks.
    setXsrfCookie();
    visit('/');
    render(<App />);
    const banner = screen.getByRole('banner');
    fireEvent.click(await within(banner).findByRole('button', { name: 'Sign out' }));
    await screen.findByText(SIGNED_OUT_LINE);
    await settle();
    expect(window.location.pathname).toBe('/');
    const lines = screen.getAllByText(SIGNED_OUT_LINE);
    expect(lines).toHaveLength(1);
    expect(lines[0]?.getAttribute('role')).toBe('status');
    expect(screen.getByRole('main').contains(lines[0] ?? null)).toBe(true);
    expect(screen.queryByText(/Could not sign out/)).toBeNull();
    expect(within(banner).queryByRole('button', { name: 'Sign out' })).toBeNull();
    expect(lastNavName()).toBe('Sign in');
    expect(calls.filter((call) => call.key === 'POST /logout')).toHaveLength(1);
  });

  it('is not shown again when Back and then Forward return to the home entry that showed it', async () => {
    let me: Response = jsonResponse(signedInMe);
    stub({
      'GET /v1/me': () => me,
      'POST /logout': () => {
        me = problemResponse(unauthenticatedProblem);
        return rawResponse(204, null);
      },
    });
    visit('/account');
    render(<App />);
    fireEvent.click(await screen.findByRole('button', { name: 'Sign out' }));
    await screen.findByText(SIGNED_OUT_LINE);
    await settle();
    act(() => {
      window.history.back();
    });
    await waitFor(() => {
      expect(window.location.pathname).toBe('/account');
    });
    expect(await screen.findByText('You are not signed in.')).toBeTruthy();
    expect(screen.queryByText(SIGNED_OUT_LINE)).toBeNull();
    act(() => {
      window.history.forward();
    });
    await waitFor(() => {
      expect(window.location.pathname).toBe('/');
    });
    expect(await screen.findByRole('heading', { level: 1, name: HOME_H1 })).toBeTruthy();
    await settle();
    expect(screen.queryByText(SIGNED_OUT_LINE)).toBeNull();
  });

  it('is not shown after a sign-out that failed: the block stays on /account with no Try again', async () => {
    const calls = stub({
      'GET /v1/me': jsonResponse(signedInMe),
      'POST /logout': problemResponse(serverErrorProblem('/logout')),
    });
    visit('/account');
    render(<App />);
    fireEvent.click(await screen.findByRole('button', { name: 'Sign out' }));
    const alert = await screen.findByRole('alert');
    expect(alert.textContent).toBe(
      'Could not sign out: Internal Server ErrorRequest ID req-p4-server-0007',
    );
    expect(window.location.pathname).toBe('/account');
    expect(screen.queryByRole('button', { name: 'Try again' })).toBeNull();
    expect(calls.filter((call) => call.key === 'POST /logout')).toHaveLength(1);
    // Still signed in, the visitor walks to the home page: no line there.
    fireEvent.click(within(screen.getByRole('banner')).getByRole('link', { name: 'PokéPrices' }));
    expect(await screen.findByRole('heading', { level: 1, name: HOME_H1 })).toBeTruthy();
    await settle();
    expect(screen.queryByText(SIGNED_OUT_LINE)).toBeNull();
    expect(lastNavName()).toBe('Account');
  });
});
