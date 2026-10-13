import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import App from '../App';
import {
  FORBIDDEN_WORDS,
  accountValidationProblem,
  alreadySignedInProblem,
  clearXsrfCookie,
  domHolds,
  jsonResponse,
  lightOnlyClasses,
  problemResponse,
  sentBody,
  shortControls,
  signedInMe,
  stubAccountFetch,
  tooManyRequestsProblem,
  unauthenticatedProblem,
} from '../test/fixtures';

const TYPED = 'fake-password-0000-ok';
const WRONG = 'These credentials do not match our records.';

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

function fill(label: string, value: string): void {
  fireEvent.change(within(main()).getByLabelText(label), { target: { value } });
}

async function signedOutForm(): Promise<void> {
  await screen.findByRole('button', { name: 'Sign in' });
}

function submit(): void {
  fireEvent.click(within(main()).getByRole('button', { name: 'Sign in' }));
}

describe('the sign-in page (T03 criterion 3, D10)', () => {
  it('shows the loading line, then the form with its exact labels, autocomplete and links', async () => {
    stubAccountFetch({ 'GET /v1/me': problemResponse(unauthenticatedProblem) });
    visit('/sign-in');
    render(<App />);
    expect(screen.getByRole('heading', { level: 1 }).textContent).toBe('Sign in');
    expect(screen.getByRole('status').textContent).toBe('Loading your account…');
    await signedOutForm();
    const email = within(main()).getByLabelText('Email');
    expect(email.getAttribute('autocomplete')).toBe('username');
    expect(email.getAttribute('type')).toBe('email');
    const password = within(main()).getByLabelText('Password');
    expect(password.getAttribute('autocomplete')).toBe('current-password');
    expect(password.getAttribute('type')).toBe('password');
    expect((password as HTMLInputElement).value).toBe('');
    expect(within(main()).getByText('Email').tagName).toBe('LABEL');
    expect(within(main()).getByText('Password').tagName).toBe('LABEL');
    const links = within(main())
      .getAllByRole('link')
      .map((link) => [link.textContent, link.getAttribute('href')]);
    expect(links).toEqual([
      ['Forgot your password?', '/password/forgot'],
      ['Create an account', '/sign-up'],
    ]);
    expect(screen.queryByRole('alert')).toBeNull();
  });

  it('signs in, refetches the session and lands on /account', async () => {
    const calls = stubAccountFetch({
      'GET /v1/me': [problemResponse(unauthenticatedProblem), jsonResponse(signedInMe)],
      'POST /login': jsonResponse({ two_factor: false }),
    });
    visit('/sign-in');
    render(<App />);
    await signedOutForm();
    fill('Email', 'collector@example.com');
    fill('Password', TYPED);
    submit();
    await waitFor(() => {
      expect(window.location.pathname).toBe('/account');
    });
    expect(await screen.findByText('Signed in as collector@example.com')).toBeTruthy();
    expect(screen.getByRole('heading', { level: 1 }).textContent).toBe('Account');
    const login = calls.find((call) => call.key === 'POST /login');
    expect(sentBody(login)).toEqual({ email: 'collector@example.com', password: TYPED });
    expect(calls.filter((call) => call.key === 'GET /v1/me').length).toBeGreaterThanOrEqual(2);
    expect(domHolds(TYPED)).toBe(false);
  });

  it('follows a valid next after signing in', async () => {
    stubAccountFetch({
      'GET /v1/me': [problemResponse(unauthenticatedProblem), jsonResponse(signedInMe)],
      'POST /login': jsonResponse({ two_factor: false }),
    });
    visit(`/sign-in?next=${encodeURIComponent('/search')}`);
    render(<App />);
    await signedOutForm();
    fill('Email', 'collector@example.com');
    fill('Password', TYPED);
    submit();
    await waitFor(() => {
      expect(window.location.pathname).toBe('/search');
    });
    expect(screen.getByRole('heading', { level: 1 }).textContent).toBe('Search cards');
  });

  it('ignores a next that leaves the site and lands on /account', async () => {
    stubAccountFetch({
      'GET /v1/me': [problemResponse(unauthenticatedProblem), jsonResponse(signedInMe)],
      'POST /login': jsonResponse({ two_factor: false }),
    });
    const origin = window.location.origin;
    visit(`/sign-in?next=${encodeURIComponent('//evil.example/account')}`);
    render(<App />);
    await signedOutForm();
    fill('Email', 'collector@example.com');
    fill('Password', TYPED);
    submit();
    await waitFor(() => {
      expect(window.location.pathname).toBe('/account');
    });
    expect(window.location.origin).toBe(origin);
    expect(window.location.search).toBe('');
  });

  it('shows a 422 under its field, names the context and clears the password', async () => {
    stubAccountFetch({
      'GET /v1/me': problemResponse(unauthenticatedProblem),
      'POST /login': problemResponse(accountValidationProblem('/login', { email: [WRONG] })),
    });
    visit('/sign-in');
    render(<App />);
    await signedOutForm();
    fill('Email', 'collector@example.com');
    fill('Password', TYPED);
    submit();
    const alert = await screen.findByRole('alert');
    expect(alert.textContent).toContain('Could not sign in: check the fields below.');
    const email = within(main()).getByLabelText('Email');
    expect(email.getAttribute('aria-invalid')).toBe('true');
    const describedBy = email.getAttribute('aria-describedby') ?? '';
    const message = document.getElementById(describedBy);
    expect(message?.textContent).toBe(WRONG);
    expect((email as HTMLInputElement).value).toBe('collector@example.com');
    expect(within(main()).getByLabelText<HTMLInputElement>('Password').value).toBe('');
    expect(within(main()).getByLabelText('Password').getAttribute('aria-invalid')).toBeNull();
    expect(domHolds(TYPED)).toBe(false);
    expect(window.location.pathname).toBe('/sign-in');
  });

  it('shows the 429 line after too many attempts', async () => {
    stubAccountFetch({
      'GET /v1/me': problemResponse(unauthenticatedProblem),
      'POST /login': problemResponse(tooManyRequestsProblem('/login')),
    });
    visit('/sign-in');
    render(<App />);
    await signedOutForm();
    fill('Email', 'collector@example.com');
    fill('Password', TYPED);
    submit();
    const alert = await screen.findByRole('alert');
    expect(alert.textContent).toContain(
      'Could not sign in: Too Many Requests. Wait a minute and try again.',
    );
    expect(alert.textContent).toContain('Request ID req-p4-throttle-0005');
    expect(domHolds(TYPED)).toBe(false);
  });

  it('turns a 409 already-signed-in into its line and a link to the account', async () => {
    stubAccountFetch({
      'GET /v1/me': problemResponse(unauthenticatedProblem),
      'POST /login': problemResponse(alreadySignedInProblem),
    });
    visit('/sign-in');
    render(<App />);
    await signedOutForm();
    fill('Email', 'collector@example.com');
    fill('Password', TYPED);
    submit();
    const alert = await screen.findByRole('alert');
    expect(alert.textContent).toContain('You are already signed in.');
    const link = within(alert).getByRole('link', { name: 'Go to your account' });
    expect(link.getAttribute('href')).toBe('/account');
  });

  it('says who is signed in instead of the form (D13, Vitest half)', async () => {
    const calls = stubAccountFetch({ 'GET /v1/me': jsonResponse(signedInMe) });
    visit('/sign-in');
    render(<App />);
    expect(await screen.findByText('You are signed in as collector@example.com.')).toBeTruthy();
    const link = within(main()).getByRole('link', { name: 'Go to your account' });
    expect(link.getAttribute('href')).toBe('/account');
    expect(within(main()).queryByRole('button', { name: 'Sign in' })).toBeNull();
    expect(calls.map((call) => call.key)).toEqual(['GET /v1/me']);
  });
});

