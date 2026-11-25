// One session transition per kind (Q1-i3 T02, review c3): a write that signs this browser in
// (sign-in, the two-factor challenge, sign-up, the demo) forgets the last account, asks ['me'] again
// and only then opens the next page; a write that signs it out (sign-out, deleting the account) marks
// ['me'] signed out first, forgets the last account, asks ['me'] again and opens the next page, if
// any. Each list below is the exact order of the calls the transition makes. The control that started
// a transition stays disabled until the transition is over: a second press never sends a second write.
import type { QueryClient } from '@tanstack/react-query';
import { act, cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import App from '../App';
import type * as QueryModule from '../query';
import {
  clearXsrfCookie,
  holdSessionCookie,
  demoMe,
  jsonResponse,
  problemResponse,
  rawResponse,
  signedInMe,
  stubAccountFetch,
  unauthenticatedProblem,
  type AccountCall,
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

const PASSWORD = 'fake-pass-0000';
const CODE = '000000';
const SIGNED_OUT = () => problemResponse(unauthenticatedProblem);

function visit(path: string): void {
  window.history.replaceState(null, '', path);
}

afterEach(() => {
  cleanup();
  clearXsrfCookie();
  vi.restoreAllMocks();
  window.localStorage.clear();
  created.clients.length = 0;
  visit('/');
});

function main(): HTMLElement {
  return screen.getByRole('main');
}

function client(): QueryClient {
  const first = created.clients[0];
  if (first === undefined) throw new Error('App created no QueryClient');
  return first;
}

function fill(label: string, value: string): void {
  fireEvent.change(within(main()).getByLabelText(label), { target: { value } });
}

function button(name: string): HTMLButtonElement {
  return within(main()).getByRole<HTMLButtonElement>('button', { name });
}

function isMeKey(key: unknown): boolean {
  return Array.isArray(key) && key.length === 1 && key[0] === 'me';
}

/**
 * Records, from now on, the calls a session transition makes: setQueryData and invalidateQueries
 * on ['me'], endSession (its cross-tab signal) and navigate (history.pushState). The returned
 * function lists them in the order they were made.
 */
function recordTransition(): () => string[] {
  const queryClient = client();
  const setQueryData = vi.spyOn(queryClient, 'setQueryData');
  const invalidate = vi.spyOn(queryClient, 'invalidateQueries');
  const setItem = vi.spyOn(Storage.prototype, 'setItem');
  const pushState = vi.spyOn(window.history, 'pushState');
  return () => {
    const steps: (readonly [number, string])[] = [];
    function add(order: readonly number[], index: number, step: string): void {
      steps.push([order[index] ?? 0, step]);
    }
    setQueryData.mock.calls.forEach(([key, value], index) => {
      if (isMeKey(key)) {
        add(
          setQueryData.mock.invocationCallOrder,
          index,
          `setQueryData me ${JSON.stringify(value)}`,
        );
      }
    });
    invalidate.mock.calls.forEach(([filters], index) => {
      if (isMeKey(filters?.queryKey)) {
        const type = filters?.refetchType ?? 'active';
        add(invalidate.mock.invocationCallOrder, index, `invalidate me ${type}`);
      }
    });
    setItem.mock.calls.forEach(([key], index) => {
      if (key === SESSION_SIGNAL_KEY) add(setItem.mock.invocationCallOrder, index, 'endSession');
    });
    pushState.mock.calls.forEach(([, , url], index) => {
      add(pushState.mock.invocationCallOrder, index, `navigate ${String(url)}`);
    });
    return steps.sort((a, b) => a[0] - b[0]).map(([, step]) => step);
  };
}

/**
 * Holds the next GET /v1/me until release(): the page then sits between "the write answered" and
 * "the session was read again".
 */
function holdNextMe(): { readonly held: () => boolean; readonly release: () => void } {
  const inner = globalThis.fetch;
  let release: () => void = () => undefined;
  let held = false;
  vi.stubGlobal('fetch', (input: string | URL, init?: RequestInit): Promise<Response> => {
    if (String(input) === '/v1/me' && !held) {
      held = true;
      return new Promise((resolve) => {
        release = () => {
          resolve(inner(input, init));
        };
      });
    }
    return inner(input, init);
  });
  return {
    held: () => held,
    release: () => {
      release();
    },
  };
}

/** Let every pending answer and render run. */
async function settle(): Promise<void> {
  for (let round = 0; round < 5; round += 1) {
    await act(async () => {
      await new Promise((resolve) => setTimeout(resolve, 0));
    });
  }
}

function count(calls: readonly AccountCall[], key: string): number {
  return calls.filter((call) => call.key === key).length;
}

const SIGN_IN_ORDER = (to: string) => ['endSession', 'invalidate me all', `navigate ${to}`];

async function openSignIn(routes: Parameters<typeof stubAccountFetch>[0]): Promise<AccountCall[]> {
  const calls = stubAccountFetch({
    'GET /v1/me': [SIGNED_OUT(), jsonResponse(signedInMe)],
    ...routes,
  });
  visit('/sign-in');
  render(<App />);
  await within(main()).findByLabelText('Email');
  return calls;
}

function submitSignIn(): void {
  fill('Email', 'collector@example.com');
  fill('Password', PASSWORD);
  fireEvent.click(button('Sign in'));
}

async function reachChallenge(): Promise<AccountCall[]> {
  const calls = await openSignIn({
    'POST /login': jsonResponse({ two_factor: true }),
    'POST /two-factor-challenge': rawResponse(204, null),
  });
  submitSignIn();
  await within(main()).findByLabelText('Authentication code');
  return calls;
}

async function openSignUp(routes: Parameters<typeof stubAccountFetch>[0]): Promise<AccountCall[]> {
  holdSessionCookie();
  const calls = stubAccountFetch({
    'GET /v1/me': [SIGNED_OUT(), jsonResponse(signedInMe)],
    'POST /register': rawResponse(201, '""', 'application/json'),
    ...routes,
  });
  // Through /account: ['me'] is cached (signed out) when the sign-up form is sent.
  visit('/account');
  render(<App />);
  await within(main()).findByText('You are not signed in.');
  fireEvent.click(within(main()).getByRole('link', { name: 'Create an account' }));
  await within(main()).findByLabelText('Invite code');
  return calls;
}

function submitSignUp(): void {
  fill('Invite code', 'FAKE-INVITE-0000');
  fill('Email', 'newcomer@example.com');
  fill('Password', PASSWORD);
  fill('Confirm password', PASSWORD);
  fireEvent.click(button('Create account'));
}

async function openAccount(routes: Parameters<typeof stubAccountFetch>[0]): Promise<AccountCall[]> {
  const calls = stubAccountFetch({
    'GET /v1/me': [jsonResponse(signedInMe), SIGNED_OUT()],
    ...routes,
  });
  visit('/account');
  render(<App />);
  await screen.findByText('Signed in as collector@example.com');
  return calls;
}

describe('a write that signs this browser in runs one transition order', () => {
  it('sign-in: forgets the last account, asks ["me"] again, then opens next', async () => {
    await openSignIn({ 'POST /login': jsonResponse({ two_factor: false }) });
    const steps = recordTransition();
    submitSignIn();
    await waitFor(() => {
      expect(window.location.pathname).toBe('/account');
    });
    expect(steps()).toEqual(SIGN_IN_ORDER('/account'));
  });

  it('the two-factor challenge: the same order', async () => {
    await reachChallenge();
    const steps = recordTransition();
    fill('Authentication code', CODE);
    fireEvent.click(button('Verify'));
    await waitFor(() => {
      expect(window.location.pathname).toBe('/account');
    });
    expect(steps()).toEqual(SIGN_IN_ORDER('/account'));
  });

  it('sign-up: the same order, then /account', async () => {
    await openSignUp({});
    const steps = recordTransition();
    submitSignUp();
    await waitFor(() => {
      expect(window.location.pathname).toBe('/account');
    });
    expect(steps()).toEqual(SIGN_IN_ORDER('/account'));
  });

  it('the demo: the same order, then /watchlist', async () => {
    stubAccountFetch({
      'GET /v1/me': [SIGNED_OUT(), jsonResponse(demoMe)],
      'POST /v1/demo/session': rawResponse(204, null),
      'GET /v1/watchlist': jsonResponse({
        data: [],
        meta: { count: 0, limit: 100, degraded: false },
      }),
    });
    visit('/sign-in');
    render(<App />);
    const demo = await within(main()).findByRole('button', { name: 'Try the read-only demo' });
    const steps = recordTransition();
    fireEvent.click(demo);
    await waitFor(() => {
      expect(window.location.pathname).toBe('/watchlist');
    });
    expect(steps()).toEqual(SIGN_IN_ORDER('/watchlist'));
  });
});

describe('a write that signs this browser out runs one transition order', () => {
  it('sign-out: marks ["me"] signed out, forgets the last account, asks ["me"] again, opens /', async () => {
    await openAccount({ 'POST /logout': rawResponse(204, null) });
    const steps = recordTransition();
    fireEvent.click(button('Sign out'));
    await waitFor(() => {
      expect(window.location.pathname).toBe('/');
    });
    expect(steps()).toEqual([
      'setQueryData me null',
      'endSession',
      'invalidate me active',
      'navigate /',
    ]);
  });

  it('deleting the account: the same order, and it stays on /account', async () => {
    await openAccount({ 'POST /v1/me/deletion': rawResponse(204, null) });
    const steps = recordTransition();
    const zone = screen.getByRole('region', { name: 'Delete account' });
    fireEvent.click(
      within(zone).getByRole('checkbox', { name: 'I understand that this cannot be undone' }),
    );
    fireEvent.change(within(zone).getByLabelText('Password'), { target: { value: PASSWORD } });
    fireEvent.click(within(zone).getByRole('button', { name: 'Delete my account' }));
    await screen.findByText('Your account has been deleted.');
    expect(steps()).toEqual(['setQueryData me null', 'endSession', 'invalidate me active']);
    expect(window.location.pathname).toBe('/account');
  });
});

describe('the control that signs in stays disabled until the session was read again', () => {
  it('sign-up: Create account cannot send a second POST /register while ["me"] is asked again', async () => {
    const calls = await openSignUp({});
    const me = holdNextMe();
    submitSignUp();
    await waitFor(() => {
      expect(me.held()).toBe(true);
    });
    await settle();
    expect(button('Create account').disabled).toBe(true);
    submitSignUp();
    fireEvent.submit(button('Create account').closest('form') as HTMLFormElement);
    me.release();
    await waitFor(() => {
      expect(window.location.pathname).toBe('/account');
    });
    expect(count(calls, 'POST /register')).toBe(1);
  });

  it('sign-in: Sign in cannot send a second POST /login while ["me"] is asked again', async () => {
    const calls = await openSignIn({ 'POST /login': jsonResponse({ two_factor: false }) });
    const me = holdNextMe();
    submitSignIn();
    await waitFor(() => {
      expect(me.held()).toBe(true);
    });
    await settle();
    expect(button('Sign in').disabled).toBe(true);
    submitSignIn();
    fireEvent.submit(button('Sign in').closest('form') as HTMLFormElement);
    me.release();
    await waitFor(() => {
      expect(window.location.pathname).toBe('/account');
    });
    expect(count(calls, 'POST /login')).toBe(1);
  });

  it('the demo: Try the read-only demo cannot send a second session while ["me"] is asked again', async () => {
    const calls = stubAccountFetch({
      'GET /v1/me': [SIGNED_OUT(), jsonResponse(demoMe)],
      'POST /v1/demo/session': rawResponse(204, null),
      'GET /v1/watchlist': jsonResponse({
        data: [],
        meta: { count: 0, limit: 100, degraded: false },
      }),
    });
    visit('/sign-in');
    render(<App />);
    const demo = await within(main()).findByRole<HTMLButtonElement>('button', {
      name: 'Try the read-only demo',
    });
    const me = holdNextMe();
    fireEvent.click(demo);
    await waitFor(() => {
      expect(me.held()).toBe(true);
    });
    await settle();
    expect(demo.disabled).toBe(true);
    fireEvent.click(demo);
    me.release();
    await waitFor(() => {
      expect(window.location.pathname).toBe('/watchlist');
    });
    expect(count(calls, 'POST /v1/demo/session')).toBe(1);
  });
});
