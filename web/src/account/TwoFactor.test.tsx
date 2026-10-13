import type { QueryClient } from '@tanstack/react-query';
import { act, cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import App from '../App';
import {
  confirmPassword,
  confirmTwoFactor,
  disableTwoFactor,
  enableTwoFactor,
  recoveryCodes,
  regenerateRecoveryCodes,
  twoFactorChallenge,
  twoFactorQrCode,
  twoFactorSecretKey,
} from '../api/client';
import type * as QueryModule from '../query';
import { navigate } from '../router';
import {
  FAKE_QR_SVG,
  FAKE_SETUP_KEY,
  FORBIDDEN_WORDS,
  XSRF_HEADER,
  accountValidationProblem,
  clearXsrfCookie,
  domHolds,
  emptyJsonResponse,
  fakeNewRecoveryCodes,
  fakeRecoveryCodes,
  jsonResponse,
  lightOnlyClasses,
  passwordRequiredResponse,
  problemResponse,
  rawResponse,
  sentBody,
  serverErrorProblem,
  setXsrfCookie,
  shortControls,
  signedInMe,
  stubAccountFetch,
  tooManyRequestsProblem,
  twoFactorMe,
  twoFactorQrAnswer,
  twoFactorSecretAnswer,
  type AccountCall,
} from '../test/fixtures';

// App builds its own QueryClient; capture each one so criterion 4 can inspect the query cache.
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
const WRONG_CODE = 'The provided two factor authentication code was invalid.';
const WRONG_PASSWORD = 'The provided password was incorrect.';
const QR_ALT = 'QR code for your authenticator app';

const ENABLE = 'POST /user/two-factor-authentication';
const DISABLE = 'DELETE /user/two-factor-authentication';
const CONFIRM_PASSWORD = 'POST /user/confirm-password';
const QR = 'GET /user/two-factor-qr-code';
const SECRET = 'GET /user/two-factor-secret-key';
const CONFIRM = 'POST /user/confirmed-two-factor-authentication';
const CODES = 'GET /user/two-factor-recovery-codes';
const REGENERATE = 'POST /user/two-factor-recovery-codes';

function visit(path: string): void {
  window.history.replaceState(null, '', path);
}

afterEach(() => {
  cleanup();
  clearXsrfCookie();
  vi.useRealTimers();
  window.localStorage.clear();
  window.sessionStorage.clear();
  created.clients.length = 0;
  visit('/');
});

function main(): HTMLElement {
  return screen.getByRole('main');
}

function section(): HTMLElement {
  return within(main()).getByRole('region', { name: 'Two-factor authentication' });
}

function click(name: string): void {
  fireEvent.click(within(main()).getByRole('button', { name }));
}

function fill(label: string, value: string): void {
  fireEvent.change(within(main()).getByLabelText(label), { target: { value } });
}

function keys(calls: readonly AccountCall[]): string[] {
  return calls.map((call) => call.key).filter((key) => key !== 'GET /sanctum/csrf-cookie');
}

function qrImage(): HTMLImageElement {
  return within(main()).getByRole<HTMLImageElement>('img', { name: QR_ALT });
}

/** Everything the app's query and mutation caches hold, as one string. */
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
        .map((mutation) => mutation.state.data),
    })),
  );
}

function storageText(): string {
  return JSON.stringify([
    Object.entries(window.localStorage),
    Object.entries(window.sessionStorage),
  ]);
}

/** The full turn-on route table: 423, password, retry, QR, key, confirm, codes, me on. */
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
  fill('Password', await passwordPrompt());
  click('Confirm password');
  await waitFor(() => {
    expect(within(main()).queryByRole('img', { name: QR_ALT })).not.toBeNull();
  });
}

async function passwordPrompt(): Promise<string> {
  await within(main()).findByRole('button', { name: 'Confirm password' });
  return PASSWORD;
}

