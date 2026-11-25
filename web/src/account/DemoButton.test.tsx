import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import App from '../App';
import {
  FORBIDDEN_WORDS,
  clearXsrfCookie,
  holdSessionCookie,
  demoMe,
  demoUnavailableProblem,
  jsonResponse,
  lightOnlyClasses,
  portfolioBody,
  problemResponse,
  rawResponse,
  shortControls,
  signedInMe,
  stubAccountFetch,
  tooManyRequestsProblem,
  unauthenticatedProblem,
  watchlistBody,
} from '../test/fixtures';

const DEMO = 'Try the read-only demo';
const DEMO_NOTICE =
  'You are signed in to the read-only demo account. Its watchlist and portfolio cannot be changed.';

function visit(path: string): void {
  window.history.replaceState(null, '', path);
}

afterEach(() => {
  cleanup();
  clearXsrfCookie();
  vi.useRealTimers();
  visit('/');
});

function main(): HTMLElement {
  return screen.getByRole('main');
}

describe('Try the read-only demo (T07 criterion 4, D8, D10)', () => {
  it('posts /v1/demo/session after the CSRF cookie, refetches the session and opens /watchlist', async () => {
    holdSessionCookie();
    const calls = stubAccountFetch({
      'GET /v1/me': [problemResponse(unauthenticatedProblem), jsonResponse(demoMe)],
      'POST /v1/demo/session': rawResponse(204, null),
      'GET /v1/watchlist': jsonResponse(watchlistBody()),
    });
    visit('/sign-in');
    render(<App />);
    fireEvent.click(await screen.findByRole('button', { name: DEMO }));
    await waitFor(() => {
      expect(window.location.pathname).toBe('/watchlist');
    });
    expect(await screen.findByText(DEMO_NOTICE)).toBeTruthy();
    expect(await screen.findByText('Market price $968.91')).toBeTruthy();
    const keys = calls.map((call) => call.key);
    expect(keys.slice(0, 3)).toEqual(['GET /v1/me', 'POST /v1/demo/session', 'GET /v1/me']);
    expect(keys.filter((key) => key === 'POST /v1/demo/session')).toHaveLength(1);
    const post = calls.find((call) => call.key === 'POST /v1/demo/session');
    expect(new Headers(post?.init?.headers).get('X-XSRF-TOKEN')).toBe('fake-xsrf+0000=');
  });

  it('shows the demo-unavailable problem and stays on the page', async () => {
    stubAccountFetch({
      'GET /v1/me': problemResponse(unauthenticatedProblem),
      'POST /v1/demo/session': problemResponse(demoUnavailableProblem),
    });
    visit('/sign-in');
    render(<App />);
    fireEvent.click(await screen.findByRole('button', { name: DEMO }));
    const alert = await screen.findByRole('alert');
    expect(alert.querySelector('p')?.textContent).toBe(
      'Could not start the demo: Demo Account Unavailable',
    );
    expect(alert.textContent).toBe('Could not start the demo: Demo Account Unavailable');
    expect(window.location.pathname).toBe('/sign-in');
  });

  it('shows the 429 line when the demo sign-in is throttled', async () => {
    stubAccountFetch({
      'GET /v1/me': problemResponse(unauthenticatedProblem),
      'POST /v1/demo/session': problemResponse(tooManyRequestsProblem('/v1/demo/session')),
    });
    visit('/sign-in');
    render(<App />);
    fireEvent.click(await screen.findByRole('button', { name: DEMO }));
    const alert = await screen.findByRole('alert');
    expect(alert.textContent).toContain(
      'Could not start the demo: Too Many Requests. Wait a minute and try again.',
    );
  });

  it('is offered on the signed-out watchlist and portfolio pages, and opens the demo from there', async () => {
    holdSessionCookie();
    stubAccountFetch({
      'GET /v1/me': [problemResponse(unauthenticatedProblem), jsonResponse(demoMe)],
      'POST /v1/demo/session': rawResponse(204, null),
      'GET /v1/watchlist': jsonResponse(watchlistBody()),
      'GET /v1/portfolio': jsonResponse(portfolioBody()),
    });
    visit('/portfolio');
    render(<App />);
    fireEvent.click(await screen.findByRole('button', { name: DEMO }));
    await waitFor(() => {
      expect(window.location.pathname).toBe('/watchlist');
    });
    expect(await screen.findByText(DEMO_NOTICE)).toBeTruthy();
  });

  it('is not offered once signed in', async () => {
    stubAccountFetch({ 'GET /v1/me': jsonResponse(signedInMe) });
    visit('/sign-in');
    render(<App />);
    await screen.findByText('You are signed in as collector@example.com.');
    expect(screen.queryByRole('button', { name: DEMO })).toBeNull();
  });
});

describe('the account page of the read-only demo (T07 criterion 4, D10)', () => {
  it('says it is the read-only demo account', async () => {
    stubAccountFetch({ 'GET /v1/me': jsonResponse(demoMe) });
    visit('/account');
    render(<App />);
    expect(await screen.findByText('Signed in as demo@example.com')).toBeTruthy();
    expect(within(screen.getByRole('banner')).getByText(DEMO_NOTICE)).toBeTruthy();
  });

  it('shows no demo notice for an ordinary account', async () => {
    stubAccountFetch({ 'GET /v1/me': jsonResponse(signedInMe) });
    visit('/account');
    render(<App />);
    await screen.findByText('Signed in as collector@example.com');
    expect(screen.queryByText(DEMO_NOTICE)).toBeNull();
  });

  it('keeps the demo views dark-ready, 44 px and free of forbidden words', async () => {
    vi.useFakeTimers({ toFake: ['Date'] });
    vi.setSystemTime(new Date('2031-02-03T04:05:06Z'));
    for (const [path, routes, ready] of [
      ['/account', { 'GET /v1/me': jsonResponse(demoMe) }, DEMO_NOTICE],
      [
        '/sign-in',
        {
          'GET /v1/me': problemResponse(unauthenticatedProblem),
          'POST /v1/demo/session': problemResponse(demoUnavailableProblem),
        },
        DEMO,
      ],
    ] as const) {
      stubAccountFetch(routes);
      visit(path);
      const { container } = render(<App />);
      await screen.findAllByText(ready);
      const demo = screen.queryByRole('button', { name: DEMO });
      if (demo !== null) {
        fireEvent.click(demo);
        await screen.findByRole('alert');
      }
      expect(lightOnlyClasses(container)).toEqual([]);
      expect(shortControls(main())).toEqual([]);
      for (const pattern of FORBIDDEN_WORDS) expect(main().textContent).not.toMatch(pattern);
      expect(main().textContent).not.toContain('2031');
      cleanup();
    }
  });
});
