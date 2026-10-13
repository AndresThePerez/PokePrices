import { afterEach, describe, expect, it } from 'vitest';
import { getMe, resendVerification, signIn, signOut, verifyEmail } from './client';
import {
  clearXsrfCookie,
  csrfMismatchProblem,
  jsonResponse,
  problemResponse,
  rawResponse,
  setXsrfCookie,
  stubAccountFetch,
} from '../test/fixtures';

const FORM = { email: 'collector@example.com', password: 'fake-password-0000-ok' };

afterEach(() => {
  clearXsrfCookie();
});

describe('a server that answers 419 forever', () => {
  it('stops after one refresh and one retry when the csrf-cookie answer never sets a cookie', async () => {
    const calls = stubAccountFetch({
      'GET /sanctum/csrf-cookie': rawResponse(204, null),
      'POST /login': problemResponse(csrfMismatchProblem),
    });
    const result = await signIn(FORM);
    expect(result).toMatchObject({ ok: false, status: 419 });
    expect(calls.map((call) => call.key)).toEqual([
      'GET /sanctum/csrf-cookie',
      'POST /login',
      'GET /sanctum/csrf-cookie',
      'POST /login',
    ]);
    for (const call of calls.filter((entry) => entry.key === 'POST /login')) {
      expect(JSON.stringify(call.init?.headers)).not.toContain('X-XSRF-TOKEN');
    }
  });

  it('stops after one refresh and one retry when the csrf-cookie GET itself answers 419', async () => {
    setXsrfCookie();
    const calls = stubAccountFetch({
      'GET /sanctum/csrf-cookie': problemResponse(csrfMismatchProblem),
      'POST /logout': problemResponse(csrfMismatchProblem),
    });
    const result = await signOut();
    expect(result).toMatchObject({ ok: false, status: 419 });
    expect(calls.map((call) => call.key)).toEqual([
      'POST /logout',
      'GET /sanctum/csrf-cookie',
      'POST /logout',
    ]);
  });

  it('never refreshes the cookie for a GET that answers 419', async () => {
    const calls = stubAccountFetch({ 'GET /v1/me': problemResponse(csrfMismatchProblem) });
    const result = await getMe();
    expect(result).toMatchObject({ ok: false, status: 419 });
    expect(calls.map((call) => call.key)).toEqual(['GET /v1/me']);
  });
});

describe('answers that are not documented JSON never resolve ok', () => {
  it.each([
    ['an HTML page', rawResponse(200, '<!doctype html><html></html>', 'text/html')],
    ['plain text', rawResponse(200, 'OK', 'text/plain')],
    ['truncated JSON', rawResponse(200, '{"data":', 'application/json')],
  ])('fails GET /v1/me with %s', async (_name, answer) => {
    stubAccountFetch({ 'GET /v1/me': answer });
    expect(await getMe()).toMatchObject({ ok: false, status: 200, type: 'about:blank' });
  });

  it('fails a resend whose 202 body is an HTML page', async () => {
    setXsrfCookie();
    stubAccountFetch({
      'POST /email/verification-notification': rawResponse(202, '<html></html>', 'text/html'),
    });
    expect(await resendVerification()).toMatchObject({ ok: false, status: 202 });
  });

  it('fails a sign-in whose 200 JSON lacks a boolean two_factor', async () => {
    setXsrfCookie();
    stubAccountFetch({ 'POST /login': jsonResponse({ two_factor: 'yes' }) });
    expect(await signIn(FORM)).toMatchObject({ ok: false, status: 200 });
  });
});

describe('verifyEmail only reaches fetch with a link under /email/verify/', () => {
  it.each([
    'https://evil.example/email/verify/1/h?x',
    '/email/verify/../../v1/me',
    '/email/verify/1/h',
    '/v1/me?x',
    '/login',
  ])('refuses %s without a request', async (link) => {
    const calls = stubAccountFetch({});
    expect(await verifyEmail(link)).toMatchObject({ ok: false, status: 0 });
    expect(calls).toEqual([]);
  });

  it('sends the matching link once as a GET with the account flags', async () => {
    const calls = stubAccountFetch({
      'GET /email/verify/7/0a1b2c?expires=1&signature=ff': rawResponse(204, null),
    });
    expect(await verifyEmail('/email/verify/7/0a1b2c?expires=1&signature=ff')).toEqual({
      ok: true,
      data: undefined,
    });
    expect(calls).toHaveLength(1);
    expect(calls[0]?.init).toMatchObject({
      credentials: 'same-origin',
      referrerPolicy: 'same-origin',
      redirect: 'error',
    });
  });
});