describe('the two-factor client (T04, D11)', () => {
  it('confirms the password with POST /user/confirm-password and the XSRF header', async () => {
    const calls = stubAccountFetch({ [CONFIRM_PASSWORD]: emptyJsonResponse(201) });
    const result = await confirmPassword({ password: PASSWORD });
    expect(result).toEqual({ ok: true, data: undefined });
    expect(keys(calls)).toEqual([CONFIRM_PASSWORD]);
    const call = calls.find((entry) => entry.key === CONFIRM_PASSWORD);
    expect(sentBody(call)).toEqual({ password: PASSWORD });
    const headers = call?.init?.headers as Record<string, string>;
    expect(headers['X-XSRF-TOKEN']).toBe(XSRF_HEADER);
    expect(headers['Content-Type']).toBe('application/json');
  });

  it('turns two-factor on, confirms it and turns it off with Fortify’s empty 200s', async () => {
    setXsrfCookie();
    const calls = stubAccountFetch({
      [ENABLE]: emptyJsonResponse(200),
      [CONFIRM]: emptyJsonResponse(200),
      [DISABLE]: emptyJsonResponse(200),
      [REGENERATE]: emptyJsonResponse(200),
    });
    expect(await enableTwoFactor()).toEqual({ ok: true, data: undefined });
    expect(await confirmTwoFactor({ code: CODE })).toEqual({ ok: true, data: undefined });
    expect(await regenerateRecoveryCodes()).toEqual({ ok: true, data: undefined });
    expect(await disableTwoFactor()).toEqual({ ok: true, data: undefined });
    expect(keys(calls)).toEqual([ENABLE, CONFIRM, REGENERATE, DISABLE]);
    expect(sentBody(calls[0])).toBeUndefined();
    expect(sentBody(calls[1])).toEqual({ code: CODE });
  });

  it('surfaces RequirePassword’s 423 as a failure with status 423', async () => {
    setXsrfCookie();
    stubAccountFetch({ [ENABLE]: passwordRequiredResponse(), [QR]: passwordRequiredResponse() });
    expect(await enableTwoFactor()).toMatchObject({ ok: false, status: 423, type: 'about:blank' });
    expect(await twoFactorQrCode()).toMatchObject({ ok: false, status: 423 });
  });

  it('reads the QR code, the setup key and the recovery codes in Fortify’s shapes', async () => {
    const calls = stubAccountFetch({
      [QR]: jsonResponse(twoFactorQrAnswer),
      [SECRET]: jsonResponse(twoFactorSecretAnswer),
      [CODES]: jsonResponse(fakeRecoveryCodes),
    });
    expect(await twoFactorQrCode()).toEqual({ ok: true, data: twoFactorQrAnswer });
    expect(await twoFactorSecretKey()).toEqual({ ok: true, data: twoFactorSecretAnswer });
    expect(await recoveryCodes()).toEqual({ ok: true, data: fakeRecoveryCodes });
    // GETs never ask for the CSRF cookie.
    expect(calls.map((call) => call.key)).toEqual([QR, SECRET, CODES]);
  });

  it.each([
    ['the QR code', QR, [], () => twoFactorQrCode()],
    ['the QR code', QR, { svg: FAKE_QR_SVG }, () => twoFactorQrCode()],
    ['the setup key', SECRET, { secret: 'x' }, () => twoFactorSecretKey()],
    ['the recovery codes', CODES, { codes: [] }, () => recoveryCodes()],
    ['the recovery codes', CODES, ['ok', 7], () => recoveryCodes()],
  ] as const)('refuses %s in another shape (%s)', async (_name, key, body, read) => {
    stubAccountFetch({ [key]: jsonResponse(body) });
    expect(await read()).toMatchObject({ ok: false, status: 200 });
  });

  it('sends the challenge as {code} or {recovery_code} to /two-factor-challenge', async () => {
    setXsrfCookie();
    const calls = stubAccountFetch({ 'POST /two-factor-challenge': rawResponse(204, null) });
    expect(await twoFactorChallenge({ code: CODE })).toEqual({ ok: true, data: undefined });
    expect(await twoFactorChallenge({ recovery_code: 'fake-recovery-0001' })).toEqual({
      ok: true,
      data: undefined,
    });
    expect(calls.map((call) => sentBody(call))).toEqual([
      { code: CODE },
      { recovery_code: 'fake-recovery-0001' },
    ]);
  });

  it('sends every two-factor request same-origin, without following a redirect', async () => {
    const calls = stubAccountFetch({
      [CONFIRM_PASSWORD]: emptyJsonResponse(201),
      [ENABLE]: emptyJsonResponse(200),
      [QR]: jsonResponse(twoFactorQrAnswer),
      [SECRET]: jsonResponse(twoFactorSecretAnswer),
      [CONFIRM]: emptyJsonResponse(200),
      [CODES]: jsonResponse(fakeRecoveryCodes),
      [REGENERATE]: emptyJsonResponse(200),
      [DISABLE]: emptyJsonResponse(200),
      'POST /two-factor-challenge': rawResponse(204, null),
    });
    await confirmPassword({ password: PASSWORD });
    await enableTwoFactor();
    await twoFactorQrCode();
    await twoFactorSecretKey();
    await confirmTwoFactor({ code: CODE });
    await recoveryCodes();
    await regenerateRecoveryCodes();
    await disableTwoFactor();
    await twoFactorChallenge({ code: CODE });
    expect(calls).toHaveLength(10);
    for (const call of calls) {
      expect(call.init).toMatchObject({
        credentials: 'same-origin',
        referrerPolicy: 'same-origin',
        redirect: 'error',
      });
      expect((call.init?.headers as Record<string, string>).Accept).toBe('application/json');
    }
  });
});

