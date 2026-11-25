import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import App from '../App';
import { safeNext } from '../router';
import {
  accountValidationProblem,
  clearXsrfCookie,
  holdSessionCookie,
  domHolds,
  jsonResponse,
  problemResponse,
  rawResponse,
  sentBody,
  serverErrorProblem,
  signedInMe,
  stubAccountFetch,
  unauthenticatedProblem,
} from '../test/fixtures';

const PASSWORD = 'fake-password-0000-ok';
const TOKEN = 'fake-reset-token-0000';
const HTML = '<!doctype html><html><body><div id="root"></div></body></html>';

function visit(path: string): void {
  window.history.replaceState(null, '', path);
}

afterEach(() => {
  cleanup();
  clearXsrfCookie();
  window.localStorage.clear();
  window.sessionStorage.clear();
  visit('/');
});

describe('next is only ever an in-app route (D10, open redirect)', () => {
  it.each([
    '/%2F%2Fevil.example',
    '/%2f%2fevil.example',
    '/\u0000',
    '/\\/evil.example',
    '/\t/evil.example',
    '/\n/evil.example',
    '/%5C%5Cevil.example',
    '/CARD/ex10-1',
    '/Account',
    '/MOVERS',
    'JAVASCRIPT:alert(1)',
    'javascript:alert(1)',
    ' //evil.example',
    '\\\\evil.example',
    'data:text/html,x',
    '/card',
    '/card/',
    '/card/a/b',
    '/sign-in/',
    '/account/',
    '%2F%2Fevil.example',
    '%2Faccount',
  ])('refuses %j and lands on /account', (raw) => {
    expect(safeNext(raw)).toBe('/account');
  });

  it('keeps a path that matches even when its query carries a hostile string, never rewritten', () => {
    const raw = '/card/ex10-1?next=//evil.example&x=javascript:alert(1)';
    expect(safeNext(raw)).toBe(raw);
  });

  it.each([
    '//evil.example',
    '/%2F%2Fevil.example',
    '/\\evil.example',
    'https://evil.example/x',
    '%252F%252Fevil.example',
  ])('opens /account after a sign-in with next=%s and never leaves the origin', async (next) => {
    stubAccountFetch({
      'GET /v1/me': [problemResponse(unauthenticatedProblem), jsonResponse(signedInMe)],
      'POST /login': jsonResponse({ two_factor: false }),
    });
    visit(`/sign-in?next=${encodeURIComponent(next)}`);
    render(<App />);
    const main = await screen.findByRole('main');
    fireEvent.change(await within(main).findByLabelText('Email'), {
      target: { value: 'collector@example.com' },
    });
    fireEvent.change(within(main).getByLabelText('Password'), { target: { value: PASSWORD } });
    fireEvent.click(within(main).getByRole('button', { name: 'Sign in' }));
    await waitFor(() => {
      expect(window.location.pathname).toBe('/account');
    });
    expect(window.location.origin).toBe('http://localhost:3000');
    expect(window.location.search).toBe('');
  });
});

describe('the reset token leaves the address bar and never lands anywhere else', () => {
  const LINK = `/password/reset?token=${TOKEN}&email=${encodeURIComponent('a+b@example.com')}`;

  function save(): void {
    const main = screen.getByRole('main');
    fireEvent.change(within(main).getByLabelText('New password'), { target: { value: PASSWORD } });
    fireEvent.change(within(main).getByLabelText('Confirm new password'), {
      target: { value: PASSWORD },
    });
    fireEvent.click(within(main).getByRole('button', { name: 'Save password' }));
  }

  it('keeps the token out of the URL, history state, storage and markup, and a "+" in the email intact', async () => {
    const calls = stubAccountFetch({
      'POST /reset-password': problemResponse(
        accountValidationProblem('/reset-password', { password: ['Too short.'] }),
      ),
    });
    visit(LINK);
    render(<App />);
    expect(window.location.href).not.toContain(TOKEN);
    expect(window.location.search).toBe('');
    expect(window.history.state).toBeNull();
    expect(domHolds(TOKEN)).toBe(false);
    save();
    await screen.findByRole('alert');
    expect(sentBody(calls.find((call) => call.key === 'POST /reset-password'))).toMatchObject({
      token: TOKEN,
      email: 'a+b@example.com',
    });
    expect(window.location.href).not.toContain(TOKEN);
    expect(window.history.state).toBeNull();
    expect(domHolds(TOKEN)).toBe(false);
    expect(domHolds(PASSWORD)).toBe(false);
    expect(JSON.stringify(Object.entries(window.localStorage))).not.toContain(TOKEN);
    expect(JSON.stringify(Object.entries(window.sessionStorage))).not.toContain(TOKEN);
    expect(window.localStorage.length + window.sessionStorage.length).toBe(0);
  });

  it('sends a double submit once', async () => {
    const calls = stubAccountFetch({
      'POST /reset-password': new Promise<never>(() => undefined) as never,
    });
    visit(LINK);
    render(<App />);
    const main = screen.getByRole('main');
    const form = main.querySelector('form');
    expect(form).not.toBeNull();
    if (form === null) return;
    // The form checks itself before it is sent: both passwords hold a value that passes.
    for (const label of ['New password', 'Confirm new password']) {
      fireEvent.change(within(main).getByLabelText(label), {
        target: { value: 'fake-password-0000-ok' },
      });
    }
    fireEvent.submit(form);
    fireEvent.submit(form);
    await waitFor(() => {
      expect(calls.filter((call) => call.key === 'POST /reset-password').length).toBeGreaterThan(0);
    });
    expect(calls.filter((call) => call.key === 'POST /reset-password')).toHaveLength(1);
  });
});

