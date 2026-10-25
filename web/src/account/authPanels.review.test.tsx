// Review tests for the sign-in / sign-up panels (D2-i2-T06): the Show toggles around a slow or
// failed submit, the toggle never submitting, the page-level two-factor state that now outlives
// the form, the demo path from sign-up, hostile input, and the other account routes' markup.
import type { QueryClient } from '@tanstack/react-query';
import { act, cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import App from '../App';
import type * as QueryModule from '../query';
import {
  accountValidationProblem,
  clearXsrfCookie,
  demoMe,
  domHolds,
  jsonResponse,
  problemResponse,
  rawResponse,
  sentBody,
  signedInMe,
  stubAccountFetch,
  unauthenticatedProblem,
  watchlistBody,
} from '../test/fixtures';
import { SESSION_SIGNAL_KEY } from './session';

const created = vi.hoisted(() => ({ clients: [] as QueryClient[] }));
vi.mock('../query', async (importOriginal) => {
  const actual = await importOriginal<typeof QueryModule>();
  return {
    ...actual,
    createQueryClient: (): QueryClient => {
      const client = actual.createQueryClient();
      created.clients.push(client);
      return client;
    },
  };
});

const TYPED = 'fake-password-0000-ok';
const INVITE = 'fake-invite-0000-zz';

function visit(path: string): void {
  window.history.replaceState(null, '', path);
}

afterEach(() => {
  cleanup();
  clearXsrfCookie();
  created.clients.length = 0;
  window.localStorage.clear();
  visit('/');
});

function main(): HTMLElement {
  return screen.getByRole('main');
}

function field(label: string): HTMLInputElement {
  return within(main()).getByLabelText(label);
}

function fillAll(overrides: Partial<Record<'invite' | 'email', string>> = {}): void {
  fireEvent.change(field('Invite code'), { target: { value: overrides.invite ?? INVITE } });
  fireEvent.change(field('Email'), {
    target: { value: overrides.email ?? 'newcomer@example.com' },
  });
  fireEvent.change(field('Password'), { target: { value: TYPED } });
  fireEvent.change(field('Confirm password'), { target: { value: TYPED } });
}

function create(): void {
  fireEvent.click(within(main()).getByRole('button', { name: 'Create account' }));
}

describe('the Show toggles of the sign-up panel (review, D2-i2-T06)', () => {
  it('hides a password revealed while the request is in flight once the answer is a failure', async () => {
    const calls = stubAccountFetch({
      'POST /register': problemResponse(
        accountValidationProblem('/register', { invite_code: ['The invite code is not valid.'] }),
      ),
    });
    // Hold the register answer until the test lets it go.
    let release: () => void = () => undefined;
    const gate = new Promise<void>((resolve) => {
      release = resolve;
    });
    const inner = globalThis.fetch;
    vi.stubGlobal('fetch', async (input: string | URL, init?: RequestInit) => {
      if (String(input) === '/register') await gate;
      return inner(input, init);
    });
    visit('/sign-up');
    render(<App />);
    fillAll();
    create();
    // The submit has hidden both. The user presses Show while the answer is still on its way.
    await waitFor(() => {
      expect(within(main()).getByRole('button', { name: 'Create account' })).toHaveProperty(
        'disabled',
        true,
      );
    });
    fireEvent.click(within(main()).getByRole('button', { name: 'Show password' }));
    fireEvent.click(within(main()).getByRole('button', { name: 'Show password confirmation' }));
    release();
    await screen.findByRole('alert');
    // R: "resets to hidden after every submit, whatever the answer".
    expect(field('Password').getAttribute('type')).toBe('password');
    expect(field('Confirm password').getAttribute('type')).toBe('password');
    expect(
      within(main()).getByRole('button', { name: 'Show password' }).getAttribute('aria-pressed'),
    ).toBe('false');
    expect(calls.filter((call) => call.key === 'POST /register')).toHaveLength(1);
  });

  it('never submits the form from a toggle, and sits between its input and the next control', () => {
    const calls = stubAccountFetch({});
    visit('/sign-up');
    render(<App />);
    fillAll();
    fireEvent.click(within(main()).getByRole('button', { name: 'Show password' }));
    fireEvent.click(within(main()).getByRole('button', { name: 'Show password confirmation' }));
    fireEvent.click(within(main()).getByRole('button', { name: 'Show password' }));
    expect(calls).toHaveLength(0);
    const form = main().querySelector('form');
    expect(form).not.toBeNull();
    const order = Array.from(form?.querySelectorAll('input, button') ?? []).map(
      (node) => node.getAttribute('name') ?? node.getAttribute('aria-label') ?? node.textContent,
    );
    expect(order).toEqual([
      'invite_code',
      'email',
      'password',
      'Show password',
      'password_confirmation',
      'Show password confirmation',
      'Create account',
    ]);
    // The typed values survive a toggle.
    expect(field('Password').value).toBe(TYPED);
    expect(field('Confirm password').value).toBe(TYPED);
  });

  it('keeps an emoji invite code and a 5000-character email intact and the layout hooks unchanged', async () => {
    const calls = stubAccountFetch({
      'POST /register': problemResponse(
        accountValidationProblem('/register', { email: ['The email field must be valid.'] }),
      ),
    });
    visit('/sign-up');
    render(<App />);
    const hostileEmail = `${'a'.repeat(4990)}@example.com`;
    const hostileInvite = '🎟️🎟️ invite 🎟️';
    fillAll({ invite: hostileInvite, email: hostileEmail });
    create();
    await screen.findByRole('alert');
    expect(sentBody(calls.find((call) => call.key === 'POST /register'))).toMatchObject({
      invite_code: hostileInvite,
      email: hostileEmail,
    });
    expect(field('Email').value).toBe(hostileEmail);
    for (const label of ['Invite code', 'Email', 'Password', 'Confirm password']) {
      expect(field(label).getAttribute('aria-required')).toBe('true');
    }
    const emailDescribers = (field('Email').getAttribute('aria-describedby') ?? '').split(/\s+/);
    expect(emailDescribers).toEqual([`${field('Email').id}-error`]);
    expect(within(main()).getAllByRole('button', { name: /^Show password/ })).toHaveLength(2);
    expect(domHolds(TYPED)).toBe(false);
  });
});

describe('the sign-in page keeps one two-factor state per visit (review, D2-i2-T06)', () => {
  it('returns to the password form, not a stale two-factor step, after the session came and went', async () => {
    stubAccountFetch({
      'GET /v1/me': [
        problemResponse(unauthenticatedProblem),
        jsonResponse(signedInMe),
        problemResponse(unauthenticatedProblem),
      ],
      'POST /login': jsonResponse({ two_factor: true }),
    });
    visit('/sign-in');
    render(<App />);
    fireEvent.change(await within(main()).findByLabelText('Email'), {
      target: { value: 'collector@example.com' },
    });
    fireEvent.change(within(main()).getByLabelText('Password'), { target: { value: TYPED } });
    fireEvent.click(within(main()).getByRole('button', { name: 'Sign in' }));
    await within(main()).findByRole('heading', { level: 2, name: 'Two-factor authentication' });
    const refetch = async (): Promise<void> => {
      await act(async () => {
        await created.clients[0]?.refetchQueries({ queryKey: ['me'] });
      });
    };
    // Another tab signs in, then out again.
    await refetch();
    await within(main()).findByText(/You are signed in as/);
    await refetch();
    await waitFor(() => {
      expect(within(main()).queryByText(/You are signed in as/)).toBeNull();
    });
    expect(
      within(main()).queryByRole('heading', { level: 2, name: 'Two-factor authentication' }),
    ).toBeNull();
    expect(within(main()).getByLabelText('Email')).toBeTruthy();
    expect(within(main()).getByRole('heading', { level: 2, name: 'Just looking?' })).toBeTruthy();
  });
});

describe('the Just looking? panel on sign-up (review, D2-i2-T06)', () => {
  it('opens the demo watchlist from /sign-up with the same calls as from /sign-in', async () => {
    const calls = stubAccountFetch({
      'GET /v1/me': [problemResponse(unauthenticatedProblem), jsonResponse(demoMe)],
      'POST /v1/demo/session': rawResponse(204, null),
      'GET /v1/watchlist': jsonResponse(watchlistBody()),
    });
    visit('/sign-up');
    render(<App />);
    expect(calls).toHaveLength(0);
    fireEvent.click(within(main()).getByRole('button', { name: 'Try the read-only demo' }));
    await waitFor(() => {
      expect(window.location.pathname).toBe('/watchlist');
    });
    expect(calls.filter((call) => call.key === 'POST /v1/demo/session')).toHaveLength(1);
    expect(calls.filter((call) => call.key === 'POST /register')).toHaveLength(0);
    expect(window.localStorage.getItem(SESSION_SIGNAL_KEY)).not.toBeNull();
  });
});

describe('the other account pages keep their plain column (review, D2-i2-T06)', () => {
  it('leaves /password/forgot with its h1 as the first child of main and no panel region', () => {
    stubAccountFetch({});
    visit('/password/forgot');
    render(<App />);
    const first = main().firstElementChild;
    expect(first?.tagName).toBe('H1');
    expect(first?.textContent).toBe('Reset your password');
    expect(within(main()).queryAllByRole('region')).toHaveLength(0);
    expect(within(main()).queryByText('Just looking?')).toBeNull();
    expect(within(main()).queryByRole('button', { name: /^Show/ })).toBeNull();
    expect(within(main()).queryByText('(required)')).toBeNull();
  });
});
