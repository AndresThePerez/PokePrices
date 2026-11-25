// Edge cases of the browser checks and of the Change password panel: one failing field among good
// ones, the Enter path, a second try after a correction, the server's 422 and 429 after a pass,
// passwords counted in characters at 11, 12 and 72, an address with spaces around it, the
// description ids a screen reader follows, and two submits in one tick.
import { act, cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import App from '../App';
import {
  accountValidationProblem,
  clearXsrfCookie,
  emptyJsonResponse,
  jsonResponse,
  problemResponse,
  sentBody,
  signedInMe,
  stubAccountFetch,
  tooManyRequestsProblem,
  unauthenticatedProblem,
  type AccountCall,
} from '../test/fixtures';

const GOOD = 'fake-password-0000-ok';
const CURRENT = 'fake-current-0000-ok';
const NEXT = 'fake-next-0000-ok-zz';
const RESET_TOKEN = 'fake0reset0value0000abcd';
const RESET_LINK = `/password/reset?token=${RESET_TOKEN}&email=collector%40example.com`;
const CHANGE = 'PUT /user/password';
const CHANGED = 'Password changed. Your other sessions are signed out.';
const NO_PASSWORD = 'Enter your password.';
const BAD_EMAIL = 'Enter an email address like name@example.com.';
const TOO_SHORT = 'Use at least 12 characters.';
const BYTES = 'The password must not be longer than 72 bytes.';
// One character outside the basic plane: two UTF-16 units, four bytes.
const ASTRAL = '\u{1F600}';

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

function field(label: string, scope: HTMLElement = main()): HTMLInputElement {
  return within(scope).getByLabelText(label);
}

function fill(label: string, value: string, scope: HTMLElement = main()): void {
  fireEvent.change(field(label, scope), { target: { value } });
}

function press(name: string, scope: HTMLElement = main()): void {
  fireEvent.click(within(scope).getByRole('button', { name }));
}

/** The form's own submit event, as Enter in one of its fields raises it. */
function enter(input: HTMLInputElement): void {
  const form = input.form;
  if (form === null) throw new Error('the field sits outside a form');
  fireEvent.submit(form);
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

/** The recorded calls once the handlers settled. */
async function sentCalls(calls: readonly AccountCall[]): Promise<readonly AccountCall[]> {
  await sent(calls);
  return calls;
}

function only(calls: readonly AccountCall[], key: string): AccountCall[] {
  return calls.filter((call) => call.key === key);
}

describe('sign in: one failing field, the Enter path and the server after a pass', () => {
  async function open(answer: Response): Promise<AccountCall[]> {
    const calls = stubAccountFetch({
      'GET /v1/me': problemResponse(unauthenticatedProblem),
      'POST /login': answer,
    });
    visit('/sign-in');
    render(<App />);
    await within(main()).findByLabelText('Email');
    return calls;
  }

  it('sends nothing on Enter with the password missing, then exactly one sign-in once it is typed', async () => {
    const refused = { email: ['These credentials do not match our records.'] };
    const calls = await open(problemResponse(accountValidationProblem('/login', refused)));
    fill('Email', 'collector@example.com');
    enter(field('Email'));
    expect(shown(field('Password'))).toBe(NO_PASSWORD);
    expect(shown(field('Email'))).toBeNull();
    expect(document.activeElement).toBe(field('Password'));
    expect(await sent(calls)).toEqual([]);
    fill('Password', GOOD);
    enter(field('Password'));
    // The stale browser message leaves with the corrected submit; the server's own lands.
    expect(shown(field('Password'))).toBeNull();
    expect(field('Password').hasAttribute('aria-invalid')).toBe(false);
    await within(main()).findByText(/Could not sign in: check the fields below\./);
    expect(shown(field('Email'))).toBe(refused.email[0]);
    expect(only(calls, 'POST /login')).toHaveLength(1);
    expect(sentBody(only(calls, 'POST /login')[0])).toEqual({
      email: 'collector@example.com',
      password: GOOD,
    });
  });

  it("drops the server's message when the next try fails its own check", async () => {
    // `name@` passes the loose browser check; the API refuses it.
    const refused = { email: ['The email field must be a valid email address.'] };
    const calls = await open(problemResponse(accountValidationProblem('/login', refused)));
    fill('Email', 'collector@');
    fill('Password', GOOD);
    press('Sign in');
    await within(main()).findByText(/Could not sign in: check the fields below\./);
    expect(shown(field('Email'))).toBe(refused.email[0]);
    // The password is dropped after the answer: a second press has none to send.
    expect(field('Password').value).toBe('');
    press('Sign in');
    expect(shown(field('Password'))).toBe(NO_PASSWORD);
    expect(shown(field('Email'))).toBeNull();
    expect(within(main()).queryByText(/Could not sign in/)).toBeNull();
    expect(document.activeElement).toBe(field('Password'));
    expect(only(await sentCalls(calls), 'POST /login')).toHaveLength(1);
  });

  it('takes an address pasted with spaces around it, as the email field hands it over', async () => {
    const refused = { email: ['These credentials do not match our records.'] };
    const calls = await open(problemResponse(accountValidationProblem('/login', refused)));
    fill('Email', '  collector@example.com  ');
    fill('Password', GOOD);
    press('Sign in');
    expect(shown(field('Email'))).not.toBe(BAD_EMAIL);
    await within(main()).findByText(/Could not sign in: check the fields below\./);
    expect(sentBody(only(calls, 'POST /login')[0])).toEqual({
      email: 'collector@example.com',
      password: GOOD,
    });
  });
});

describe('sign up and reset password: one failing field, then the corrected form is sent once', () => {
  it('marks only the email among good fields, keeps what was typed, then registers once', async () => {
    const refused = { invite_code: ['The invite code is not valid.'] };
    const calls = stubAccountFetch({
      'POST /register': problemResponse(accountValidationProblem('/register', refused)),
    });
    visit('/sign-up');
    render(<App />);
    fill('Invite code', 'fake-invite-0000-zz');
    fill('Email', 'newcomer.example.com');
    fill('Password', GOOD);
    fill('Confirm password', GOOD);
    enter(field('Email'));
    expect(shown(field('Email'))).toBe(BAD_EMAIL);
    expect(document.activeElement).toBe(field('Email'));
    for (const label of ['Invite code', 'Password', 'Confirm password']) {
      expect(field(label).hasAttribute('aria-invalid')).toBe(false);
      expect(shown(field(label))).toBeNull();
    }
    expect(field('Invite code').value).toBe('fake-invite-0000-zz');
    expect(field('Password').value).toBe(GOOD);
    expect(field('Confirm password').value).toBe(GOOD);
    expect(await sent(calls)).toEqual([]);
    fill('Email', 'newcomer@example.com');
    press('Create account');
    await within(main()).findByText(/Could not create the account: check the fields below\./);
    expect(shown(field('Email'))).toBeNull();
    expect(shown(field('Invite code'))).toBe(refused.invite_code[0]);
    expect(only(calls, 'POST /register')).toHaveLength(1);
    expect(sentBody(only(calls, 'POST /register')[0])).toEqual({
      invite_code: 'fake-invite-0000-zz',
      email: 'newcomer@example.com',
      password: GOOD,
      password_confirmation: GOOD,
    });
  });

  it("focuses the missing confirmation, not the good new password, then sends the link's token once", async () => {
    const refused = { email: ['This password reset token is invalid.'] };
    const calls = stubAccountFetch({
      'POST /reset-password': problemResponse(accountValidationProblem('/reset-password', refused)),
    });
    visit(RESET_LINK);
    render(<App />);
    fill('New password', GOOD);
    enter(field('New password'));
    expect(shown(field('New password'))).toBeNull();
    expect(shown(field('Confirm new password'))).toBe('Enter the password again.');
    expect(document.activeElement).toBe(field('Confirm new password'));
    expect(await sent(calls)).toEqual([]);
    fill('Confirm new password', GOOD);
    press('Save password');
    await within(main()).findByText(/Could not save the password: check the fields below\./);
    expect(shown(field('Confirm new password'))).toBeNull();
    expect(only(calls, 'POST /reset-password')).toHaveLength(1);
    expect(sentBody(only(calls, 'POST /reset-password')[0])).toEqual({
      token: RESET_TOKEN,
      email: 'collector@example.com',
      password: GOOD,
      password_confirmation: GOOD,
    });
  });
});

describe('the Change password panel at its edges', () => {
  function panel(): HTMLElement {
    return within(main()).getByRole('region', { name: 'Change password' });
  }

  function fillAll(next: string, confirmation: string = next): void {
    fill('Current password', CURRENT, panel());
    fill('New password', next, panel());
    fill('Confirm new password', confirmation, panel());
  }

  async function open(answer: Response | readonly Response[]): Promise<AccountCall[]> {
    const calls = stubAccountFetch({ 'GET /v1/me': jsonResponse(signedInMe), [CHANGE]: answer });
    visit('/account');
    render(<App />);
    await screen.findByText('Signed in as collector@example.com');
    return calls;
  }

  it('counts characters, not UTF-16 units: 11 wide characters are refused and 12 are sent as typed', async () => {
    const calls = await open(emptyJsonResponse(200));
    const eleven = ASTRAL.repeat(11);
    const twelve = ASTRAL.repeat(12);
    // Eleven characters, though twenty-two UTF-16 units: the API counts eleven too.
    expect(eleven.length).toBe(22);
    fillAll(eleven);
    press('Change password', panel());
    expect(shown(field('New password', panel()))).toBe(TOO_SHORT);
    expect(only(await sentCalls(calls), CHANGE)).toEqual([]);
    fillAll(twelve);
    press('Change password', panel());
    await within(panel()).findByText(CHANGED);
    expect(only(calls, CHANGE)).toHaveLength(1);
    expect(sentBody(only(calls, CHANGE)[0])).toEqual({
      current_password: CURRENT,
      password: twelve,
      password_confirmation: twelve,
    });
  });

  it("sends 72 two-byte characters and shows the API's 72-byte refusal under New password", async () => {
    const calls = await open(
      problemResponse(accountValidationProblem('/user/password', { password: [BYTES] })),
    );
    const wide = 'é'.repeat(72);
    fillAll(wide);
    press('Change password', panel());
    await within(panel()).findByText(/Could not change the password: check the fields below\./);
    expect(only(calls, CHANGE)).toHaveLength(1);
    expect(shown(field('New password', panel()))).toBe(BYTES);
    expect(shown(field('Current password', panel()))).toBeNull();
    expect(shown(field('Confirm new password', panel()))).toBeNull();
  });

  it('ties New password to its hint and its message, both on the page, and unmarks it once corrected', async () => {
    await open(emptyJsonResponse(200));
    fillAll('x'.repeat(11));
    press('Change password', panel());
    const input = field('New password', panel());
    const ids = (input.getAttribute('aria-describedby') ?? '').split(/\s+/);
    expect(ids).toHaveLength(2);
    expect(new Set(ids).size).toBe(2);
    expect(ids.map((id) => document.getElementById(id)?.textContent)).toEqual([
      '12 to 72 characters.',
      TOO_SHORT,
    ]);
    expect(ids.map((id) => document.querySelectorAll(`[id="${id}"]`).length)).toEqual([1, 1]);
    expect(input.getAttribute('aria-invalid')).toBe('true');
    fillAll(NEXT);
    press('Change password', panel());
    await within(panel()).findByText(CHANGED);
    const after = field('New password', panel());
    expect(after.hasAttribute('aria-invalid')).toBe(false);
    const left = (after.getAttribute('aria-describedby') ?? '').split(/\s+/);
    expect(left.map((id) => document.getElementById(id)?.textContent)).toEqual([
      '12 to 72 characters.',
    ]);
    expect(within(panel()).queryByText(TOO_SHORT)).toBeNull();
  });

  it('empties the fields after a 429 without marking them, then changes the password on the next try', async () => {
    const calls = await open([
      problemResponse(tooManyRequestsProblem('/user/password')),
      emptyJsonResponse(200),
    ]);
    fillAll(NEXT);
    press('Change password', panel());
    const alert = await within(panel()).findByRole('alert');
    expect(alert.textContent).toContain(
      'Could not change the password: Too Many Requests. Wait a minute and try again.',
    );
    for (const label of ['Current password', 'New password', 'Confirm new password']) {
      expect(field(label, panel()).value).toBe('');
      expect(field(label, panel()).hasAttribute('aria-invalid')).toBe(false);
    }
    fillAll(NEXT);
    press('Change password', panel());
    await within(panel()).findByText(CHANGED);
    expect(within(panel()).queryByRole('alert')).toBeNull();
    expect(only(calls, CHANGE)).toHaveLength(2);
  });

  it('sends one change for two submits in the same tick', async () => {
    const calls = await open(emptyJsonResponse(200));
    fillAll(NEXT);
    enter(field('New password', panel()));
    enter(field('New password', panel()));
    await waitFor(() => {
      expect(only(calls, CHANGE).length).toBeGreaterThan(0);
    });
    await within(panel()).findByText(CHANGED);
    expect(only(await sentCalls(calls), CHANGE)).toHaveLength(1);
  });
});