describe('an answer that is not the documented JSON is a failure, never a session', () => {
  it('shows the Problem block, not signed-out, for a 200 HTML page on GET /v1/me', async () => {
    const calls = stubAccountFetch({ 'GET /v1/me': rawResponse(200, HTML, 'text/html') });
    visit('/account');
    render(<App />);
    const alert = await screen.findByRole('alert');
    expect(alert.textContent).toContain('Could not load your account');
    expect(screen.queryByText('You are not signed in.')).toBeNull();
    expect(screen.queryByText(/^Signed in as/)).toBeNull();
    expect(calls.filter((call) => call.key === 'GET /v1/me')).toHaveLength(1);
  });

  it('shows the Problem block, not "undefined", for a 200 JSON body of the wrong shape on GET /v1/me', async () => {
    stubAccountFetch({ 'GET /v1/me': jsonResponse({}) });
    visit('/account');
    render(<App />);
    const alert = await screen.findByRole('alert');
    expect(alert.textContent).toContain('Could not load your account');
    expect(alert.textContent).not.toContain('undefined');
    expect(screen.queryByText('You are not signed in.')).toBeNull();
  });

  it('shows the Problem block, not signed-out, for a 500 on GET /v1/me', async () => {
    holdSessionCookie();
    const calls = stubAccountFetch({
      'GET /v1/me': problemResponse(serverErrorProblem('/v1/me')),
    });
    visit('/account');
    render(<App />);
    const alert = await screen.findByRole('alert');
    expect(alert.textContent).toContain('Could not load your account: Internal Server Error');
    expect(screen.queryByText('You are not signed in.')).toBeNull();
    expect(calls.filter((call) => call.key === 'GET /v1/me')).toHaveLength(1);
  });

  it('keeps a 200 HTML answer to POST /login a failure: the form stays, the password is cleared', async () => {
    const calls = stubAccountFetch({
      'GET /v1/me': problemResponse(unauthenticatedProblem),
      'POST /login': rawResponse(200, HTML, 'text/html'),
    });
    visit('/sign-in');
    render(<App />);
    const main = screen.getByRole('main');
    fireEvent.change(await within(main).findByLabelText('Email'), {
      target: { value: 'collector@example.com' },
    });
    fireEvent.change(within(main).getByLabelText('Password'), { target: { value: PASSWORD } });
    fireEvent.click(within(main).getByRole('button', { name: 'Sign in' }));
    const alert = await screen.findByRole('alert');
    expect(alert.textContent).toContain('Could not sign in');
    expect(window.location.pathname).toBe('/sign-in');
    expect(domHolds(PASSWORD)).toBe(false);
    expect(calls.filter((call) => call.key === 'GET /v1/me')).toEqual([]);
  });

  it('keeps a refused redirect on POST /login a failure the page can show', async () => {
    stubAccountFetch({
      'GET /v1/me': problemResponse(unauthenticatedProblem),
      'POST /login': new TypeError('redirect mode is set to error'),
    });
    visit('/sign-in');
    render(<App />);
    const main = screen.getByRole('main');
    fireEvent.change(await within(main).findByLabelText('Email'), {
      target: { value: 'collector@example.com' },
    });
    fireEvent.change(within(main).getByLabelText('Password'), { target: { value: PASSWORD } });
    fireEvent.click(within(main).getByRole('button', { name: 'Sign in' }));
    const alert = await screen.findByRole('alert');
    expect(alert.textContent).toContain('Could not sign in');
    expect(window.location.pathname).toBe('/sign-in');
    expect(domHolds(PASSWORD)).toBe(false);
  });
});

describe('a hostile verification link is never fetched', () => {
  it.each([
    'https://evil.example/email/verify/1/h?x',
    '//evil.example/email/verify/1/h?x',
    '/email/verify/../../v1/me',
    '/email/verify/%2e%2e/%2e%2e?x',
    '/email/verify/1/h',
    '/email/verify/1/h#?x',
    '/email/verify//h?x',
    '/email/verify/1/2/3?x',
    '/EMAIL/verify/1/h?x',
    '/email/verify\\..\\..\\v1\\me/h?x',
    'javascript:alert(1)',
    '',
  ])('shows the invalid-link line and sends no request for %j', (link) => {
    const calls = stubAccountFetch({});
    visit(`/account/verify-email?link=${encodeURIComponent(link)}`);
    render(<App />);
    expect(
      screen.getByText(
        'This verification link is not valid or has expired. Send a new one from your account.',
      ),
    ).toBeTruthy();
    expect(calls).toHaveLength(0);
  });
});

describe('public pages make no account request', () => {
  it.each(['/', '/search?q=unown', '/movers', '/card/ex10-1', '/nowhere'])(
    'sends nothing to the session or /v1/me paths on %s',
    async (path) => {
      const calls = stubAccountFetch({});
      visit(path);
      render(<App />);
      await new Promise((resolve) => setTimeout(resolve, 30));
      const account = calls.filter((call) =>
        /^\/(?:sanctum|login|logout|register|forgot-password|reset-password|email|user|two-factor)|^\/v1\/(?:me|watchlist|portfolio|demo)/.test(
          call.url,
        ),
      );
      expect(account).toEqual([]);
    },
  );

  it('does not fetch the session when the header Sign in link is only rendered', () => {
    const calls = stubAccountFetch({});
    vi.useFakeTimers();
    try {
      visit('/movers');
      render(<App />);
      expect(screen.getByRole('link', { name: 'Sign in' }).getAttribute('href')).toBe('/sign-in');
      expect(calls.filter((call) => call.url === '/v1/me')).toEqual([]);
    } finally {
      vi.useRealTimers();
    }
  });
});