describe('sign-in accessibility and honesty (T03 criterion 7)', () => {
  const states = [
    ['signed out', { 'GET /v1/me': problemResponse(unauthenticatedProblem) }, 'Sign in'],
    ['signed in', { 'GET /v1/me': jsonResponse(signedInMe) }, 'Go to your account'],
  ] as const;

  it.each(states)(
    '%s: dark counterparts, 44 px controls, no forbidden word',
    async (_n, routes, ready) => {
      vi.useFakeTimers({ toFake: ['Date'] });
      vi.setSystemTime(new Date('2031-02-03T04:05:06Z'));
      stubAccountFetch(routes);
      visit('/sign-in');
      const { container } = render(<App />);
      await screen.findAllByText(ready);
      expect(lightOnlyClasses(container)).toEqual([]);
      expect(shortControls(main())).toEqual([]);
      const text = main().textContent;
      for (const pattern of FORBIDDEN_WORDS) expect(text).not.toMatch(pattern);
      expect(text).not.toContain('2031');
    },
  );

  it('keeps every failure state dark-ready and free of forbidden words', async () => {
    stubAccountFetch({
      'GET /v1/me': problemResponse(unauthenticatedProblem),
      'POST /login': [
        problemResponse(accountValidationProblem('/login', { email: [WRONG] })),
        problemResponse(tooManyRequestsProblem('/login')),
        problemResponse(alreadySignedInProblem),
      ],
    });
    visit('/sign-in');
    const { container } = render(<App />);
    await signedOutForm();
    for (const expected of ['check the fields below', 'Too Many Requests', 'already signed in']) {
      fill('Email', 'collector@example.com');
      fill('Password', TYPED);
      submit();
      await screen.findByText(new RegExp(expected));
      expect(lightOnlyClasses(container)).toEqual([]);
      expect(shortControls(main())).toEqual([]);
      for (const pattern of FORBIDDEN_WORDS) expect(main().textContent).not.toMatch(pattern);
      expect(domHolds(TYPED)).toBe(false);
    }
  });
});
