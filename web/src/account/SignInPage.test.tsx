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
import { rawResponse } from '../test/fixtures';

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

describe('the two-factor challenge step (T04 criterion 1, D10)', () => {
  const CODE = '000000';
  const RECOVERY = 'fake-recovery-0001';
  const WRONG_CODE = 'The provided two factor authentication code was invalid.';
  const WRONG_RECOVERY = 'The provided two factor recovery code was invalid.';

  async function reachChallenge(
    routes: Parameters<typeof stubAccountFetch>[0],
    path = '/sign-in',
  ): Promise<ReturnType<typeof stubAccountFetch>> {
    const calls = stubAccountFetch({
      'GET /v1/me': [problemResponse(unauthenticatedProblem), jsonResponse(signedInMe)],
      'POST /login': jsonResponse({ two_factor: true }),
      ...routes,
    });
    visit(path);
    render(<App />);
    await signedOutForm();
    fill('Email', 'collector@example.com');
    fill('Password', TYPED);
    submit();
    await within(main()).findByRole('heading', { level: 2, name: 'Two-factor authentication' });
    return calls;
  }

  function verify(): void {
    fireEvent.click(within(main()).getByRole('button', { name: 'Verify' }));
  }

  it('replaces the sign-in form with the authentication code step', async () => {
    const calls = await reachChallenge({});
    expect(screen.getByRole('heading', { level: 1 }).textContent).toBe('Sign in');
    expect(within(main()).queryByLabelText('Email')).toBeNull();
    expect(within(main()).queryByLabelText('Password')).toBeNull();
    expect(within(main()).queryByRole('button', { name: 'Sign in' })).toBeNull();
    const code = within(main()).getByLabelText('Authentication code');
    expect(within(main()).getByText('Authentication code').tagName).toBe('LABEL');
    expect(code.getAttribute('inputmode')).toBe('numeric');
    expect(code.getAttribute('autocomplete')).toBe('one-time-code');
    expect(within(main()).getByRole('button', { name: 'Verify' })).toBeTruthy();
    expect(
      within(main()).getByRole('button', { name: 'Use a recovery code instead' }),
    ).toBeTruthy();
    expect(within(main()).queryByLabelText('Recovery code')).toBeNull();
    expect(domHolds(TYPED)).toBe(false);
    expect(window.location.pathname).toBe('/sign-in');
    // Nothing is signed in yet: the session is not refetched before the code.
    expect(calls.filter((call) => call.key === 'GET /v1/me')).toHaveLength(1);
  });

  it('posts {code} to /two-factor-challenge and lands on /account after a 204', async () => {
    const calls = await reachChallenge({ 'POST /two-factor-challenge': rawResponse(204, null) });
    fill('Authentication code', CODE);
    verify();
    await waitFor(() => {
      expect(window.location.pathname).toBe('/account');
    });
    expect(await screen.findByText('Signed in as collector@example.com')).toBeTruthy();
    const challenge = calls.filter((call) => call.key === 'POST /two-factor-challenge');
    expect(challenge).toHaveLength(1);
    expect(sentBody(challenge[0])).toEqual({ code: CODE });
    expect(calls.filter((call) => call.key === 'GET /v1/me').length).toBeGreaterThanOrEqual(2);
    expect(domHolds(TYPED)).toBe(false);
    expect(domHolds(CODE)).toBe(false);
  });

  it('switches to {recovery_code} and follows next after a 204', async () => {
    const calls = await reachChallenge(
      { 'POST /two-factor-challenge': rawResponse(204, null) },
      `/sign-in?next=${encodeURIComponent('/search')}`,
    );
    fireEvent.click(within(main()).getByRole('button', { name: 'Use a recovery code instead' }));
    expect(within(main()).queryByLabelText('Authentication code')).toBeNull();
    expect(within(main()).getByText('Recovery code').tagName).toBe('LABEL');
    expect(
      within(main()).getByRole('button', { name: 'Use an authentication code instead' }),
    ).toBeTruthy();
    fill('Recovery code', RECOVERY);
    verify();
    await waitFor(() => {
      expect(window.location.pathname).toBe('/search');
    });
    const challenge = calls.find((call) => call.key === 'POST /two-factor-challenge');
    expect(sentBody(challenge)).toEqual({ recovery_code: RECOVERY });
    expect(domHolds(RECOVERY)).toBe(false);
  });

  it('switches back to the authentication code', async () => {
    await reachChallenge({});
    fireEvent.click(within(main()).getByRole('button', { name: 'Use a recovery code instead' }));
    fireEvent.click(
      within(main()).getByRole('button', { name: 'Use an authentication code instead' }),
    );
    expect(within(main()).getByLabelText('Authentication code')).toBeTruthy();
    expect(within(main()).queryByLabelText('Recovery code')).toBeNull();
  });

  it('shows a 422 under the code field, names the context and clears the field', async () => {
    const calls = await reachChallenge({
      'POST /two-factor-challenge': problemResponse(
        accountValidationProblem('/two-factor-challenge', { code: [WRONG_CODE] }),
      ),
    });
    fill('Authentication code', CODE);
    verify();
    const alert = await screen.findByRole('alert');
    expect(alert.textContent).toContain('Could not sign in: check the fields below.');
    const code = within(main()).getByLabelText<HTMLInputElement>('Authentication code');
    expect(code.getAttribute('aria-invalid')).toBe('true');
    const message = document.getElementById(code.getAttribute('aria-describedby') ?? '');
    expect(message?.textContent).toBe(WRONG_CODE);
    expect(code.value).toBe('');
    expect(domHolds(CODE)).toBe(false);
    expect(window.location.pathname).toBe('/sign-in');
    expect(calls.filter((call) => call.key === 'GET /v1/me')).toHaveLength(1);
  });

  it('shows a 422 under the recovery code field and clears it', async () => {
    await reachChallenge({
      'POST /two-factor-challenge': problemResponse(
        accountValidationProblem('/two-factor-challenge', { recovery_code: [WRONG_RECOVERY] }),
      ),
    });
    fireEvent.click(within(main()).getByRole('button', { name: 'Use a recovery code instead' }));
    fill('Recovery code', RECOVERY);
    verify();
    await screen.findByRole('alert');
    const field = within(main()).getByLabelText<HTMLInputElement>('Recovery code');
    expect(field.getAttribute('aria-invalid')).toBe('true');
    expect(document.getElementById(field.getAttribute('aria-describedby') ?? '')?.textContent).toBe(
      WRONG_RECOVERY,
    );
    expect(field.value).toBe('');
    expect(domHolds(RECOVERY)).toBe(false);
  });

  it('shows the 429 line and clears the code', async () => {
    await reachChallenge({
      'POST /two-factor-challenge': problemResponse(
        tooManyRequestsProblem('/two-factor-challenge'),
      ),
    });
    fill('Authentication code', CODE);
    verify();
    const alert = await screen.findByRole('alert');
    expect(alert.textContent).toContain(
      'Could not sign in: Too Many Requests. Wait a minute and try again.',
    );
    expect(within(main()).getByLabelText<HTMLInputElement>('Authentication code').value).toBe('');
    expect(domHolds(CODE)).toBe(false);
  });

  it('keeps every challenge state dark-ready, 44 px and free of forbidden words', async () => {
    vi.useFakeTimers({ toFake: ['Date'] });
    vi.setSystemTime(new Date('2031-02-03T04:05:06Z'));
    await reachChallenge({
      'POST /two-factor-challenge': [
        problemResponse(accountValidationProblem('/two-factor-challenge', { code: [WRONG_CODE] })),
        problemResponse(tooManyRequestsProblem('/two-factor-challenge')),
      ],
    });
    const check = (): void => {
      expect(lightOnlyClasses(document.body)).toEqual([]);
      expect(shortControls(main())).toEqual([]);
      for (const pattern of FORBIDDEN_WORDS) expect(main().textContent).not.toMatch(pattern);
      expect(main().textContent).not.toContain('2031');
    };
    check();
    fill('Authentication code', CODE);
    verify();
    await screen.findByText(/check the fields below/);
    check();
    fireEvent.click(within(main()).getByRole('button', { name: 'Use a recovery code instead' }));
    check();
    fill('Recovery code', RECOVERY);
    verify();
    await screen.findByText(/Too Many Requests/);
    check();
    expect(domHolds(RECOVERY)).toBe(false);
  });
});

