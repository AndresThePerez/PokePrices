// Reviewer edge cases for T04: secret retention on the exit paths the happy-path test does not
// walk, hostile strings from the API, the 423 loop, and the challenge step's state machine.
import type { QueryClient } from '@tanstack/react-query';
import { act, cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import App from '../App';
import type * as QueryModule from '../query';
import { navigate } from '../router';
import {
  FAKE_QR_SVG,
  FAKE_SETUP_KEY,
  accountValidationProblem,
  clearXsrfCookie,
  domHolds,
  emptyJsonResponse,
  fakeRecoveryCodes,
  jsonResponse,
  passwordRequiredResponse,
  problemResponse,
  rawResponse,
  sentBody,
  signedInMe,
  stubAccountFetch,
  twoFactorMe,
  twoFactorQrAnswer,
  twoFactorSecretAnswer,
  unauthenticatedProblem,
  type AccountCall,
} from '../test/fixtures';

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

const PASSWORD = 'fake-password-0000-ok';
const CODE = '000000';
const QR_ALT = 'QR code for your authenticator app';
const WRONG_CODE = 'The provided two factor authentication code was invalid.';

const ENABLE = 'POST /user/two-factor-authentication';
const CONFIRM_PASSWORD = 'POST /user/confirm-password';
const QR = 'GET /user/two-factor-qr-code';
const SECRET = 'GET /user/two-factor-secret-key';
const CONFIRM = 'POST /user/confirmed-two-factor-authentication';
const CODES = 'GET /user/two-factor-recovery-codes';
const CHALLENGE = 'POST /two-factor-challenge';

function visit(path: string): void {
  window.history.replaceState(null, '', path);
}

afterEach(() => {
  cleanup();
  clearXsrfCookie();
  window.localStorage.clear();
  window.sessionStorage.clear();
  created.clients.length = 0;
  visit('/');
});

function main(): HTMLElement {
  return screen.getByRole('main');
}

function click(name: string): void {
  fireEvent.click(within(main()).getByRole('button', { name }));
}

function fill(label: string, value: string): void {
  fireEvent.change(within(main()).getByLabelText(label), { target: { value } });
}

function count(calls: readonly AccountCall[], key: string): number {
  return calls.filter((call) => call.key === key).length;
}

function cacheText(): string {
  return JSON.stringify(
    created.clients.map((client) => ({
      queries: client
        .getQueryCache()
        .getAll()
        .map((query) => [query.queryKey, query.state.data, query.state.error]),
      mutations: client
        .getMutationCache()
        .getAll()
        .map((mutation) => [mutation.state.variables, mutation.state.data]),
    })),
  );
}

function storageText(): string {
  return JSON.stringify([
    Object.entries(window.localStorage),
    Object.entries(window.sessionStorage),
  ]);
}

function turnOnRoutes() {
  return {
    'GET /v1/me': [jsonResponse(signedInMe), jsonResponse(twoFactorMe)],
    [ENABLE]: [passwordRequiredResponse(), emptyJsonResponse(200)],
    [CONFIRM_PASSWORD]: emptyJsonResponse(201),
    [QR]: jsonResponse(twoFactorQrAnswer),
    [SECRET]: jsonResponse(twoFactorSecretAnswer),
    [CONFIRM]: emptyJsonResponse(200),
    [CODES]: jsonResponse(fakeRecoveryCodes),
  };
}

async function openAccount(): Promise<void> {
  visit('/account');
  render(<App />);
  await screen.findByText('Signed in as collector@example.com');
}

async function reachSetup(): Promise<void> {
  click('Turn on two-factor authentication');
  await within(main()).findByRole('button', { name: 'Confirm password' });
  fill('Password', PASSWORD);
  click('Confirm password');
  await within(main()).findByRole('img', { name: QR_ALT });
}

function noSecretLeft(secrets: readonly string[]): void {
  for (const secret of secrets) {
    expect(domHolds(secret)).toBe(false);
    expect(storageText()).not.toContain(secret);
    expect(cacheText()).not.toContain(secret);
  }
}

describe('secrets leave the page on every exit path (T04 criterion 4, D14)', () => {
  const SECRETS = [
    FAKE_SETUP_KEY,
    encodeURIComponent(FAKE_QR_SVG),
    PASSWORD,
    CODE,
    ...fakeRecoveryCodes,
  ];

  it('drops the QR code and setup key when the user signs out mid-setup', async () => {
    const calls = stubAccountFetch({
      ...turnOnRoutes(),
      'GET /v1/me': [
        jsonResponse(signedInMe),
        problemResponse(unauthenticatedProblem),
        problemResponse(unauthenticatedProblem),
      ],
      'POST /logout': rawResponse(204, null),
    });
    await openAccount();
    await reachSetup();
    expect(domHolds(FAKE_SETUP_KEY)).toBe(true);
    click('Sign out');
    await waitFor(() => {
      expect(window.location.pathname).toBe('/');
    });
    expect(count(calls, 'POST /logout')).toBe(1);
    noSecretLeft(SECRETS);
    expect(screen.queryByRole('img', { name: QR_ALT })).toBeNull();
    // Coming back to /account signed out shows no trace of the unfinished setup.
    act(() => {
      navigate('/account');
    });
    await screen.findByText('You are not signed in.');
    noSecretLeft(SECRETS);
    expect(within(main()).queryByRole('img')).toBeNull();
  });

  it('leaves nothing behind after a failed confirm followed by leaving the page', async () => {
    stubAccountFetch({
      ...turnOnRoutes(),
      [CONFIRM]: problemResponse(
        accountValidationProblem('/user/confirmed-two-factor-authentication', {
          code: [WRONG_CODE],
        }),
      ),
    });
    await openAccount();
    await reachSetup();
    fill('Authentication code', CODE);
    click('Confirm');
    await within(main()).findByRole('alert');
    act(() => {
      navigate('/nowhere');
    });
    await screen.findByText('Page not found');
    noSecretLeft([FAKE_SETUP_KEY, encodeURIComponent(FAKE_QR_SVG), PASSWORD, CODE]);
    act(() => {
      navigate('/account');
    });
    await within(main()).findByRole('button', { name: 'Turn on two-factor authentication' });
    expect(within(main()).queryByRole('img')).toBeNull();
    expect(within(main()).queryByRole('alert')).toBeNull();
    noSecretLeft([FAKE_SETUP_KEY, encodeURIComponent(FAKE_QR_SVG), PASSWORD, CODE]);
  });
});

describe('hostile strings from the API stay inert text (D10, XSS)', () => {
  it('renders a hostile svg only through an <img> data URL with D10’s exact alt and source', async () => {
    const hostile =
      '<svg xmlns="http://www.w3.org/2000/svg" onload="window.__pwned=1"><script>window.__pwned=2</script></svg>"><script>window.__pwned=3</script><img src=x onerror="window.__pwned=4">';
    stubAccountFetch({
      ...turnOnRoutes(),
      [QR]: jsonResponse({ svg: hostile, url: twoFactorQrAnswer.url }),
    });
    await openAccount();
    await reachSetup();
    const image = within(main()).getByRole<HTMLImageElement>('img', { name: QR_ALT });
    expect(image.getAttribute('alt')).toBe(QR_ALT);
    expect(image.getAttribute('src')).toBe(`data:image/svg+xml,${encodeURIComponent(hostile)}`);
    expect(image.getAttribute('src')?.startsWith('data:image/svg+xml,')).toBe(true);
    expect(image.getAttribute('src')).not.toContain('<');
    expect(document.querySelectorAll('script')).toHaveLength(0);
    expect(main().querySelectorAll('svg, [onload], [onerror]')).toHaveLength(0);
    expect(main().querySelectorAll('img')).toHaveLength(1);
    expect((window as unknown as { __pwned?: number }).__pwned).toBeUndefined();
  });

  it('renders a hostile setup key and hostile recovery codes as text, never as markup', async () => {
    const key = '<img src=x onerror="window.__pwned=5">KEY';
    const codes = ['<b id="injected">code-one</b>', '<script>window.__pwned=6</script>'];
    stubAccountFetch({
      ...turnOnRoutes(),
      [SECRET]: jsonResponse({ secretKey: key }),
      [CODES]: jsonResponse(codes),
    });
    await openAccount();
    await reachSetup();
    expect(within(main()).getByText(key).tagName).toBe('CODE');
    expect(main().querySelectorAll('img[src="x"], [onerror]')).toHaveLength(0);
    fill('Authentication code', CODE);
    click('Confirm');
    await within(main()).findByText(codes[0] ?? '');
    expect(
      within(main())
        .getAllByRole('listitem')
        .map((item) => item.textContent),
    ).toEqual(codes);
    expect(document.getElementById('injected')).toBeNull();
    expect(document.querySelectorAll('script')).toHaveLength(0);
    expect((window as unknown as { __pwned?: number }).__pwned).toBeUndefined();
  });
});

describe('the password prompt never loops (D2 RequirePassword, 423)', () => {
  it('prompts again after a second 423 right after a confirmed password, then completes', async () => {
    const calls = stubAccountFetch({
      ...turnOnRoutes(),
      [ENABLE]: [passwordRequiredResponse(), passwordRequiredResponse(), emptyJsonResponse(200)],
    });
    await openAccount();
    click('Turn on two-factor authentication');
    await within(main()).findByRole('button', { name: 'Confirm password' });
    fill('Password', PASSWORD);
    click('Confirm password');
    // Let the retry run: a second 423 puts the prompt back with an empty field, no extra requests.
    await waitFor(() => {
      expect(count(calls, ENABLE)).toBe(2);
    });
    const field = await within(main()).findByLabelText<HTMLInputElement>('Password');
    expect(field.value).toBe('');
    expect(within(main()).queryByRole('img')).toBeNull();
    expect(within(main()).queryByRole('alert')).toBeNull();
    await new Promise((resolve) => setTimeout(resolve, 30));
    expect(count(calls, ENABLE)).toBe(2);
    expect(count(calls, CONFIRM_PASSWORD)).toBe(1);
    fill('Password', PASSWORD);
    click('Confirm password');
    await within(main()).findByRole('img', { name: QR_ALT });
    expect(count(calls, ENABLE)).toBe(3);
    expect(count(calls, CONFIRM_PASSWORD)).toBe(2);
    expect(domHolds(PASSWORD)).toBe(false);
  });

  it('retries Confirm with the same typed code after a 423 at the confirm step', async () => {
    const calls = stubAccountFetch({
      ...turnOnRoutes(),
      [ENABLE]: emptyJsonResponse(200),
      [CONFIRM]: [passwordRequiredResponse(), emptyJsonResponse(200)],
    });
    await openAccount();
    click('Turn on two-factor authentication');
    await within(main()).findByRole('img', { name: QR_ALT });
    fill('Authentication code', '123456');
    click('Confirm');
    await within(main()).findByLabelText('Password');
    expect(within(main()).getByLabelText<HTMLInputElement>('Authentication code').value).toBe('');
    fill('Password', PASSWORD);
    click('Confirm password');
    await within(main()).findByText('Two-factor authentication is on.');
    const sent = calls.filter((call) => call.key === CONFIRM).map((call) => sentBody(call));
    expect(sent).toEqual([{ code: '123456' }, { code: '123456' }]);
    expect(domHolds('123456')).toBe(false);
    expect(domHolds(PASSWORD)).toBe(false);
    expect(domHolds(FAKE_SETUP_KEY)).toBe(false);
  });
});

describe('the sign-in challenge step is a clean state machine (T04 criterion 1)', () => {
  const RECOVERY = 'fake-recovery-0001';

  async function reachChallenge(
    routes: Parameters<typeof stubAccountFetch>[0],
    path = '/sign-in',
  ): Promise<AccountCall[]> {
    const calls = stubAccountFetch({
      'GET /v1/me': [problemResponse(unauthenticatedProblem), jsonResponse(signedInMe)],
      'POST /login': jsonResponse({ two_factor: true }),
      ...routes,
    });
    visit(path);
    render(<App />);
    await screen.findByRole('button', { name: 'Sign in' });
    fill('Email', 'collector@example.com');
    fill('Password', PASSWORD);
    click('Sign in');
    await within(main()).findByRole('heading', { level: 2, name: 'Two-factor authentication' });
    return calls;
  }

  it('sends one request for a double click and a double Enter while the first is in flight', async () => {
    const calls = await reachChallenge({ [CHALLENGE]: rawResponse(204, null) });
    fill('Authentication code', CODE);
    const verify = within(main()).getByRole('button', { name: 'Verify' });
    fireEvent.click(verify);
    fireEvent.click(verify);
    fireEvent.submit(verify.closest('form') as HTMLFormElement);
    await waitFor(() => {
      expect(window.location.pathname).toBe('/account');
    });
    expect(count(calls, CHALLENGE)).toBe(1);
    expect(domHolds(CODE)).toBe(false);
  });

  it('does not send a second challenge while the session refetch after a 204 is still pending', async () => {
    const calls = await reachChallenge({ [CHALLENGE]: rawResponse(204, null) });
    // Hold the post-challenge GET /v1/me so the page sits between "204 received" and "navigated".
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
    fill('Authentication code', CODE);
    click('Verify');
    await waitFor(() => {
      expect(held).toBe(true);
    });
    const verify = within(main()).queryByRole<HTMLButtonElement>('button', { name: 'Verify' });
    if (verify !== null) {
      fireEvent.click(verify);
      fireEvent.submit(verify.closest('form') as HTMLFormElement);
    }
    release();
    await waitFor(() => {
      expect(window.location.pathname).toBe('/account');
    });
    expect(count(calls, CHALLENGE)).toBe(1);
  });

  it('clears the typed value and the error when switching between code and recovery code, both ways', async () => {
    await reachChallenge({
      [CHALLENGE]: problemResponse(
        accountValidationProblem('/two-factor-challenge', { code: [WRONG_CODE] }),
      ),
    });
    fill('Authentication code', CODE);
    click('Verify');
    await within(main()).findByRole('alert');
    expect(within(main()).getByLabelText<HTMLInputElement>('Authentication code').value).toBe('');
    fill('Authentication code', '999999');
    click('Use a recovery code instead');
    expect(within(main()).queryByLabelText('Authentication code')).toBeNull();
    expect(within(main()).getByLabelText<HTMLInputElement>('Recovery code').value).toBe('');
    expect(within(main()).queryByRole('alert')).toBeNull();
    expect(domHolds('999999')).toBe(false);
    fill('Recovery code', RECOVERY);
    click('Use an authentication code instead');
    expect(within(main()).getByLabelText<HTMLInputElement>('Authentication code').value).toBe('');
    expect(within(main()).queryByLabelText('Recovery code')).toBeNull();
    expect(domHolds(RECOVERY)).toBe(false);
    expect(domHolds(CODE)).toBe(false);
  });

  it('shows a dropped connection as a clean sign-in failure with the field cleared and Verify usable again', async () => {
    await reachChallenge({ [CHALLENGE]: new TypeError('network down') });
    fill('Authentication code', CODE);
    click('Verify');
    const alert = await within(main()).findByRole('alert');
    expect(alert.textContent).toContain('Could not sign in:');
    expect(within(main()).getByLabelText<HTMLInputElement>('Authentication code').value).toBe('');
    expect(within(main()).getByRole<HTMLButtonElement>('button', { name: 'Verify' }).disabled).toBe(
      false,
    );
    expect(window.location.pathname).toBe('/sign-in');
    expect(domHolds(CODE)).toBe(false);
  });
});
