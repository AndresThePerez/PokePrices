// The header's last link after this tab's own sign-out, in a browser that still holds the CSRF
// cookie (a sign-out does not remove it): the cached signed-out answer keeps "Sign in" on the home
// page, on a page that asks nothing and on a not-found address, and the walk asks nothing more.
import { act, cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import { holdsSessionCookie } from './account/session';
import App from './App';
import { HOME_H1 } from './routes';
import {
  answerMovers,
  clearXsrfCookie,
  isMoversUrl,
  jsonResponse,
  problemResponse,
  rawResponse,
  setXsrfCookie,
  signedInMe,
  stubAccountFetch,
  unauthenticatedProblem,
  type AccountAnswer,
} from './test/fixtures';

function visit(path: string): void {
  window.history.replaceState(null, '', path);
}

function popTo(path: string): void {
  act(() => {
    window.history.pushState(null, '', path);
    window.dispatchEvent(new PopStateEvent('popstate'));
  });
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

function lastLink(): [string | null, string | null, string | null] {
  const nav = within(screen.getByRole('banner')).getByRole('navigation', { name: 'Main' });
  const last = within(nav).getAllByRole('link').at(-1);
  return [
    last?.textContent ?? null,
    last?.getAttribute('href') ?? null,
    last?.getAttribute('aria-current') ?? null,
  ];
}

afterEach(() => {
  cleanup();
  clearXsrfCookie();
  window.localStorage.clear();
  visit('/');
});

describe('the header label after a sign-out with the cookie still held (review)', () => {
  it('reads Sign in on home, on /about and on a not-found address, asking nothing more', async () => {
    let me: Response = jsonResponse(signedInMe);
    const calls = stub({
      'GET /v1/me': () => me,
      'POST /logout': () => {
        me = problemResponse(unauthenticatedProblem);
        return rawResponse(204, null);
      },
    });
    setXsrfCookie();
    visit('/account');
    render(<App />);
    fireEvent.click(await screen.findByRole('button', { name: 'Sign out' }));
    await screen.findByRole('heading', { level: 1, name: HOME_H1 });
    await settle();
    expect(holdsSessionCookie()).toBe(true);
    expect(lastLink()).toEqual(['Sign in', '/sign-in', null]);
    const asked = calls.filter((call) => call.key === 'GET /v1/me').length;

    popTo('/about');
    await settle();
    expect(screen.getByRole('heading', { level: 1 }).textContent).toBe('About');
    expect(lastLink()).toEqual(['Sign in', '/sign-in', null]);

    popTo('/no/such/page');
    await settle();
    expect(screen.getByRole('heading', { level: 1 }).textContent).toBe('Page not found');
    expect(lastLink()).toEqual(['Sign in', '/sign-in', null]);
    expect(within(screen.getByRole('banner')).queryByRole('link', { name: 'Account' })).toBeNull();
    expect(calls.filter((call) => call.key === 'GET /v1/me')).toHaveLength(asked);
  });
});
