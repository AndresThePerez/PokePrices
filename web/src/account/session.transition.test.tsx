// Session transitions (Q0 U1): nothing one account owns may show under the next one, whether the
// switch happens through this tab's own sign-out and sign-in or is seen only through a ['me']
// refetch (the five-minute poll or a window focus after another tab switched accounts), and a 401
// on any per-user request sends the session back through GET /v1/me.
import type { QueryClient } from '@tanstack/react-query';
import { act, cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import App from '../App';
import type * as QueryModule from '../query';
import { navigate } from '../router';
import {
  FAKE_SETUP_KEY,
  FAKE_SIGNING_SECRET,
  clearXsrfCookie,
  domHolds,
  emptyJsonResponse,
  holdSessionCookie,
  invalidSignatureProblem,
  jsonResponse,
  notificationsBody,
  problemResponse,
  rawResponse,
  serverErrorProblem,
  signedInMe,
  stubAccountFetch,
  twoFactorQrAnswer,
  twoFactorSecretAnswer,
  unauthenticatedProblem,
  watchlistBody,
  webhookCreatedBody,
  webhookOf,
  webhooksBody,
  type AccountCall,
} from '../test/fixtures';

// App builds its own QueryClient; capture it so a test can refetch ['me'] like the poll does.
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

function visit(path: string): void {
  window.history.replaceState(null, '', path);
}

// Every test here starts signed in as A: the browser holds the token cookie from the first render.
beforeEach(() => {
  holdSessionCookie();
});

afterEach(() => {
  cleanup();
  clearXsrfCookie();
  created.clients.length = 0;
  visit('/');
});

const B_EMAIL = 'second@example.com';
const B_ME = { data: { ...signedInMe.data, email: B_EMAIL } };
const A_HOOK = 'https://a-private.example.com/…';
const PASSWORD = 'fake-pass-0000';
const SIGNATURE = 'fake0signature0000beef';
const LINK = `/email/verify/7/0a1b2c?expires=1700000000&signature=${SIGNATURE}`;
const VERIFY_PAGE = `/account/verify-email?link=${encodeURIComponent(LINK)}`;

function client(): QueryClient {
  const first = created.clients[0];
  if (first === undefined) throw new Error('App created no QueryClient');
  return first;
}

function count(calls: readonly AccountCall[], key: string): number {
  return calls.filter((call) => call.key === key).length;
}

/** Refetch ['me'] the way the five-minute poll or a window focus does. */
async function pollMe(): Promise<void> {
  await act(async () => {
    await client().refetchQueries({ queryKey: ['me'] });
  });
}

/** Let every pending answer and render run. */
async function settle(): Promise<void> {
  for (let round = 0; round < 5; round += 1) {
    await act(async () => {
      await new Promise((resolve) => setTimeout(resolve, 0));
    });
  }
}

function digest(): boolean {
  return screen.getByRole<HTMLInputElement>('checkbox', { name: 'Send me a daily digest' }).checked;
}

async function signOutThroughAccount(): Promise<void> {
  fireEvent.click(screen.getByRole('link', { name: 'Account' }));
  fireEvent.click(await screen.findByRole('button', { name: 'Sign out' }));
  await screen.findByRole('heading', {
    level: 1,
    name: 'Pokémon card prices at the daily close',
  });
}

/** Sign in as B through the sign-in page; `asB` switches the stubbed GET /v1/me to B. */
async function signInAsB(asB: () => void): Promise<void> {
  fireEvent.click(within(screen.getByRole('banner')).getByRole('link', { name: 'Sign in' }));
  fireEvent.change(await screen.findByLabelText('Email'), { target: { value: B_EMAIL } });
  fireEvent.change(screen.getByLabelText('Password'), { target: { value: PASSWORD } });
  asB();
  fireEvent.click(within(screen.getByRole('main')).getByRole('button', { name: 'Sign in' }));
  await screen.findByText(`Signed in as ${B_EMAIL}`);
}

describe('an identity change seen only through ["me"] clears what the last account owned (C2)', () => {
  it('watchlist: account A rows leave the screen once ["me"] says account B', async () => {
    let me: Response = jsonResponse(signedInMe);
    let list: Response = jsonResponse(watchlistBody());
    const calls = stubAccountFetch({
      'GET /v1/me': () => me,
      'GET /v1/watchlist': () => list,
    });
    visit('/watchlist');
    render(<App />);
    expect(await screen.findByRole('link', { name: 'Chimecho' })).toBeTruthy();

    // Another tab signed A out and B in; B's own list cannot load.
    me = jsonResponse(B_ME);
    list = problemResponse(serverErrorProblem('/v1/watchlist'));
    await pollMe();
    await settle();
    expect(count(calls, 'GET /v1/me')).toBe(2);
    expect(screen.queryAllByRole('link', { name: 'Chimecho' })).toEqual([]);
    expect(document.body.textContent).not.toContain('Charizard');
    expect(await screen.findByText(/Could not load your watchlist/)).toBeTruthy();
  });

  it('alerts: B never sees A webhooks or A settings beside B email', async () => {
    let me: Response = jsonResponse(signedInMe);
    let hooks: Response = jsonResponse(webhooksBody([webhookOf({ url_hint: A_HOOK })]));
    let settings: Response = jsonResponse(
      notificationsBody({ channel: 'discord', digest: true, discord_available: true }),
    );
    stubAccountFetch({
      'GET /v1/me': () => me,
      'GET /v1/webhooks': () => hooks,
      'GET /v1/me/notifications': () => settings,
    });
    visit('/alerts');
    render(<App />);
    expect(await screen.findByText(A_HOOK)).toBeTruthy();
    await waitFor(() => {
      expect(digest()).toBe(true);
    });

    me = jsonResponse(B_ME);
    hooks = jsonResponse(webhooksBody([]));
    settings = jsonResponse(notificationsBody());
    await pollMe();
    expect(await screen.findByText(`Email (${B_EMAIL})`)).toBeTruthy();
    await settle();
    expect(document.body.textContent).not.toContain(A_HOOK);
    expect(digest()).toBe(false);
  });

  it('alerts: A one-time signing secret (component state) is gone once ["me"] says B', async () => {
    let me: Response = jsonResponse(signedInMe);
    stubAccountFetch({
      'GET /v1/me': () => me,
      'GET /v1/webhooks': jsonResponse(webhooksBody([])),
      'GET /v1/me/notifications': jsonResponse(notificationsBody()),
      'POST /v1/webhooks': jsonResponse(webhookCreatedBody(webhookOf(), FAKE_SIGNING_SECRET), {
        status: 201,
      }),
    });
    visit('/alerts');
    render(<App />);
    fireEvent.change(await screen.findByLabelText('URL'), {
      target: { value: 'https://hooks.example.com/a' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Add webhook' }));
    expect(await screen.findByRole('heading', { level: 3, name: 'Signing secret' })).toBeTruthy();
    expect(domHolds(FAKE_SIGNING_SECRET)).toBe(true);

    me = jsonResponse(B_ME);
    await pollMe();
    expect(await screen.findByText(`Email (${B_EMAIL})`)).toBeTruthy();
    expect(domHolds(FAKE_SIGNING_SECRET)).toBe(false);
    expect(screen.queryByRole('heading', { name: 'Signing secret' })).toBeNull();
  });

  it('account: A two-factor setup key and QR are gone once ["me"] says B', async () => {
    let me: Response = jsonResponse(signedInMe);
    stubAccountFetch({
      'GET /v1/me': () => me,
      'POST /user/two-factor-authentication': emptyJsonResponse(200),
      'GET /user/two-factor-qr-code': jsonResponse(twoFactorQrAnswer),
      'GET /user/two-factor-secret-key': jsonResponse(twoFactorSecretAnswer),
    });
    visit('/account');
    render(<App />);
    await screen.findByText('Signed in as collector@example.com');
    fireEvent.click(screen.getByRole('button', { name: 'Turn on two-factor authentication' }));
    await waitFor(() => {
      expect(domHolds(FAKE_SETUP_KEY)).toBe(true);
    });

    me = jsonResponse(B_ME);
    await pollMe();
    expect(await screen.findByText(`Signed in as ${B_EMAIL}`)).toBeTruthy();
    expect(domHolds(FAKE_SETUP_KEY)).toBe(false);
    expect(screen.queryByRole('img', { name: 'QR code for your authenticator app' })).toBeNull();
  });

  it('keeps the cached lists when ["me"] goes A to signed out and back to A', async () => {
    let me: Response = jsonResponse(signedInMe);
    const calls = stubAccountFetch({
      'GET /v1/me': () => me,
      'GET /v1/watchlist': jsonResponse(watchlistBody()),
    });
    visit('/watchlist');
    render(<App />);
    expect(await screen.findByRole('link', { name: 'Chimecho' })).toBeTruthy();

    me = problemResponse(unauthenticatedProblem);
    await pollMe();
    expect(await screen.findByText('Sign in to keep a watchlist.')).toBeTruthy();
    me = jsonResponse(signedInMe);
    await pollMe();
    expect(await screen.findByRole('link', { name: 'Chimecho' })).toBeTruthy();
    await settle();
    // Same account: the cached list was kept (only marked stale by the signed-out answer), so it
    // shows at once and is asked for once more, not dropped and loaded from nothing.
    expect(count(calls, 'GET /v1/watchlist')).toBe(2);
  });
});

describe('a late write answer never lands in the next account (C1)', () => {
  it('A PUT /v1/me/notifications resolving after sign-out and sign-in as B leaves B settings alone', async () => {
    let me: Response = jsonResponse(signedInMe);
    let settings: Response = jsonResponse(notificationsBody());
    stubAccountFetch({
      'GET /v1/me': () => me,
      'GET /v1/webhooks': jsonResponse(webhooksBody([])),
      'GET /v1/me/notifications': () => settings,
      'POST /logout': rawResponse(204, null),
      'POST /login': jsonResponse({ two_factor: false }),
    });
    const inner = globalThis.fetch;
    let releasePut: ((response: Response) => void) | undefined;
    vi.stubGlobal('fetch', (input: string | URL, init?: RequestInit): Promise<Response> => {
      if (init?.method === 'PUT' && String(input) === '/v1/me/notifications') {
        return new Promise<Response>((resolve) => {
          releasePut = resolve;
        });
      }
      return inner(input, init);
    });
    visit('/alerts');
    render(<App />);
    await waitFor(() => {
      expect(digest()).toBe(false);
    });
    // A ticks the digest and saves; the PUT hangs.
    fireEvent.click(screen.getByRole('checkbox', { name: 'Send me a daily digest' }));
    fireEvent.click(screen.getByRole('button', { name: 'Save' }));
    await waitFor(() => {
      expect(releasePut).toBeDefined();
    });

    me = problemResponse(unauthenticatedProblem);
    await signOutThroughAccount();
    await signInAsB(() => {
      me = jsonResponse(B_ME);
      settings = jsonResponse(notificationsBody());
    });
    act(() => {
      navigate('/alerts');
    });
    await screen.findByText(`Email (${B_EMAIL})`);
    expect(digest()).toBe(false);

    // A's answer lands now.
    await act(async () => {
      releasePut?.(jsonResponse(notificationsBody({ digest: true })));
      await Promise.resolve();
    });
    await settle();
    expect(digest()).toBe(false);
  });
});

describe('every per-user answer is forgotten at a session transition (C4)', () => {
  it('asks again for a verification link B opens after A verified it in this tab', async () => {
    let me: Response = jsonResponse(signedInMe);
    const calls = stubAccountFetch({
      'GET /v1/me': () => me,
      [`GET ${LINK}`]: [rawResponse(204, null), problemResponse(invalidSignatureProblem)],
      'POST /logout': rawResponse(204, null),
      'POST /login': jsonResponse({ two_factor: false }),
    });
    visit(VERIFY_PAGE);
    render(<App />);
    expect(await screen.findByText('Your email address is verified.')).toBeTruthy();

    me = problemResponse(unauthenticatedProblem);
    await signOutThroughAccount();
    await signInAsB(() => {
      me = jsonResponse(B_ME);
    });
    act(() => {
      navigate(VERIFY_PAGE);
    });
    await waitFor(() => {
      expect(count(calls, `GET ${LINK}`)).toBe(2);
    });
    expect(
      await screen.findByText(
        'This verification link is not valid or has expired. Send a new one from your account.',
      ),
    ).toBeTruthy();
    expect(screen.queryByText('Your email address is verified.')).toBeNull();
  });
});

describe('a 401 on a per-user request asks GET /v1/me again (C5)', () => {
  it('shows the signed-out view, not A rows, once the watchlist answers 401', async () => {
    let me: Response = jsonResponse(signedInMe);
    let list: Response = jsonResponse(watchlistBody());
    const calls = stubAccountFetch({
      'GET /v1/me': () => me,
      'GET /v1/watchlist': () => list,
    });
    visit('/watchlist');
    render(<App />);
    expect(await screen.findByRole('link', { name: 'Chimecho' })).toBeTruthy();

    // The session expired: the next watchlist poll answers 401.
    me = problemResponse(unauthenticatedProblem);
    list = problemResponse({ ...unauthenticatedProblem, instance: '/v1/watchlist' });
    await act(async () => {
      await client().refetchQueries({
        predicate: (query) => query.queryKey.includes('watchlist'),
      });
    });
    await waitFor(() => {
      expect(count(calls, 'GET /v1/me')).toBe(2);
    });
    expect(await screen.findByText('Sign in to keep a watchlist.')).toBeTruthy();
    expect(screen.queryAllByRole('link', { name: 'Chimecho' })).toEqual([]);
  });
});
