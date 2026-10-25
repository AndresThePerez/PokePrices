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
  rawResponse,
  sentBody,
  shortControls,
  stubAccountFetch,
  tooManyRequestsProblem,
  unverifiedMe,
} from '../test/fixtures';

const TYPED = 'fake-password-0000-ok';
const INVITE = 'fake-invite-0000-zz';
const INVITE_ERROR = 'The invite code is not valid.';

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

function fillAll(): void {
  fireEvent.change(field('Invite code'), { target: { value: INVITE } });
  fireEvent.change(field('Email'), { target: { value: 'newcomer@example.com' } });
  fireEvent.change(field('Password'), { target: { value: TYPED } });
  fireEvent.change(field('Confirm password'), { target: { value: TYPED } });
}

function submit(): void {
  fireEvent.click(within(main()).getByRole('button', { name: 'Create account' }));
}

// Exactly the field's error node (D2-i2-T06: the invite code now has a hint too).
function errorOf(input: HTMLElement): string | null {
  return document.getElementById(`${input.id}-error`)?.textContent ?? null;
}

describe('the sign-up page (T03 criterion 3, D10)', () => {
  it('shows the exact heading, intro, labels, autocomplete, hint and button', () => {
    const calls = stubAccountFetch({});
    visit('/sign-up');
    render(<App />);
    expect(screen.getByRole('heading', { level: 1 }).textContent).toBe('Create an account');
    expect(within(main()).getByText('PokéPrices accounts are invite-only.')).toBeTruthy();
    expect(field('Invite code').getAttribute('autocomplete')).toBe('off');
    expect(field('Email').getAttribute('autocomplete')).toBe('email');
    expect(field('Email').getAttribute('type')).toBe('email');
    expect(field('Password').getAttribute('autocomplete')).toBe('new-password');
    expect(field('Password').getAttribute('type')).toBe('password');
    expect(field('Confirm password').getAttribute('autocomplete')).toBe('new-password');
    expect(field('Confirm password').getAttribute('type')).toBe('password');
    for (const label of ['Invite code', 'Email', 'Password', 'Confirm password']) {
      expect(within(main()).getByText(label).tagName).toBe('LABEL');
    }
    const hint = within(main()).getByText('At least 12 characters.');
    expect((field('Password').getAttribute('aria-describedby') ?? '').split(/\s+/)).toContain(
      hint.id,
    );
    expect(within(main()).getByRole('button', { name: 'Create account' })).toBeTruthy();
    // The page needs no session to show its form.
    expect(calls).toHaveLength(0);
  });

  it('creates the account and lands on /account with its status', async () => {
    const calls = stubAccountFetch({
      'POST /register': rawResponse(201, '""', 'application/json'),
      'GET /v1/me': jsonResponse(unverifiedMe),
    });
    visit('/sign-up');
    render(<App />);
    fillAll();
    submit();
    await waitFor(() => {
      expect(window.location.pathname).toBe('/account');
    });
    expect(
      await screen.findByText('Account created. Check your email for the verification link.'),
    ).toBeTruthy();
    expect(await screen.findByText('Signed in as newcomer@example.com')).toBeTruthy();
    expect(screen.getByText('Email not verified')).toBeTruthy();
    expect(sentBody(calls.find((call) => call.key === 'POST /register'))).toEqual({
      invite_code: INVITE,
      email: 'newcomer@example.com',
      password: TYPED,
      password_confirmation: TYPED,
    });
    expect(domHolds(TYPED)).toBe(false);
    expect(domHolds(INVITE)).toBe(false);
  });

  it('shows a wrong invite code under its field and keeps no secret', async () => {
    stubAccountFetch({
      'POST /register': problemResponse(
        accountValidationProblem('/register', { invite_code: [INVITE_ERROR] }),
      ),
    });
    visit('/sign-up');
    render(<App />);
    fillAll();
    submit();
    const alert = await screen.findByRole('alert');
    expect(alert.textContent).toContain('Could not create the account: check the fields below.');
    expect(field('Invite code').getAttribute('aria-invalid')).toBe('true');
    expect(errorOf(field('Invite code'))).toBe(INVITE_ERROR);
    expect(field('Email').getAttribute('aria-invalid')).toBeNull();
    expect(field('Email').value).toBe('newcomer@example.com');
    expect(field('Password').value).toBe('');
    expect(field('Confirm password').value).toBe('');
    expect(domHolds(TYPED)).toBe(false);
    expect(domHolds(INVITE)).toBe(false);
    expect(window.location.pathname).toBe('/sign-up');
  });

  it('shows email and password errors under their own fields', async () => {
    stubAccountFetch({
      'POST /register': problemResponse(
        accountValidationProblem('/register', {
          email: ['The email has already been taken.'],
          password: ['The password field must be at least 12 characters.'],
        }),
      ),
    });
    visit('/sign-up');
    render(<App />);
    fillAll();
    submit();
    await screen.findByRole('alert');
    expect(errorOf(field('Email'))).toBe('The email has already been taken.');
    expect(errorOf(field('Password'))).toBe('The password field must be at least 12 characters.');
    expect(field('Password').getAttribute('aria-invalid')).toBe('true');
    expect(field('Invite code').getAttribute('aria-invalid')).toBeNull();
  });

  it('shows the 429 line', async () => {
    stubAccountFetch({ 'POST /register': problemResponse(tooManyRequestsProblem('/register')) });
    visit('/sign-up');
    render(<App />);
    fillAll();
    submit();
    const alert = await screen.findByRole('alert');
    expect(alert.textContent).toContain(
      'Could not create the account: Too Many Requests. Wait a minute and try again.',
    );
  });

  it('is dark-ready, 44 px and free of forbidden words in each state', async () => {
    vi.useFakeTimers({ toFake: ['Date'] });
    vi.setSystemTime(new Date('2031-02-03T04:05:06Z'));
    stubAccountFetch({
      'POST /register': [
        problemResponse(accountValidationProblem('/register', { invite_code: [INVITE_ERROR] })),
        problemResponse(tooManyRequestsProblem('/register')),
      ],
    });
    visit('/sign-up');
    const { container } = render(<App />);
    const check = (): void => {
      expect(lightOnlyClasses(container)).toEqual([]);
      expect(shortControls(main())).toEqual([]);
      for (const pattern of FORBIDDEN_WORDS) expect(main().textContent).not.toMatch(pattern);
      expect(main().textContent).not.toContain('2031');
    };
    check();
    for (const expected of [/check the fields below/, /Too Many Requests/]) {
      fillAll();
      submit();
      await screen.findByText(expected);
      check();
    }
  });
});

