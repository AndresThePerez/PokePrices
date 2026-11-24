// The Change password panel of /account: current password, new password and its confirmation over
// PUT /user/password. The browser checks run first; the server's 422 messages sit under their
// fields; a success is one calm status line; no password outlives an answer; the read-only demo
// has no such panel.
import { act, cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import App from '../App';
import { changePassword } from '../api/client';
import {
  FORBIDDEN_WORDS,
  XSRF_HEADER,
  accountValidationProblem,
  clearXsrfCookie,
  demoMe,
  domHolds,
  emptyJsonResponse,
  jsonResponse,
  lightOnlyClasses,
  problemResponse,
  sentBody,
  setXsrfCookie,
  shortControls,
  signedInMe,
  stubAccountFetch,
  tooManyRequestsProblem,
  type AccountCall,
} from '../test/fixtures';

const CHANGE = 'PUT /user/password';
const CURRENT = 'fake-current-0000-ok';
const NEXT = 'fake-next-0000-ok-zz';
const CHANGED = 'Password changed. Your other sessions are signed out.';
const WRONG_CURRENT = 'The password is incorrect.';

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

function panel(): HTMLElement {
  return within(main()).getByRole('region', { name: 'Change password' });
}

function field(label: string): HTMLInputElement {
  return within(panel()).getByLabelText(label);
}

function fillAll(current = CURRENT, next = NEXT, confirmation = next): void {
  fireEvent.change(field('Current password'), { target: { value: current } });
  fireEvent.change(field('New password'), { target: { value: next } });
  fireEvent.change(field('Confirm new password'), { target: { value: confirmation } });
}

function submit(): void {
  fireEvent.click(within(panel()).getByRole('button', { name: 'Change password' }));
}

function shown(input: HTMLElement): string | null {
  const errorId = `${input.id}-error`;
  const described = (input.getAttribute('aria-describedby') ?? '').split(/\s+/);
  if (input.getAttribute('aria-invalid') !== 'true' || !described.includes(errorId)) return null;
  return document.getElementById(errorId)?.textContent ?? null;
}

function changes(calls: readonly AccountCall[]): AccountCall[] {
  return calls.filter((call) => call.key === CHANGE);
}

async function open(routes: Parameters<typeof stubAccountFetch>[0] = {}): Promise<AccountCall[]> {
  const calls = stubAccountFetch({ 'GET /v1/me': jsonResponse(signedInMe), ...routes });
  visit('/account');
  render(<App />);
  await screen.findByText('Signed in as collector@example.com');
  return calls;
}

describe('the changePassword client', () => {
  it('puts the three fields as JSON to /user/password with the account flags', async () => {
    setXsrfCookie();
    const calls = stubAccountFetch({ [CHANGE]: emptyJsonResponse(200) });
    const result = await changePassword({
      current_password: CURRENT,
      password: NEXT,
      password_confirmation: NEXT,
    });
    expect(result).toEqual({ ok: true, data: undefined });
    expect(calls.map((call) => call.key)).toEqual([CHANGE]);
    expect(sentBody(calls[0])).toEqual({
      current_password: CURRENT,
      password: NEXT,
      password_confirmation: NEXT,
    });
    expect(calls[0]?.init?.credentials).toBe('same-origin');
    expect(calls[0]?.init?.redirect).toBe('error');
    expect(calls[0]?.init?.headers).toMatchObject({
      Accept: 'application/json',
      'Content-Type': 'application/json',
      'X-XSRF-TOKEN': XSRF_HEADER,
    });
  });
});

describe('the Change password panel on /account', () => {
  it('shows three labelled password fields and its button, and asks for nothing', async () => {
    const calls = await open();
    expect(within(panel()).getByRole('heading', { level: 2 }).textContent).toBe('Change password');
    expect(field('Current password').type).toBe('password');
    expect(field('Current password').getAttribute('autocomplete')).toBe('current-password');
    expect(field('New password').type).toBe('password');
    expect(field('New password').getAttribute('autocomplete')).toBe('new-password');
    expect(field('Confirm new password').type).toBe('password');
    expect(field('Confirm new password').getAttribute('autocomplete')).toBe('new-password');
    const hint = within(panel()).getByText('12 to 72 characters.');
    expect(field('New password').getAttribute('aria-describedby')).toBe(hint.id);
    expect(within(panel()).getByRole('button', { name: 'Change password' })).toBeTruthy();
    expect(calls.map((call) => call.key)).toEqual(['GET /v1/me']);
  });

  it('changes the password, says so calmly and keeps no password on the page', async () => {
    const calls = await open({ [CHANGE]: emptyJsonResponse(200) });
    fillAll();
    submit();
    const status = await within(panel()).findByText(CHANGED);
    expect(status.getAttribute('role')).toBe('status');
    expect(sentBody(changes(calls)[0])).toEqual({
      current_password: CURRENT,
      password: NEXT,
      password_confirmation: NEXT,
    });
    expect(changes(calls)).toHaveLength(1);
    expect(field('Current password').value).toBe('');
    expect(field('New password').value).toBe('');
    expect(field('Confirm new password').value).toBe('');
    expect(domHolds(CURRENT)).toBe(false);
    expect(domHolds(NEXT)).toBe(false);
    expect(within(panel()).queryByRole('alert')).toBeNull();
    // Still signed in, on the same page.
    expect(screen.getByText('Signed in as collector@example.com')).toBeTruthy();
    expect(window.location.pathname).toBe('/account');
  });

  it('shows a wrong current password under Current password and clears the three fields', async () => {
    await open({
      [CHANGE]: problemResponse(
        accountValidationProblem('/user/password', { current_password: [WRONG_CURRENT] }),
      ),
    });
    fillAll();
    submit();
    const alert = await within(panel()).findByRole('alert');
    expect(alert.textContent).toContain('Could not change the password: check the fields below.');
    expect(shown(field('Current password'))).toBe(WRONG_CURRENT);
    expect(shown(field('New password'))).toBeNull();
    expect(shown(field('Confirm new password'))).toBeNull();
    expect(field('Current password').value).toBe('');
    expect(domHolds(CURRENT)).toBe(false);
    expect(domHolds(NEXT)).toBe(false);
    expect(within(panel()).queryByText(CHANGED)).toBeNull();
  });

  it("shows the server's new-password and confirmation errors under their own fields", async () => {
    const errors = {
      password: ['The password field must not be greater than 72 bytes.'],
      password_confirmation: ['The password confirmation field must be a string.'],
    };
    await open({
      [CHANGE]: problemResponse(accountValidationProblem('/user/password', errors)),
    });
    fillAll();
    submit();
    await within(panel()).findByRole('alert');
    expect(shown(field('Current password'))).toBeNull();
    expect(shown(field('New password'))).toBe(errors.password[0]);
    expect(shown(field('Confirm new password'))).toBe(errors.password_confirmation[0]);
  });

  it('says the 429 plainly and shows no success line', async () => {
    await open({ [CHANGE]: problemResponse(tooManyRequestsProblem('/user/password')) });
    fillAll();
    submit();
    const alert = await within(panel()).findByRole('alert');
    expect(alert.textContent).toContain(
      'Could not change the password: Too Many Requests. Wait a minute and try again.',
    );
    expect(within(panel()).queryByText(CHANGED)).toBeNull();
    expect(domHolds(CURRENT)).toBe(false);
  });

  it('is there for an account that can write and absent in the read-only demo', async () => {
    await open();
    expect(within(main()).getAllByRole('region', { name: 'Change password' })).toHaveLength(1);
    cleanup();
    stubAccountFetch({ 'GET /v1/me': jsonResponse(demoMe) });
    visit('/account');
    render(<App />);
    await screen.findByText('Signed in as demo@example.com');
    expect(within(main()).queryByRole('region', { name: 'Change password' })).toBeNull();
    expect(within(main()).queryByRole('heading', { name: 'Change password' })).toBeNull();
    expect(within(main()).queryByLabelText('Current password')).toBeNull();
    expect(within(main()).queryByRole('button', { name: 'Change password' })).toBeNull();
  });
});

describe('the Change password panel checks itself in the browser', () => {
  async function settled(calls: readonly AccountCall[]): Promise<AccountCall[]> {
    await act(() => new Promise((resolve) => setTimeout(resolve, 20)));
    return changes(calls);
  }

  it('names the three empty fields at once, focuses the first and sends nothing', async () => {
    const calls = await open({ [CHANGE]: emptyJsonResponse(200) });
    submit();
    expect(shown(field('Current password'))).toBe('Enter your current password.');
    expect(shown(field('New password'))).toBe('Enter a new password.');
    expect(shown(field('Confirm new password'))).toBe('Enter the password again.');
    expect(document.activeElement).toBe(field('Current password'));
    expect(await settled(calls)).toEqual([]);
  });

  it('refuses 11 and 73 characters and a different confirmation, and keeps what was typed', async () => {
    const calls = await open({ [CHANGE]: emptyJsonResponse(200) });
    for (const [typed, message] of [
      ['x'.repeat(11), 'Use at least 12 characters.'],
      ['x'.repeat(73), 'Use at most 72 characters.'],
    ] as const) {
      fillAll(CURRENT, typed);
      submit();
      expect(shown(field('New password'))).toBe(message);
      expect(shown(field('Current password'))).toBeNull();
      expect(document.activeElement).toBe(field('New password'));
      expect(field('New password').value).toBe(typed);
    }
    fillAll(CURRENT, NEXT, `${NEXT}-not`);
    submit();
    expect(shown(field('New password'))).toBeNull();
    expect(shown(field('Confirm new password'))).toBe('The two passwords do not match.');
    expect(document.activeElement).toBe(field('Confirm new password'));
    expect(await settled(calls)).toEqual([]);
  });

  it('drops a success line when the next try fails its checks', async () => {
    const calls = await open({ [CHANGE]: emptyJsonResponse(200) });
    fillAll();
    submit();
    await within(panel()).findByText(CHANGED);
    submit();
    expect(within(panel()).queryByText(CHANGED)).toBeNull();
    expect(shown(field('Current password'))).toBe('Enter your current password.');
    expect(await settled(calls)).toHaveLength(1);
  });
});

describe('the Change password panel in each state', () => {
  it('is dark-ready, 44 px and free of forbidden words', async () => {
    await open({
      [CHANGE]: [
        problemResponse(
          accountValidationProblem('/user/password', { current_password: [WRONG_CURRENT] }),
        ),
        problemResponse(tooManyRequestsProblem('/user/password')),
        emptyJsonResponse(200),
      ],
    });
    const clean = (): string[] => [
      ...lightOnlyClasses(panel()),
      ...shortControls(panel()),
      ...FORBIDDEN_WORDS.filter((pattern) => pattern.test(main().textContent)).map(String),
    ];
    expect(clean()).toEqual([]);
    submit();
    expect(shown(field('Current password'))).toBe('Enter your current password.');
    expect(clean()).toEqual([]);
    for (const expected of [/check the fields below/, /Too Many Requests/, CHANGED]) {
      fillAll();
      submit();
      await within(panel()).findByText(expected);
      expect(clean()).toEqual([]);
    }
  });
});
