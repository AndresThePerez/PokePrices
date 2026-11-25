// The browser checks of the account forms: before a form is sent, an empty required field, a new
// password outside 12 to 72 characters, a confirmation that differs and an email address without
// its shape each put a message under their field (aria-describedby, aria-invalid), focus goes to
// the first such field, and no request leaves the page.
import { act, cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import App from '../App';
import {
  FORBIDDEN_WORDS,
  accountValidationProblem,
  clearXsrfCookie,
  emptyJsonResponse,
  jsonResponse,
  passwordRequiredResponse,
  problemResponse,
  signedInMe,
  stubAccountFetch,
  twoFactorQrAnswer,
  twoFactorSecretAnswer,
  unauthenticatedProblem,
  type AccountCall,
} from '../test/fixtures';

const GOOD = 'fake-password-0000-ok';
const SHORT = 'x'.repeat(11);
const LONG = 'x'.repeat(73);
const RESET_LINK = '/password/reset?token=fake0reset0value0000abcd&email=collector%40example.com';

const NO_EMAIL = 'Enter your email address.';
const BAD_EMAIL = 'Enter an email address like name@example.com.';
const NO_PASSWORD = 'Enter your password.';
const TOO_SHORT = 'Use at least 12 characters.';
const TOO_LONG = 'Use at most 72 characters.';
const NO_CONFIRMATION = 'Enter the password again.';
const MISMATCH = 'The two passwords do not match.';
const NO_CODE = 'Enter the authentication code.';

function visit(path: string): void {
  window.history.replaceState(null, '', path);
}

afterEach(() => {
  cleanup();
  clearXsrfCookie();
  visit('/');
});

function main(): HTMLElement {
  return screen.getByRole('main');
}

function field(label: string): HTMLInputElement {
  return within(main()).getByLabelText(label);
}

function fill(label: string, value: string): void {
  fireEvent.change(field(label), { target: { value } });
}

function press(name: string): void {
  fireEvent.click(within(main()).getByRole('button', { name }));
}

/** The message under a field, when the field is marked invalid and described by it; else null. */
function shown(input: HTMLElement): string | null {
  const errorId = `${input.id}-error`;
  const described = (input.getAttribute('aria-describedby') ?? '').split(/\s+/);
  if (input.getAttribute('aria-invalid') !== 'true' || !described.includes(errorId)) return null;
  return document.getElementById(errorId)?.textContent ?? null;
}

/** Every request the page sent, after the handlers settled. */
async function sent(calls: readonly AccountCall[]): Promise<string[]> {
  await act(() => new Promise((resolve) => setTimeout(resolve, 20)));
  return calls.map((call) => call.key);
}

function forbidden(): string[] {
  return FORBIDDEN_WORDS.filter((pattern) => pattern.test(main().textContent)).map(String);
}

describe('sign in checks itself in the browser', () => {
  async function open(): Promise<AccountCall[]> {
    const calls = stubAccountFetch({ 'GET /v1/me': problemResponse(unauthenticatedProblem) });
    visit('/sign-in');
    render(<App />);
    await within(main()).findByLabelText('Email');
    return calls;
  }

  it('names both empty fields, focuses Email and sends nothing', async () => {
    const calls = await open();
    press('Sign in');
    expect(shown(field('Email'))).toBe(NO_EMAIL);
    expect(shown(field('Password'))).toBe(NO_PASSWORD);
    expect(document.activeElement).toBe(field('Email'));
    expect(forbidden()).toEqual([]);
    expect(await sent(calls)).toEqual([]);
  });

  it('names an email address without its shape and puts no length rule on the password', async () => {
    const calls = await open();
    fill('Email', 'collector.example.com');
    fill('Password', SHORT);
    press('Sign in');
    expect(shown(field('Email'))).toBe(BAD_EMAIL);
    expect(shown(field('Password'))).toBeNull();
    expect(document.activeElement).toBe(field('Email'));
    expect(field('Password').value).toBe(SHORT);
    expect(await sent(calls)).toEqual([]);
  });

  it('focuses Password when only the password is missing', async () => {
    const calls = await open();
    fill('Email', 'collector@example.com');
    press('Sign in');
    expect(shown(field('Email'))).toBeNull();
    expect(shown(field('Password'))).toBe(NO_PASSWORD);
    expect(document.activeElement).toBe(field('Password'));
    expect(await sent(calls)).toEqual([]);
  });
});

describe('the two-factor step of sign in checks itself in the browser', () => {
  async function reach(): Promise<AccountCall[]> {
    const calls = stubAccountFetch({
      'GET /v1/me': problemResponse(unauthenticatedProblem),
      'POST /login': jsonResponse({ two_factor: true }),
    });
    visit('/sign-in');
    render(<App />);
    await within(main()).findByLabelText('Email');
    fill('Email', 'collector@example.com');
    fill('Password', GOOD);
    press('Sign in');
    await within(main()).findByLabelText('Authentication code');
    return calls;
  }

  it('names an empty authentication code, then an empty recovery code, and sends neither', async () => {
    const calls = await reach();
    press('Verify');
    expect(shown(field('Authentication code'))).toBe(NO_CODE);
    expect(document.activeElement).toBe(field('Authentication code'));
    press('Use a recovery code instead');
    expect(shown(field('Recovery code'))).toBeNull();
    press('Verify');
    expect(shown(field('Recovery code'))).toBe('Enter a recovery code.');
    expect(document.activeElement).toBe(field('Recovery code'));
    expect(forbidden()).toEqual([]);
    expect((await sent(calls)).filter((key) => key.includes('two-factor'))).toEqual([]);
  });
});

describe('sign up checks itself in the browser', () => {
  function open(): AccountCall[] {
    const calls = stubAccountFetch({});
    visit('/sign-up');
    render(<App />);
    return calls;
  }

  it('shows every empty field at once, focuses the invite code and sends nothing', async () => {
    const calls = open();
    press('Create account');
    expect(shown(field('Invite code'))).toBe('Enter your invite code.');
    expect(shown(field('Email'))).toBe(NO_EMAIL);
    expect(shown(field('Password'))).toBe('Enter a password.');
    expect(shown(field('Confirm password'))).toBe(NO_CONFIRMATION);
    expect(document.activeElement).toBe(field('Invite code'));
    expect(forbidden()).toEqual([]);
    expect(await sent(calls)).toEqual([]);
  });

  it('shows a malformed email, an 11-character password and a different confirmation together', async () => {
    const calls = open();
    fill('Invite code', 'fake-invite-0000-zz');
    fill('Email', 'newcomer example.com');
    fill('Password', SHORT);
    fill('Confirm password', `${SHORT}y`);
    press('Create account');
    expect(shown(field('Invite code'))).toBeNull();
    expect(shown(field('Email'))).toBe(BAD_EMAIL);
    expect(shown(field('Password'))).toBe(TOO_SHORT);
    expect(shown(field('Confirm password'))).toBe(MISMATCH);
    expect(document.activeElement).toBe(field('Email'));
    // The hint still describes the password, beside the message.
    const hint = within(main()).getByText('At least 12 characters.');
    expect((field('Password').getAttribute('aria-describedby') ?? '').split(/\s+/)).toContain(
      hint.id,
    );
    expect(forbidden()).toEqual([]);
    expect(await sent(calls)).toEqual([]);
  });

  it('refuses a 73-character password and takes 12 and 72', async () => {
    const calls = open();
    fill('Invite code', 'fake-invite-0000-zz');
    fill('Email', 'newcomer@example.com');
    fill('Password', LONG);
    fill('Confirm password', LONG);
    press('Create account');
    expect(shown(field('Password'))).toBe(TOO_LONG);
    expect(shown(field('Confirm password'))).toBeNull();
    expect(document.activeElement).toBe(field('Password'));
    expect(await sent(calls)).toEqual([]);
    for (const length of [12, 72]) {
      fill('Password', 'x'.repeat(length));
      fill('Confirm password', 'x'.repeat(length));
      press('Create account');
      expect(shown(field('Password'))).toBeNull();
    }
  });

  it('replaces its own messages with every field of one 422', async () => {
    const answers = {
      invite_code: ['The invite code is not valid.'],
      email: ['The email has already been taken.'],
      password: ['The password field must be at least 12 characters.'],
      password_confirmation: ['The password confirmation field is required.'],
    };
    const calls = stubAccountFetch({
      'POST /register': problemResponse(accountValidationProblem('/register', answers)),
    });
    visit('/sign-up');
    render(<App />);
    press('Create account');
    expect(shown(field('Email'))).toBe(NO_EMAIL);
    fill('Invite code', 'fake-invite-0000-zz');
    fill('Email', 'newcomer@example.com');
    fill('Password', GOOD);
    fill('Confirm password', GOOD);
    press('Create account');
    await screen.findByRole('alert');
    expect(shown(field('Invite code'))).toBe(answers.invite_code[0]);
    expect(shown(field('Email'))).toBe(answers.email[0]);
    expect(shown(field('Password'))).toBe(answers.password[0]);
    expect(shown(field('Confirm password'))).toBe(answers.password_confirmation[0]);
    expect(calls.filter((call) => call.key === 'POST /register')).toHaveLength(1);
  });
});

describe('forgot password checks itself in the browser', () => {
  it('names an empty and then a malformed email, focuses it and sends nothing', async () => {
    const calls = stubAccountFetch({});
    visit('/password/forgot');
    render(<App />);
    press('Send reset link');
    expect(shown(field('Email'))).toBe(NO_EMAIL);
    expect(document.activeElement).toBe(field('Email'));
    for (const typed of ['collector.example.com', '@example.com', 'the collector@example.com']) {
      fill('Email', typed);
      press('Send reset link');
      expect(shown(field('Email'))).toBe(BAD_EMAIL);
    }
    expect(forbidden()).toEqual([]);
    expect(await sent(calls)).toEqual([]);
  });
});

describe('reset password checks itself in the browser', () => {
  function open(): AccountCall[] {
    const calls = stubAccountFetch({});
    visit(RESET_LINK);
    render(<App />);
    return calls;
  }

  it('names both empty passwords at once and focuses the new one', async () => {
    const calls = open();
    press('Save password');
    expect(shown(field('New password'))).toBe('Enter a new password.');
    expect(shown(field('Confirm new password'))).toBe(NO_CONFIRMATION);
    expect(shown(field('Email'))).toBeNull();
    expect(document.activeElement).toBe(field('New password'));
    expect(forbidden()).toEqual([]);
    expect(await sent(calls)).toEqual([]);
  });

  it('refuses 11 and 73 characters and a different confirmation', async () => {
    const calls = open();
    for (const [typed, message] of [
      [SHORT, TOO_SHORT],
      [LONG, TOO_LONG],
    ] as const) {
      fill('New password', typed);
      fill('Confirm new password', typed);
      press('Save password');
      expect(shown(field('New password'))).toBe(message);
      expect(shown(field('Confirm new password'))).toBeNull();
    }
    fill('New password', GOOD);
    fill('Confirm new password', `${GOOD}-not`);
    press('Save password');
    expect(shown(field('New password'))).toBeNull();
    expect(shown(field('Confirm new password'))).toBe(MISMATCH);
    expect(document.activeElement).toBe(field('Confirm new password'));
    expect(await sent(calls)).toEqual([]);
  });
});

describe('the account page forms check themselves in the browser', () => {
  function twoFactor(): HTMLElement {
    return within(main()).getByRole('region', { name: 'Two-factor authentication' });
  }

  async function open(routes: Parameters<typeof stubAccountFetch>[0]): Promise<AccountCall[]> {
    const calls = stubAccountFetch({ 'GET /v1/me': jsonResponse(signedInMe), ...routes });
    visit('/account');
    render(<App />);
    await screen.findByText('Signed in as collector@example.com');
    return calls;
  }

  it('names an empty authentication code in two-factor setup and sends no confirmation', async () => {
    const calls = await open({
      'POST /user/two-factor-authentication': emptyJsonResponse(200),
      'GET /user/two-factor-qr-code': jsonResponse(twoFactorQrAnswer),
      'GET /user/two-factor-secret-key': jsonResponse(twoFactorSecretAnswer),
    });
    press('Turn on two-factor authentication');
    const code = await within(twoFactor()).findByLabelText('Authentication code');
    press('Confirm');
    expect(shown(code)).toBe(NO_CODE);
    expect(document.activeElement).toBe(code);
    expect(forbidden()).toEqual([]);
    expect((await sent(calls)).filter((key) => key.includes('confirmed'))).toEqual([]);
  });

  it('names an empty password in the confirm-password prompt and sends no confirmation', async () => {
    const calls = await open({
      'POST /user/two-factor-authentication': passwordRequiredResponse(),
    });
    press('Turn on two-factor authentication');
    const password = await within(twoFactor()).findByLabelText('Password');
    press('Confirm password');
    expect(shown(password)).toBe(NO_PASSWORD);
    expect(document.activeElement).toBe(password);
    expect(forbidden()).toEqual([]);
    expect((await sent(calls)).filter((key) => key.includes('confirm-password'))).toEqual([]);
  });

  it('names an empty password in the deletion form and sends no deletion', async () => {
    const calls = await open({});
    const deletion = within(main()).getByRole('region', { name: 'Delete account' });
    fireEvent.click(within(deletion).getByRole('checkbox'));
    const password = within(deletion).getByLabelText('Password');
    fireEvent.submit(password.closest('form') as HTMLFormElement);
    expect(shown(password)).toBe(NO_PASSWORD);
    expect(document.activeElement).toBe(password);
    expect(forbidden()).toEqual([]);
    expect((await sent(calls)).filter((key) => key.includes('deletion'))).toEqual([]);
    // Unticking drops the message with the field.
    fireEvent.click(within(deletion).getByRole('checkbox'));
    fireEvent.click(within(deletion).getByRole('checkbox'));
    expect(shown(within(deletion).getByLabelText('Password'))).toBeNull();
  });
});
