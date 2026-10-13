import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import App from '../App';
import {
  FORBIDDEN_WORDS,
  clearXsrfCookie,
  domHolds,
  invalidSignatureProblem,
  jsonResponse,
  lightOnlyClasses,
  problemResponse,
  rawResponse,
  serverErrorProblem,
  shortControls,
  signedInMe,
  stubAccountFetch,
  unauthenticatedProblem,
  unverifiedMe,
} from '../test/fixtures';

const TYPED = 'fake-password-0000-ok';
const SIGNATURE = 'fake0signature0000beef';
const LINK = `/email/verify/7/0a1b2c?expires=1700000000&signature=${SIGNATURE}`;
const PAGE = `/account/verify-email?link=${encodeURIComponent(LINK)}`;
const INVALID =
  'This verification link is not valid or has expired. Send a new one from your account.';

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

describe('the verify-email page (T03 criterion 3, D10)', () => {
  it('verifies a signed-in account, refetches the session and links to the account', async () => {
    const calls = stubAccountFetch({
      'GET /v1/me': [jsonResponse(unverifiedMe), jsonResponse(signedInMe)],
      [`GET ${LINK}`]: rawResponse(204, null),
    });
    visit(PAGE);
    render(<App />);
    expect(screen.getByRole('heading', { level: 1 }).textContent).toBe('Verify your email');
    expect(screen.getByRole('status').textContent).toBe('Loading your account…');
    expect(await screen.findByText('Your email address is verified.')).toBeTruthy();
    const link = within(main()).getByRole('link', { name: 'Go to your account' });
    expect(link.getAttribute('href')).toBe('/account');
    await waitFor(() => {
      expect(calls.filter((call) => call.key === 'GET /v1/me')).toHaveLength(2);
    });
    expect(calls.filter((call) => call.key === `GET ${LINK}`)).toHaveLength(1);
    expect(screen.queryByRole('alert')).toBeNull();
    expect(domHolds(SIGNATURE)).toBe(false);
  });

  it.each([
    ['no link', '/account/verify-email'],
    ['an empty link', '/account/verify-email?link='],
    [
      'another path',
      `/account/verify-email?link=${encodeURIComponent('/user/two-factor-qr-code?x=1')}`,
    ],
    ['another origin', `/account/verify-email?link=${encodeURIComponent(`//evil.example${LINK}`)}`],
    ['no query', `/account/verify-email?link=${encodeURIComponent('/email/verify/7/0a1b2c')}`],
  ])('never fetches %s and says the link is not valid', (_name, path) => {
    const calls = stubAccountFetch({ 'GET /v1/me': jsonResponse(signedInMe) });
    visit(path);
    render(<App />);
    expect(within(main()).getByText(INVALID)).toBeTruthy();
    expect(calls.filter((call) => !call.key.startsWith('GET /v1/me'))).toEqual([]);
  });

  it('says a 403 link is not valid', async () => {
    stubAccountFetch({
      'GET /v1/me': jsonResponse(unverifiedMe),
      [`GET ${LINK}`]: problemResponse(invalidSignatureProblem),
    });
    visit(PAGE);
    render(<App />);
    expect(await screen.findByText(INVALID)).toBeTruthy();
    expect(screen.queryByText('Your email address is verified.')).toBeNull();
  });

  it('shows another failure as the problem block', async () => {
    stubAccountFetch({
      'GET /v1/me': jsonResponse(unverifiedMe),
      [`GET ${LINK}`]: problemResponse(serverErrorProblem('/email/verify/7/0a1b2c')),
    });
    visit(PAGE);
    render(<App />);
    const alert = await screen.findByRole('alert');
    expect(alert.textContent).toContain(
      'Could not verify the email address: Internal Server Error',
    );
    expect(alert.textContent).toContain('Request ID req-p4-server-0007');
  });

  it('asks a signed-out visitor to sign in, with a next that comes back here', async () => {
    const calls = stubAccountFetch({
      'GET /v1/me': [
        problemResponse(unauthenticatedProblem),
        jsonResponse(unverifiedMe),
        jsonResponse(signedInMe),
      ],
      'POST /login': jsonResponse({ two_factor: false }),
      [`GET ${LINK}`]: rawResponse(204, null),
    });
    visit(PAGE);
    render(<App />);
    expect(await screen.findByText('Sign in to verify your email address.')).toBeTruthy();
    const link = within(main()).getByRole('link', { name: 'Sign in' });
    expect(link.getAttribute('href')).toBe(`/sign-in?next=${encodeURIComponent(PAGE)}`);
    expect(calls.filter((call) => call.key === `GET ${LINK}`)).toHaveLength(0);
    // Following it signs in and comes back to verify.
    fireEvent.click(link);
    await screen.findByRole('button', { name: 'Sign in' });
    fireEvent.change(within(main()).getByLabelText('Email'), {
      target: { value: 'newcomer@example.com' },
    });
    fireEvent.change(within(main()).getByLabelText('Password'), { target: { value: TYPED } });
    fireEvent.click(within(main()).getByRole('button', { name: 'Sign in' }));
    expect(await screen.findByText('Your email address is verified.')).toBeTruthy();
    expect(window.location.pathname).toBe('/account/verify-email');
    expect(calls.filter((call) => call.key === `GET ${LINK}`)).toHaveLength(1);
    expect(domHolds(TYPED)).toBe(false);
  });

  it('treats a 401 from the link itself as signed out', async () => {
    stubAccountFetch({
      'GET /v1/me': jsonResponse(unverifiedMe),
      [`GET ${LINK}`]: problemResponse(unauthenticatedProblem),
    });
    visit(PAGE);
    render(<App />);
    expect(await screen.findByText('Sign in to verify your email address.')).toBeTruthy();
  });

  it('is dark-ready, 44 px and free of forbidden words in each state', async () => {
    vi.useFakeTimers({ toFake: ['Date'] });
    vi.setSystemTime(new Date('2031-02-03T04:05:06Z'));
    const states = [
      [{ [`GET ${LINK}`]: rawResponse(204, null) }, 'Your email address is verified.'],
      [{ [`GET ${LINK}`]: problemResponse(invalidSignatureProblem) }, INVALID],
      [{ [`GET ${LINK}`]: problemResponse(serverErrorProblem('/x')) }, /Could not verify/],
      [{ [`GET ${LINK}`]: problemResponse(unauthenticatedProblem) }, /Sign in to verify/],
    ] as const;
    for (const [routes, expected] of states) {
      stubAccountFetch({ 'GET /v1/me': jsonResponse(unverifiedMe), ...routes });
      visit(PAGE);
      const { container } = render(<App />);
      await screen.findByText(expected);
      expect(lightOnlyClasses(container)).toEqual([]);
      expect(shortControls(main())).toEqual([]);
      for (const pattern of FORBIDDEN_WORDS) expect(main().textContent).not.toMatch(pattern);
      expect(main().textContent).not.toContain('2031');
      cleanup();
    }
  });
});
