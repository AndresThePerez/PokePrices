// The line the home page shows after a sign-out: "You are signed out.", once, in a status region,
// whether the sign-out started on the account page or in the demo strip of the home page itself;
// a reload of the home page does not show it, and a visitor who only opens the home page never
// sees it.
import { act, cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import App from '../App';
import {
  FORBIDDEN_WORDS,
  answerMovers,
  clearXsrfCookie,
  demoMe,
  isMoversUrl,
  jsonResponse,
  problemResponse,
  rawResponse,
  signedInMe,
  stubAccountFetch,
  unauthenticatedProblem,
  type AccountAnswer,
} from '../test/fixtures';

const HOME_H1 = 'Pokémon card prices at the daily close';
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

/** The account stub plus the home page's public reads (the movers; the freshness read fails). */
function stub(routes: Readonly<Record<string, AccountAnswer>>) {
  const calls = stubAccountFetch(
    new Proxy(routes, {
      get(target, key) {
        if (typeof key !== 'string') return undefined;
        const url = key.replace(/^GET /, '');
        if (key.startsWith('GET ') && isMoversUrl(url)) return answerMovers(url);
        return target[key];
      },
    }),
  );
  return calls;
}

afterEach(() => {
  cleanup();
  clearXsrfCookie();
  window.localStorage.clear();
  visit('/');
});

describe('the signed-out line on the home page', () => {
  it('shows once in a status region after Sign out on the account page, and not after a reload', async () => {
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
    await screen.findByRole('heading', { level: 1, name: HOME_H1 });
    await settle();
    expect(window.location.pathname).toBe('/');
    const lines = screen.getAllByText(SIGNED_OUT_LINE);
    expect(lines).toHaveLength(1);
    const line = lines[0] as HTMLElement;
    expect(line.getAttribute('role')).toBe('status');
    expect(screen.getByRole('main').contains(line)).toBe(true);
    for (const word of FORBIDDEN_WORDS) expect(line.textContent).not.toMatch(word);
    const nav = within(screen.getByRole('banner')).getByRole('navigation', { name: 'Main' });
    expect(within(nav).getAllByRole('link').at(-1)?.textContent).toBe('Sign in');

    // A reload: the same address and history entry, a new document.
    cleanup();
    render(<App />);
    await settle();
    expect(screen.getByRole('heading', { level: 1, name: HOME_H1 })).toBeTruthy();
    expect(screen.queryByText(SIGNED_OUT_LINE)).toBeNull();
  });

  it('shows after the demo signs out from the strip while already on the home page', async () => {
    let me: Response = jsonResponse(demoMe);
    stub({
      'GET /v1/me': () => me,
      'POST /logout': () => {
        me = problemResponse(unauthenticatedProblem);
        return rawResponse(204, null);
      },
    });
    visit('/account');
    render(<App />);
    await screen.findByText('Signed in as demo@example.com');
    fireEvent.click(within(screen.getByRole('banner')).getByRole('link', { name: 'PokéPrices' }));
    await settle();
    expect(screen.queryByText(SIGNED_OUT_LINE)).toBeNull();
    fireEvent.click(within(screen.getByRole('banner')).getByRole('button', { name: 'Sign out' }));
    await settle();
    expect(window.location.pathname).toBe('/');
    const lines = screen.getAllByText(SIGNED_OUT_LINE);
    expect(lines).toHaveLength(1);
    expect(lines[0]?.getAttribute('role')).toBe('status');
  });

  it('is gone when the visitor leaves the home page and comes back', async () => {
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
    const banner = screen.getByRole('banner');
    fireEvent.click(within(banner).getByRole('link', { name: 'Movers' }));
    await settle();
    fireEvent.click(within(banner).getByRole('link', { name: 'PokéPrices' }));
    await settle();
    expect(screen.getByRole('heading', { level: 1, name: HOME_H1 })).toBeTruthy();
    expect(screen.queryByText(SIGNED_OUT_LINE)).toBeNull();
  });

  it('is not shown to a visitor who opens the home page signed out', async () => {
    stub({ 'GET /v1/me': problemResponse(unauthenticatedProblem) });
    visit('/');
    render(<App />);
    await settle();
    expect(screen.getByRole('heading', { level: 1, name: HOME_H1 })).toBeTruthy();
    expect(screen.queryByText(SIGNED_OUT_LINE)).toBeNull();
  });
});