describe('the Just looking? panel on sign-in (D2-i2-T06, D0-F09)', () => {
  const DEMO_SENTENCE =
    'Open the read-only demo account to see a watchlist and a portfolio without signing up.';

  function demoPanel(): HTMLElement | null {
    return within(main()).queryByRole('heading', { level: 2, name: 'Just looking?' });
  }

  it('follows the form when signed out: its heading, the sentence and the demo button', async () => {
    stubAccountFetch({ 'GET /v1/me': problemResponse(unauthenticatedProblem) });
    visit('/sign-in');
    render(<App />);
    // Absent while the session loads.
    expect(screen.getByRole('status').textContent).toBe('Loading your account…');
    expect(demoPanel()).toBeNull();
    await signedOutForm();
    expect(demoPanel()).not.toBeNull();
    const heading = within(main()).getByRole('heading', { level: 2, name: 'Just looking?' });
    const submitButton = within(main()).getByRole('button', { name: 'Sign in' });
    expect(
      submitButton.compareDocumentPosition(heading) & Node.DOCUMENT_POSITION_FOLLOWING,
    ).toBeTruthy();
    expect(within(main()).getByText(DEMO_SENTENCE)).toBeTruthy();
    const demo = within(main()).getByRole('button', { name: 'Try the read-only demo' });
    expect(heading.compareDocumentPosition(demo) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
  });

  it('is absent when signed in', async () => {
    stubAccountFetch({ 'GET /v1/me': jsonResponse(signedInMe) });
    visit('/sign-in');
    render(<App />);
    await screen.findByText('You are signed in as collector@example.com.');
    expect(demoPanel()).toBeNull();
    expect(within(main()).queryByText(DEMO_SENTENCE)).toBeNull();
  });

  it('is absent during the two-factor step', async () => {
    stubAccountFetch({
      'GET /v1/me': problemResponse(unauthenticatedProblem),
      'POST /login': jsonResponse({ two_factor: true }),
    });
    visit('/sign-in');
    render(<App />);
    await signedOutForm();
    fill('Email', 'collector@example.com');
    fill('Password', TYPED);
    submit();
    await within(main()).findByRole('heading', { level: 2, name: 'Two-factor authentication' });
    expect(demoPanel()).toBeNull();
    expect(within(main()).queryByRole('button', { name: 'Try the read-only demo' })).toBeNull();
  });

  it('has no Show toggle', async () => {
    stubAccountFetch({ 'GET /v1/me': problemResponse(unauthenticatedProblem) });
    visit('/sign-in');
    render(<App />);
    await signedOutForm();
    expect(within(main()).queryByRole('button', { name: /^Show/ })).toBeNull();
  });
});