describe('the sign-up panel (D2-i2-T06, D0-F09)', () => {
  const LABELS = ['Invite code', 'Email', 'Password', 'Confirm password'] as const;
  const DEMO_SENTENCE =
    'Open the read-only demo account to see a watchlist and a portfolio without signing up.';

  it('marks the four fields required outside their labels, with aria-required', () => {
    stubAccountFetch({});
    visit('/sign-up');
    render(<App />);
    for (const label of LABELS) {
      const input = field(label);
      expect(input.getAttribute('aria-required')).toBe('true');
      const labelNode = within(main()).getByText(label);
      expect(labelNode.tagName).toBe('LABEL');
      expect(labelNode.textContent).toBe(label);
      const mark = labelNode.nextElementSibling;
      expect(mark?.tagName).toBe('SPAN');
      expect(mark?.textContent).toBe('(required)');
      expect(mark?.closest('label')).toBeNull();
    }
  });

  it('wires the invite hint to its field and adds nothing else to the descriptions', () => {
    stubAccountFetch({});
    visit('/sign-up');
    render(<App />);
    const invite = field('Invite code');
    const hint = within(main()).getByText('The code from your invitation.');
    expect(invite.getAttribute('aria-describedby')).toBe(hint.id);
    expect(field('Email').hasAttribute('aria-describedby')).toBe(false);
    expect(field('Confirm password').hasAttribute('aria-describedby')).toBe(false);
  });

  it('shows and hides each password with its own named, pressed toggle', () => {
    stubAccountFetch({});
    visit('/sign-up');
    render(<App />);
    for (const [label, name] of [
      ['Password', 'Show password'],
      ['Confirm password', 'Show password confirmation'],
    ] as const) {
      const toggle = within(main()).getByRole('button', { name });
      expect(toggle.getAttribute('type')).toBe('button');
      expect(toggle.textContent).toBe('Show');
      expect(toggle.getAttribute('aria-pressed')).toBe('false');
      expect(field(label).getAttribute('type')).toBe('password');
      fireEvent.click(toggle);
      expect(field(label).getAttribute('type')).toBe('text');
      expect(within(main()).getByRole('button', { name }).getAttribute('aria-pressed')).toBe(
        'true',
      );
      fireEvent.click(toggle);
      expect(field(label).getAttribute('type')).toBe('password');
      expect(toggle.getAttribute('aria-pressed')).toBe('false');
    }
    // One toggle does not reveal the other field.
    fireEvent.click(within(main()).getByRole('button', { name: 'Show password' }));
    expect(field('Confirm password').getAttribute('type')).toBe('password');
  });

  it('hides both passwords again after every submit, whatever the answer', async () => {
    stubAccountFetch({
      'POST /register': [
        problemResponse(accountValidationProblem('/register', { invite_code: [INVITE_ERROR] })),
        problemResponse(tooManyRequestsProblem('/register')),
      ],
    });
    visit('/sign-up');
    render(<App />);
    for (const expected of [/check the fields below/, /Too Many Requests/]) {
      fillAll();
      fireEvent.click(within(main()).getByRole('button', { name: 'Show password' }));
      fireEvent.click(within(main()).getByRole('button', { name: 'Show password confirmation' }));
      expect(field('Password').getAttribute('type')).toBe('text');
      submit();
      await screen.findByText(expected);
      expect(main().textContent).not.toContain('Error:');
      expect(field('Password').getAttribute('type')).toBe('password');
      expect(field('Confirm password').getAttribute('type')).toBe('password');
      for (const name of ['Show password', 'Show password confirmation']) {
        expect(within(main()).getByRole('button', { name }).getAttribute('aria-pressed')).toBe(
          'false',
        );
      }
    }
  });

  it('offers the way back to sign in and the demo under the submit, with no request', () => {
    const calls = stubAccountFetch({});
    visit('/sign-up');
    render(<App />);
    const signIn = within(main()).getByRole('link', { name: 'Sign in' });
    expect(signIn.textContent).toBe('Sign in');
    expect(signIn.getAttribute('href')).toBe('/sign-in');
    expect(signIn.closest('p')?.textContent).toBe('Already have an account? Sign in');
    const submitButton = within(main()).getByRole('button', { name: 'Create account' });
    expect(
      submitButton.compareDocumentPosition(signIn) & Node.DOCUMENT_POSITION_FOLLOWING,
    ).toBeTruthy();
    const heading = within(main()).getByRole('heading', { level: 2, name: 'Just looking?' });
    const demo = within(main()).getByRole<HTMLButtonElement>('button', {
      name: 'Try the read-only demo',
    });
    expect(demo.type).toBe('button');
    expect(demo.closest('form')).toBeNull();
    expect(signIn.compareDocumentPosition(heading) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
    expect(within(main()).getByText(DEMO_SENTENCE)).toBeTruthy();
    expect(calls).toHaveLength(0);
  });
});