describe('the two-factor section of /account (T04 criteria 2 and 3, D10)', () => {
  it('reads off from GET /v1/me alone and offers to turn it on', async () => {
    const calls = stubAccountFetch({ 'GET /v1/me': jsonResponse(signedInMe) });
    await openAccount();
    const region = section();
    expect(within(region).getByRole('heading', { level: 2 }).textContent).toBe(
      'Two-factor authentication',
    );
    expect(within(region).getByText('Two-factor authentication is off.')).toBeTruthy();
    expect(
      within(region).getByRole('button', { name: 'Turn on two-factor authentication' }),
    ).toBeTruthy();
    expect(within(region).queryByRole('img')).toBeNull();
    expect(keys(calls)).toEqual(['GET /v1/me']);
  });

  it('turns on: 423, the password prompt, the retry, the QR code and setup key, Confirm, the codes once', async () => {
    const calls = stubAccountFetch(turnOnRoutes());
    await openAccount();
    click('Turn on two-factor authentication');

    // 423: the password prompt.
    await within(main()).findByRole('button', { name: 'Confirm password' });
    const password = within(section()).getByLabelText('Password');
    expect(password.getAttribute('type')).toBe('password');
    expect(password.getAttribute('autocomplete')).toBe('current-password');
    expect(within(section()).getByText('Password').tagName).toBe('LABEL');
    expect(within(main()).queryByRole('img')).toBeNull();
    fill('Password', PASSWORD);
    click('Confirm password');

    // The retry: the QR code inside its white padded wrapper, the setup key and the code field.
    const image = await within(main()).findByRole<HTMLImageElement>('img', { name: QR_ALT });
    expect(image.getAttribute('alt')).toBe(QR_ALT);
    expect(image.getAttribute('src')).toBe(`data:image/svg+xml,${encodeURIComponent(FAKE_QR_SVG)}`);
    const wrapper = image.parentElement;
    expect(wrapper?.tagName).toBe('DIV');
    const wrapperClasses = wrapper?.className.split(/\s+/) ?? [];
    expect(wrapperClasses).toEqual(expect.arrayContaining(['inline-block', 'bg-white', 'p-4']));
    expect(wrapperClasses.filter((name) => name.startsWith('dark:'))).toEqual([]);
    expect(image.className.split(/\s+/).filter((name) => /^(?:dark:)?bg-/.test(name))).toEqual([]);
    expect(main().querySelector('svg')).toBeNull();
    const setupKey = within(section()).getByText(FAKE_SETUP_KEY);
    expect(setupKey.tagName).toBe('CODE');
    expect(setupKey.parentElement?.textContent).toBe(`Setup key ${FAKE_SETUP_KEY}`);
    expect(within(section()).queryByLabelText('Password')).toBeNull();
    expect(domHolds(PASSWORD)).toBe(false);
    const code = within(section()).getByLabelText('Authentication code');
    expect(code.getAttribute('inputmode')).toBe('numeric');
    expect(code.getAttribute('autocomplete')).toBe('one-time-code');
    fill('Authentication code', CODE);
    click('Confirm');

    // Confirmed: the codes once, ['me'] refetched, the section reads on.
    expect(await within(main()).findByText('Two-factor authentication is on.')).toBeTruthy();
    const region = section();
    expect(within(region).getByRole('heading', { level: 3 }).textContent).toBe('Recovery codes');
    expect(
      within(region).getByText('Store these codes somewhere safe. Each one works once.'),
    ).toBeTruthy();
    const items = within(region)
      .getAllByRole('listitem')
      .map((item) => item.textContent);
    expect(items).toEqual([...fakeRecoveryCodes]);
    expect(within(region).queryByRole('img')).toBeNull();
    expect(domHolds(FAKE_SETUP_KEY)).toBe(false);
    expect(domHolds(CODE)).toBe(false);
    expect(within(region).getByRole('button', { name: 'Show new recovery codes' })).toBeTruthy();
    expect(
      within(region).getByRole('button', { name: 'Turn off two-factor authentication' }),
    ).toBeTruthy();

    const sequence = keys(calls);
    expect(sequence.slice(0, 7)).toEqual([
      'GET /v1/me',
      ENABLE,
      CONFIRM_PASSWORD,
      ENABLE,
      QR,
      SECRET,
      CONFIRM,
    ]);
    expect(sequence.slice(7).sort()).toEqual([CODES, 'GET /v1/me'].sort());
    expect(sentBody(calls.find((call) => call.key === CONFIRM_PASSWORD))).toEqual({
      password: PASSWORD,
    });
    expect(sentBody(calls.find((call) => call.key === CONFIRM))).toEqual({ code: CODE });
  });

  it('goes straight to the setup step when no password confirmation is needed', async () => {
    const calls = stubAccountFetch({
      'GET /v1/me': jsonResponse(signedInMe),
      [ENABLE]: emptyJsonResponse(200),
      [QR]: jsonResponse(twoFactorQrAnswer),
      [SECRET]: jsonResponse(twoFactorSecretAnswer),
    });
    await openAccount();
    click('Turn on two-factor authentication');
    expect(await within(main()).findByRole('img', { name: QR_ALT })).toBeTruthy();
    expect(within(main()).queryByRole('button', { name: 'Confirm password' })).toBeNull();
    expect(keys(calls)).toEqual(['GET /v1/me', ENABLE, QR, SECRET]);
  });

  it('shows a wrong code under its field, clears it and keeps the setup step', async () => {
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
    const alert = await within(main()).findByRole('alert');
    expect(alert.textContent).toContain(
      'Could not change two-factor authentication: check the fields below.',
    );
    const code = within(section()).getByLabelText<HTMLInputElement>('Authentication code');
    expect(code.getAttribute('aria-invalid')).toBe('true');
    const message = document.getElementById(code.getAttribute('aria-describedby') ?? '');
    expect(message?.textContent).toBe(WRONG_CODE);
    expect(code.value).toBe('');
    expect(qrImage()).toBeTruthy();
    expect(within(section()).getByText('Two-factor authentication is off.')).toBeTruthy();
    expect(within(section()).queryByRole('list')).toBeNull();
  });

  it('shows a wrong password under its field, clears it and keeps the prompt', async () => {
    stubAccountFetch({
      'GET /v1/me': jsonResponse(signedInMe),
      [ENABLE]: passwordRequiredResponse(),
      [CONFIRM_PASSWORD]: problemResponse(
        accountValidationProblem('/user/confirm-password', { password: [WRONG_PASSWORD] }),
      ),
    });
    await openAccount();
    click('Turn on two-factor authentication');
    fill('Password', await passwordPrompt());
    click('Confirm password');
    const alert = await within(main()).findByRole('alert');
    expect(alert.textContent).toContain(
      'Could not change two-factor authentication: check the fields below.',
    );
    const password = within(section()).getByLabelText<HTMLInputElement>('Password');
    expect(password.getAttribute('aria-invalid')).toBe('true');
    expect(
      document.getElementById(password.getAttribute('aria-describedby') ?? '')?.textContent,
    ).toBe(WRONG_PASSWORD);
    expect(password.value).toBe('');
    expect(domHolds(PASSWORD)).toBe(false);
  });

  it('shows the 429 line when password confirmations are throttled', async () => {
    stubAccountFetch({
      'GET /v1/me': jsonResponse(signedInMe),
      [ENABLE]: passwordRequiredResponse(),
      [CONFIRM_PASSWORD]: problemResponse(tooManyRequestsProblem('/user/confirm-password')),
    });
    await openAccount();
    click('Turn on two-factor authentication');
    fill('Password', await passwordPrompt());
    click('Confirm password');
    const alert = await within(main()).findByRole('alert');
    expect(alert.textContent).toContain(
      'Could not change two-factor authentication: Too Many Requests. Wait a minute and try again.',
    );
    expect(domHolds(PASSWORD)).toBe(false);
  });

  it('shows a failed turn-on as the problem block and stays off', async () => {
    stubAccountFetch({
      'GET /v1/me': jsonResponse(signedInMe),
      [ENABLE]: problemResponse(serverErrorProblem('/user/two-factor-authentication')),
    });
    await openAccount();
    click('Turn on two-factor authentication');
    const alert = await within(main()).findByRole('alert');
    expect(alert.textContent).toContain(
      'Could not change two-factor authentication: Internal Server Error',
    );
    expect(alert.textContent).toContain('Request ID req-p4-server-0007');
    expect(within(section()).getByText('Two-factor authentication is off.')).toBeTruthy();
    expect(within(main()).queryByRole('img')).toBeNull();
  });

  it('never renders the QR markup itself (no dangerouslySetInnerHTML)', async () => {
    const sources = import.meta.glob<string>('./TwoFactor.tsx', {
      query: '?raw',
      import: 'default',
      eager: true,
    });
    const source = sources['./TwoFactor.tsx'];
    expect(source).toBeDefined();
    expect(source).not.toContain('dangerouslySetInnerHTML');
    stubAccountFetch(turnOnRoutes());
    await openAccount();
    await reachSetup();
    expect(main().querySelector('svg, rect')).toBeNull();
    expect(main().innerHTML).not.toContain('<rect');
  });
});

