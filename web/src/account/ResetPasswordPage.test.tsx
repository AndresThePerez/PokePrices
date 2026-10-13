import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import App from '../App';
import {
  FORBIDDEN_WORDS,
  accountValidationProblem,
  clearXsrfCookie,
  domHolds,
  jsonResponse,
  lightOnlyClasses,
  problemResponse,
  sentBody,
  shortControls,
  stubAccountFetch,
  unauthenticatedProblem,
} from '../test/fixtures';

const TYPED = 'fake-password-0000-ok';
const RESET = 'fake0reset0value0000abcd';
const EMAIL = 'collector+cards@example.com';
const LINK = `/password/reset?token=${RESET}&email=${encodeURIComponent(EMAIL)}`;

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

function field(label: string): HTMLInputElement {
  return within(main()).getByLabelText(label);
}

function save(): void {
  fireEvent.change(field('New password'), { target: { value: TYPED } });
  fireEvent.change(field('Confirm new password'), { target: { value: TYPED } });
  fireEvent.click(within(main()).getByRole('button', { name: 'Save password' }));
}

function errorOf(input: HTMLElement): string | undefined {
  return document.getElementById(input.getAttribute('aria-describedby') ?? '')?.textContent;
}

describe('the reset-password page (T03 criterion 3, D10)', () => {
  it('shows the form, keeps the link in state and clears it from the address bar on mount', () => {
    const calls = stubAccountFetch({});
    visit(LINK);
    render(<App />);
    expect(screen.getByRole('heading', { level: 1 }).textContent).toBe('Choose a new password');
    expect(window.location.pathname).toBe('/password/reset');
    expect(window.location.search).toBe('');
    expect(window.location.href).not.toContain(RESET);
    expect(field('Email').value).toBe(EMAIL);
    expect(field('Email').readOnly).toBe(true);
    expect(field('New password').getAttribute('autocomplete')).toBe('new-password');
    expect(field('New password').getAttribute('type')).toBe('password');
    expect(field('Confirm new password').getAttribute('autocomplete')).toBe('new-password');
    expect(field('Confirm new password').getAttribute('type')).toBe('password');
    for (const label of ['Email', 'New password', 'Confirm new password']) {
      expect(within(main()).getByText(label).tagName).toBe('LABEL');
    }
    expect(domHolds(RESET)).toBe(false);
    expect(calls).toHaveLength(0);
  });

  it('saves the password and lands on /sign-in with its status', async () => {
    const calls = stubAccountFetch({
      'POST /reset-password': jsonResponse({ message: 'Your password has been reset.' }),
      'GET /v1/me': problemResponse(unauthenticatedProblem),
    });
    visit(LINK);
    render(<App />);
    save();
    await waitFor(() => {
      expect(window.location.pathname).toBe('/sign-in');
    });
    expect(await screen.findByText('Password saved. Sign in with the new password.')).toBeTruthy();
    expect(sentBody(calls.find((call) => call.key === 'POST /reset-password'))).toEqual({
      token: RESET,
      email: EMAIL,
      password: TYPED,
      password_confirmation: TYPED,
    });
    expect(domHolds(TYPED)).toBe(false);
    expect(domHolds(RESET)).toBe(false);
  });

  it('shows a 422 under New password and clears both password fields', async () => {
    stubAccountFetch({
      'POST /reset-password': problemResponse(
        accountValidationProblem('/reset-password', {
          password: ['The password field confirmation does not match.'],
        }),
      ),
    });
    visit(LINK);
    render(<App />);
    save();
    const alert = await screen.findByRole('alert');
    expect(alert.textContent).toContain('Could not save the password: check the fields below.');
    expect(field('New password').getAttribute('aria-invalid')).toBe('true');
    expect(errorOf(field('New password'))).toBe('The password field confirmation does not match.');
    expect(field('New password').value).toBe('');
    expect(field('Confirm new password').value).toBe('');
    expect(domHolds(TYPED)).toBe(false);
    expect(domHolds(RESET)).toBe(false);
    expect(window.location.pathname).toBe('/password/reset');
  });

  it('shows a bad token (an email error) under Email', async () => {
    stubAccountFetch({
      'POST /reset-password': problemResponse(
        accountValidationProblem('/reset-password', {
          email: ['This password reset token is invalid.'],
        }),
      ),
    });
    visit(LINK);
    render(<App />);
    save();
    await screen.findByRole('alert');
    expect(field('Email').getAttribute('aria-invalid')).toBe('true');
    expect(errorOf(field('Email'))).toBe('This password reset token is invalid.');
  });

  it.each([
    '/password/reset',
    `/password/reset?token=${RESET}`,
    `/password/reset?email=${encodeURIComponent(EMAIL)}`,
    `/password/reset?token=&email=${encodeURIComponent(EMAIL)}`,
  ])('says %s is incomplete and links to a new one', (path) => {
    const calls = stubAccountFetch({});
    visit(path);
    render(<App />);
    expect(
      within(main()).getByText('This reset link is incomplete. Ask for a new one.'),
    ).toBeTruthy();
    const link = within(main()).getByRole('link', { name: 'Ask for a new link' });
    expect(link.getAttribute('href')).toBe('/password/forgot');
    expect(within(main()).queryByRole('button')).toBeNull();
    expect(domHolds(RESET)).toBe(false);
    expect(calls).toHaveLength(0);
  });

  it('is dark-ready, 44 px and free of forbidden words in each state', async () => {
    vi.useFakeTimers({ toFake: ['Date'] });
    vi.setSystemTime(new Date('2031-02-03T04:05:06Z'));
    stubAccountFetch({
      'POST /reset-password': problemResponse(
        accountValidationProblem('/reset-password', { password: ['Too short.'] }),
      ),
    });
    const check = (container: HTMLElement): void => {
      expect(lightOnlyClasses(container)).toEqual([]);
      expect(shortControls(main())).toEqual([]);
      for (const pattern of FORBIDDEN_WORDS) expect(main().textContent).not.toMatch(pattern);
      expect(main().textContent).not.toContain('2031');
    };
    visit('/password/reset');
    check(render(<App />).container);
    cleanup();
    visit(LINK);
    const { container } = render(<App />);
    check(container);
    save();
    await screen.findByRole('alert');
    check(container);
  });
});
