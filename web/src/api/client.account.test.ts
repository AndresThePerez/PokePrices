import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import {
  getMe,
  getMovers,
  requestPasswordReset,
  resendVerification,
  resetPassword,
  signIn,
  signOut,
  signUp,
  verifyEmail,
} from './client';
import {
  XSRF_HEADER,
  alreadySignedInProblem,
  clearXsrfCookie,
  csrfMismatchProblem,
  jsonResponse,
  moversBody,
  problemResponse,
  rawResponse,
  sentBody,
  setXsrfCookie,
  signedInMe,
  stubAccountFetch,
  stubFetch,
  unauthenticatedProblem,
} from '../test/fixtures';

const TYPED = 'fake-password-0000-ok';
const LINK = '/email/verify/7/0a1b2c?expires=1700000000&signature=fake0000';

beforeEach(() => {
  clearXsrfCookie();
});

afterEach(() => {
  clearXsrfCookie();
});

function headersOf(init: RequestInit | undefined): Record<string, string> {
  return (init?.headers ?? {}) as Record<string, string>;
}

describe('the account request layer (T03 criterion 1, D11)', () => {
  it('sends X-XSRF-TOKEN as the decoded cookie and skips the cookie request when it is present', async () => {
    setXsrfCookie();
    const calls = stubAccountFetch({ 'POST /login': jsonResponse({ two_factor: false }) });
    const result = await signIn({ email: 'collector@example.com', password: TYPED });
    expect(result).toEqual({ ok: true, data: { two_factor: false } });
    expect(calls.map((call) => call.key)).toEqual(['POST /login']);
    expect(headersOf(calls[0]?.init)['X-XSRF-TOKEN']).toBe(XSRF_HEADER);
  });

  it('asks for the CSRF cookie exactly once before the first write when the cookie is missing', async () => {
    const calls = stubAccountFetch({ 'POST /logout': rawResponse(204, null) });
    expect(await signOut()).toEqual({ ok: true, data: undefined });
    expect(calls.map((call) => call.key)).toEqual(['GET /sanctum/csrf-cookie', 'POST /logout']);
    expect(headersOf(calls[1]?.init)['X-XSRF-TOKEN']).toBe(XSRF_HEADER);
    // The cookie is now set: the next write asks for nothing else.
    await signOut();
    expect(calls.map((call) => call.key)).toEqual([
      'GET /sanctum/csrf-cookie',
      'POST /logout',
      'POST /logout',
    ]);
  });

  it('answers a 419 with one cookie refresh and exactly one retry', async () => {
    setXsrfCookie('fake-stale-0000');
    const calls = stubAccountFetch({
      'POST /login': [problemResponse(csrfMismatchProblem), jsonResponse({ two_factor: false })],
    });
    const result = await signIn({ email: 'collector@example.com', password: TYPED });
    expect(result).toEqual({ ok: true, data: { two_factor: false } });
    expect(calls.map((call) => call.key)).toEqual([
      'POST /login',
      'GET /sanctum/csrf-cookie',
      'POST /login',
    ]);
    expect(headersOf(calls[0]?.init)['X-XSRF-TOKEN']).toBe('fake-stale-0000');
    expect(headersOf(calls[2]?.init)['X-XSRF-TOKEN']).toBe(XSRF_HEADER);
  });

  it('fails on a second 419 without a third attempt', async () => {
    setXsrfCookie();
    const calls = stubAccountFetch({ 'POST /login': problemResponse(csrfMismatchProblem) });
    const result = await signIn({ email: 'collector@example.com', password: TYPED });
    expect(result).toMatchObject({ ok: false, status: 419, type: csrfMismatchProblem.type });
    expect(calls.map((call) => call.key)).toEqual([
      'POST /login',
      'GET /sanctum/csrf-cookie',
      'POST /login',
    ]);
  });

  it.each([
    ['204 without a body', rawResponse(204, null)],
    ['201 with an empty body', rawResponse(201, '')],
    ["201 with Fortify's empty JSON string", rawResponse(201, '""', 'application/json')],
    ['202 with an empty body', rawResponse(202, '')],
  ])('resolves %s as ok with no data', async (_name, answer) => {
    setXsrfCookie();
    stubAccountFetch({ 'POST /register': answer });
    const result = await signUp({
      invite_code: 'fake-invite-0000',
      email: 'newcomer@example.com',
      password: TYPED,
      password_confirmation: TYPED,
    });
    expect(result).toEqual({ ok: true, data: undefined });
  });

  it('treats a 2xx HTML page as a failure, never ok', async () => {
    setXsrfCookie();
    stubAccountFetch({
      'POST /login': rawResponse(200, '<!doctype html><title>PokéPrices</title>', 'text/html'),
    });
    const result = await signIn({ email: 'collector@example.com', password: TYPED });
    expect(result).toMatchObject({ ok: false, status: 200, type: 'about:blank', problem: null });
  });

  it('keeps a 409 already-signed-in problem as the failure the pages turn into their line', async () => {
    setXsrfCookie();
    stubAccountFetch({ 'POST /login': problemResponse(alreadySignedInProblem) });
    const result = await signIn({ email: 'collector@example.com', password: TYPED });
    expect(result).toMatchObject({
      ok: false,
      status: 409,
      type: alreadySignedInProblem.type,
      requestId: alreadySignedInProblem.request_id,
    });
  });

  it("surfaces Fortify's non-problem 423 with its real status", async () => {
    setXsrfCookie();
    stubAccountFetch({
      'POST /email/verification-notification': jsonResponse(
        { message: 'Password confirmation required.' },
        { status: 423 },
      ),
    });
    const result = await resendVerification();
    expect(result).toMatchObject({ ok: false, status: 423, type: 'about:blank', problem: null });
  });

  it('resolves a network failure, including a refused redirect, as status 0', async () => {
    setXsrfCookie();
    stubAccountFetch({ 'POST /login': new TypeError('Failed to fetch: redirect mode is error') });
    const result = await signIn({ email: 'collector@example.com', password: TYPED });
    expect(result).toMatchObject({ ok: false, status: 0, type: 'about:blank' });
  });

  it('sends same-origin credentials, a same-origin referrer and redirect error on every account request', async () => {
    const calls = stubAccountFetch({
      'GET /v1/me': jsonResponse(signedInMe),
      'POST /login': jsonResponse({ two_factor: false }),
      'POST /register': rawResponse(201, '""', 'application/json'),
      'POST /logout': rawResponse(204, null),
      'POST /forgot-password': jsonResponse({ message: 'fixed' }),
      'POST /reset-password': jsonResponse({ message: 'fixed' }),
      'POST /email/verification-notification': rawResponse(202, '""', 'application/json'),
      [`GET ${LINK}`]: rawResponse(204, null),
    });
    await getMe();
    await signIn({ email: 'collector@example.com', password: TYPED });
    await signUp({
      invite_code: 'fake-invite-0000',
      email: 'newcomer@example.com',
      password: TYPED,
      password_confirmation: TYPED,
    });
    await signOut();
    await requestPasswordReset({ email: 'collector@example.com' });
    await resetPassword({
      token: 'fake-reset-0000',
      email: 'collector@example.com',
      password: TYPED,
      password_confirmation: TYPED,
    });
    await resendVerification();
    await verifyEmail(LINK);
    expect(calls.map((call) => call.key)).toEqual([
      'GET /v1/me',
      'GET /sanctum/csrf-cookie',
      'POST /login',
      'POST /register',
      'POST /logout',
      'POST /forgot-password',
      'POST /reset-password',
      'POST /email/verification-notification',
      `GET ${LINK}`,
    ]);
    for (const call of calls) {
      expect({ key: call.key, credentials: call.init?.credentials }).toEqual({
        key: call.key,
        credentials: 'same-origin',
      });
      expect({ key: call.key, referrer: call.init?.referrerPolicy }).toEqual({
        key: call.key,
        referrer: 'same-origin',
      });
      expect({ key: call.key, redirect: call.init?.redirect }).toEqual({
        key: call.key,
        redirect: 'error',
      });
      expect(headersOf(call.init).Accept).toBe('application/json');
      const write = call.key.startsWith('POST ');
      expect(headersOf(call.init)['Content-Type']).toBe(
        write && call.key !== 'POST /logout' && call.key !== 'POST /email/verification-notification'
          ? 'application/json'
          : undefined,
      );
      if (write) expect(headersOf(call.init)['X-XSRF-TOKEN']).toBe(XSRF_HEADER);
    }
  });

  it('posts each form as JSON to its Fortify path', async () => {
    setXsrfCookie();
    const calls = stubAccountFetch({
      'POST /login': jsonResponse({ two_factor: false }),
      'POST /register': rawResponse(201, ''),
      'POST /forgot-password': jsonResponse({ message: 'fixed' }),
      'POST /reset-password': jsonResponse({ message: 'fixed' }),
    });
    await signIn({ email: 'collector@example.com', password: TYPED });
    await signUp({
      invite_code: 'fake-invite-0000',
      email: 'newcomer@example.com',
      password: TYPED,
      password_confirmation: TYPED,
    });
    await requestPasswordReset({ email: 'collector@example.com' });
    await resetPassword({
      token: 'fake-reset-0000',
      email: 'collector@example.com',
      password: TYPED,
      password_confirmation: TYPED,
    });
    expect(calls.map((call) => [call.key, sentBody(call)])).toEqual([
      ['POST /login', { email: 'collector@example.com', password: TYPED }],
      [
        'POST /register',
        {
          invite_code: 'fake-invite-0000',
          email: 'newcomer@example.com',
          password: TYPED,
          password_confirmation: TYPED,
        },
      ],
      ['POST /forgot-password', { email: 'collector@example.com' }],
      [
        'POST /reset-password',
        {
          token: 'fake-reset-0000',
          email: 'collector@example.com',
          password: TYPED,
          password_confirmation: TYPED,
        },
      ],
    ]);
  });

  it('reads GET /v1/me, and its 401 is a failure with that status', async () => {
    stubAccountFetch({
      'GET /v1/me': [jsonResponse(signedInMe), problemResponse(unauthenticatedProblem)],
    });
    expect(await getMe()).toEqual({ ok: true, data: signedInMe });
    expect(await getMe()).toMatchObject({
      ok: false,
      status: 401,
      type: unauthenticatedProblem.type,
    });
  });

  it('never asks for the CSRF cookie on a GET', async () => {
    const calls = stubAccountFetch({ 'GET /v1/me': jsonResponse(signedInMe) });
    await getMe();
    expect(calls.map((call) => call.key)).toEqual(['GET /v1/me']);
  });

  it('passes the abort signal through', async () => {
    const controller = new AbortController();
    const calls = stubAccountFetch({ 'GET /v1/me': jsonResponse(signedInMe) });
    await getMe({ signal: controller.signal });
    expect(calls[0]?.init?.signal).toBe(controller.signal);
  });

  it('fetches a verification link that matches the D10 pattern exactly as given', async () => {
    const calls = stubAccountFetch({ [`GET ${LINK}`]: rawResponse(204, null) });
    expect(await verifyEmail(LINK)).toEqual({ ok: true, data: undefined });
    expect(calls.map((call) => call.url)).toEqual([LINK]);
  });

  it.each([
    '',
    'x',
    `https://evil.example${LINK}`,
    `//evil.example${LINK}`,
    '/email/verify/7/0a1b2c',
    '/email/verify/7?expires=1',
    '/email/verify/7/0a1b2c/extra?expires=1',
    '/email/verify//0a1b2c?expires=1',
    '/user/two-factor-authentication?x=1',
    ' /email/verify/7/0a1b2c?expires=1',
    '/email/verify/../..?expires=1',
    '/email/verify/7/%2e%2e?expires=1',
    '/email/verify/7\\..\\..\\..\\user/x?expires=1',
  ])('never fetches the verification link %j', async (link) => {
    const calls = stubAccountFetch({});
    const result = await verifyEmail(link);
    expect(result).toMatchObject({ ok: false, status: 0, type: 'about:blank' });
    expect(calls).toHaveLength(0);
  });

  it('leaves the public request() init unchanged', async () => {
    const calls = stubFetch(() => jsonResponse(moversBody('up', [])));
    await getMovers('up', 5);
    expect(calls[0]?.init).toEqual({ headers: { Accept: 'application/json' } });
  });
});