describe('two-factor on (T04 criterion 3)', () => {
  it('shows on with its two buttons and no codes', async () => {
    const calls = stubAccountFetch({ 'GET /v1/me': jsonResponse(twoFactorMe) });
    await openAccount();
    const region = section();
    expect(within(region).getByText('Two-factor authentication is on.')).toBeTruthy();
    expect(within(region).getByRole('button', { name: 'Show new recovery codes' })).toBeTruthy();
    expect(
      within(region).getByRole('button', { name: 'Turn off two-factor authentication' }),
    ).toBeTruthy();
    expect(within(region).queryByRole('heading', { level: 3 })).toBeNull();
    expect(within(region).queryByRole('list')).toBeNull();
    expect(keys(calls)).toEqual(['GET /v1/me']);
  });

  it('shows new recovery codes once: POST, then GET', async () => {
    const calls = stubAccountFetch({
      'GET /v1/me': jsonResponse(twoFactorMe),
      [REGENERATE]: emptyJsonResponse(200),
      [CODES]: jsonResponse(fakeNewRecoveryCodes),
    });
    await openAccount();
    click('Show new recovery codes');
    expect(await within(main()).findByRole('heading', { level: 3 })).toBeTruthy();
    const items = within(section())
      .getAllByRole('listitem')
      .map((item) => item.textContent);
    expect(items).toEqual([...fakeNewRecoveryCodes]);
    expect(keys(calls)).toEqual(['GET /v1/me', REGENERATE, CODES]);
    expect(within(section()).getByText('Two-factor authentication is on.')).toBeTruthy();
  });

  it('asks for the password when regenerating needs it, then shows the new codes', async () => {
    const calls = stubAccountFetch({
      'GET /v1/me': jsonResponse(twoFactorMe),
      [REGENERATE]: [passwordRequiredResponse(), emptyJsonResponse(200)],
      [CONFIRM_PASSWORD]: emptyJsonResponse(201),
      [CODES]: jsonResponse(fakeNewRecoveryCodes),
    });
    await openAccount();
    click('Show new recovery codes');
    fill('Password', await passwordPrompt());
    click('Confirm password');
    expect(await within(main()).findByText('fake-renewed-0004')).toBeTruthy();
    expect(keys(calls)).toEqual(['GET /v1/me', REGENERATE, CONFIRM_PASSWORD, REGENERATE, CODES]);
    expect(domHolds(PASSWORD)).toBe(false);
  });

  it('turns off after a password confirmation and reads off from the refetched session', async () => {
    const calls = stubAccountFetch({
      'GET /v1/me': [jsonResponse(twoFactorMe), jsonResponse(signedInMe)],
      [DISABLE]: [passwordRequiredResponse(), emptyJsonResponse(200)],
      [CONFIRM_PASSWORD]: emptyJsonResponse(201),
    });
    await openAccount();
    click('Turn off two-factor authentication');
    fill('Password', await passwordPrompt());
    click('Confirm password');
    expect(await within(main()).findByText('Two-factor authentication is off.')).toBeTruthy();
    expect(
      within(section()).getByRole('button', { name: 'Turn on two-factor authentication' }),
    ).toBeTruthy();
    expect(keys(calls)).toEqual(['GET /v1/me', DISABLE, CONFIRM_PASSWORD, DISABLE, 'GET /v1/me']);
    expect(domHolds(PASSWORD)).toBe(false);
  });

  it('shows a failed turn-off as the problem block and stays on', async () => {
    stubAccountFetch({
      'GET /v1/me': jsonResponse(twoFactorMe),
      [DISABLE]: problemResponse(serverErrorProblem('/user/two-factor-authentication')),
    });
    await openAccount();
    click('Turn off two-factor authentication');
    const alert = await within(main()).findByRole('alert');
    expect(alert.textContent).toContain(
      'Could not change two-factor authentication: Internal Server Error',
    );
    expect(within(section()).getByText('Two-factor authentication is on.')).toBeTruthy();
  });

  it('turns off from the codes view and drops the codes', async () => {
    stubAccountFetch({
      'GET /v1/me': [jsonResponse(twoFactorMe), jsonResponse(signedInMe)],
      [REGENERATE]: emptyJsonResponse(200),
      [CODES]: jsonResponse(fakeNewRecoveryCodes),
      [DISABLE]: emptyJsonResponse(200),
    });
    await openAccount();
    click('Show new recovery codes');
    await within(main()).findByText('fake-renewed-0004');
    click('Turn off two-factor authentication');
    expect(await within(main()).findByText('Two-factor authentication is off.')).toBeTruthy();
    for (const code of fakeNewRecoveryCodes) expect(domHolds(code)).toBe(false);
  });
});

describe('recovery codes and the setup key live in component state only (T04 criterion 4)', () => {
  it('does not show the codes again after leaving and re-entering /account', async () => {
    const calls = stubAccountFetch({
      ...turnOnRoutes(),
      'GET /v1/me': [jsonResponse(signedInMe), jsonResponse(twoFactorMe)],
    });
    await openAccount();
    await reachSetup();
    fill('Authentication code', CODE);
    click('Confirm');
    await within(main()).findByText('fake-recovery-0001');
    for (const secret of [...fakeRecoveryCodes, FAKE_SETUP_KEY, FAKE_QR_SVG, CODE, PASSWORD]) {
      expect(storageText()).not.toContain(secret);
      expect(cacheText()).not.toContain(secret);
    }
    expect(created.clients).toHaveLength(1);

    act(() => {
      navigate('/nowhere');
    });
    await screen.findByText('Page not found');
    act(() => {
      navigate('/account');
    });
    expect(await within(main()).findByText('Two-factor authentication is on.')).toBeTruthy();
    expect(within(section()).queryByRole('heading', { level: 3 })).toBeNull();
    for (const secret of [...fakeRecoveryCodes, FAKE_SETUP_KEY, CODE, PASSWORD]) {
      expect(domHolds(secret)).toBe(false);
      expect(storageText()).not.toContain(secret);
      expect(cacheText()).not.toContain(secret);
    }
    expect(window.localStorage.length + window.sessionStorage.length).toBe(0);
    // Re-entering asks for nothing secret again: only the session.
    expect(keys(calls).filter((key) => key.startsWith('GET /user/'))).toEqual([QR, SECRET, CODES]);
  });

  it('does not show the setup key or the QR code again after leaving mid-setup', async () => {
    stubAccountFetch(turnOnRoutes());
    await openAccount();
    await reachSetup();
    expect(domHolds(FAKE_SETUP_KEY)).toBe(true);
    expect(cacheText()).not.toContain(FAKE_SETUP_KEY);
    expect(cacheText()).not.toContain('svg');
    act(() => {
      navigate('/nowhere');
    });
    await screen.findByText('Page not found');
    act(() => {
      navigate('/account');
    });
    expect(
      await within(main()).findByRole('button', { name: 'Turn on two-factor authentication' }),
    ).toBeTruthy();
    expect(within(main()).queryByRole('img')).toBeNull();
    expect(domHolds(FAKE_SETUP_KEY)).toBe(false);
    expect(storageText()).not.toContain(FAKE_SETUP_KEY);
    expect(cacheText()).not.toContain(FAKE_SETUP_KEY);
  });
});

describe('two-factor accessibility and honesty (T04 criterion 5, D10, D14)', () => {
  function honest(container: HTMLElement, exempt: readonly string[] = []): void {
    expect(lightOnlyClasses(container)).toEqual(exempt);
    expect(shortControls(main())).toEqual([]);
    const text = main().textContent;
    for (const pattern of FORBIDDEN_WORDS) expect(text).not.toMatch(pattern);
    expect(text).not.toContain('2031');
    for (const input of Array.from(main().querySelectorAll('input'))) {
      const label = main().querySelector(`label[for="${input.id}"]`);
      expect(label?.textContent).not.toBe('');
    }
  }

  it('keeps every state dark-ready, 44 px and free of forbidden words; the QR box is the one light-only surface', async () => {
    vi.useFakeTimers({ toFake: ['Date'] });
    vi.setSystemTime(new Date('2031-02-03T04:05:06Z'));
    stubAccountFetch({
      ...turnOnRoutes(),
      [CONFIRM]: [
        problemResponse(
          accountValidationProblem('/user/confirmed-two-factor-authentication', {
            code: [WRONG_CODE],
          }),
        ),
        emptyJsonResponse(200),
      ],
      [REGENERATE]: problemResponse(tooManyRequestsProblem('/user/two-factor-recovery-codes')),
    });
    visit('/account');
    const { container } = render(<App />);
    await screen.findByText('Two-factor authentication is off.');
    honest(container);

    click('Turn on two-factor authentication');
    await passwordPrompt();
    honest(container);

    fill('Password', PASSWORD);
    click('Confirm password');
    const image = await within(main()).findByRole('img', { name: QR_ALT });
    // The QR wrapper is the only element allowed a light-only background (review F-P4-10).
    honest(container, ['div bg-white']);
    const lightOnly = Array.from(container.querySelectorAll('.bg-white')).filter(
      (element) => !element.className.split(/\s+/).some((name) => name.startsWith('dark:bg-')),
    );
    expect(lightOnly).toEqual([image.parentElement]);

    fill('Authentication code', CODE);
    click('Confirm');
    await within(main()).findByRole('alert');
    honest(container, ['div bg-white']);

    fill('Authentication code', CODE);
    click('Confirm');
    await within(main()).findByText('fake-recovery-0001');
    honest(container);

    click('Show new recovery codes');
    await within(main()).findByText(/Too Many Requests/);
    honest(container);
  });
});
