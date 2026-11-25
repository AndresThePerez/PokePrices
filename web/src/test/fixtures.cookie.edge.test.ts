// The signed-in fixture and the security cookie: a 2xx first GET /v1/me answer makes the faked
// browser hold the token cookie from the start, a 401 or a function answer holds none, and nothing
// the fixture set outlives its test. The tests of one describe run in order on purpose: a test
// that fakes a signed-out session follows the one that held the cookie, in the same file (jsdom's
// document.cookie survives from one test to the next).
import { describe, expect, it } from 'vitest';
import {
  XSRF_COOKIE,
  clearXsrfCookie,
  holdSessionCookieFor,
  jsonResponse,
  problemResponse,
  rawResponse,
  serverErrorProblem,
  signedInMe,
  stubAccountFetch,
  unauthenticatedProblem,
} from './fixtures';

function holdsCookie(): boolean {
  return /(?:^|;\s*)XSRF-TOKEN=[^;]+/.test(document.cookie);
}

describe('a 2xx first answer holds the cookie, and a following signed-out test sees none', () => {
  it('holds the cookie before any request, and the csrf route still answers 204 over it', async () => {
    expect(holdsCookie()).toBe(false);
    const calls = stubAccountFetch({ 'GET /v1/me': jsonResponse(signedInMe) });
    expect(holdsCookie()).toBe(true);
    expect(calls).toHaveLength(0);
    const answer = await fetch('/sanctum/csrf-cookie');
    expect(answer.status).toBe(204);
    expect(holdsCookie()).toBe(true);
    expect(document.cookie).toContain(`XSRF-TOKEN=${XSRF_COOKIE}`);
  });

  it('a signed-out 401 test that follows sees no cookie', () => {
    expect(holdsCookie()).toBe(false);
    stubAccountFetch({ 'GET /v1/me': problemResponse(unauthenticatedProblem) });
    expect(holdsCookie()).toBe(false);
  });
});

describe('a first answer that is not a 2xx Response holds none', () => {
  it('a 401, a 500 and a function answer set no cookie, whatever follows', () => {
    stubAccountFetch({ 'GET /v1/me': problemResponse(unauthenticatedProblem) });
    stubAccountFetch({ 'GET /v1/me': problemResponse(serverErrorProblem('/v1/me')) });
    stubAccountFetch({ 'GET /v1/me': () => jsonResponse(signedInMe) });
    expect(holdsCookie()).toBe(false);
  });

  it('a [401, 200] sequence sets none: only the first answer decides', async () => {
    stubAccountFetch({
      'GET /v1/me': [problemResponse(unauthenticatedProblem), jsonResponse(signedInMe)],
    });
    expect(holdsCookie()).toBe(false);
    expect((await fetch('/v1/me')).status).toBe(401);
    expect((await fetch('/v1/me')).status).toBe(200);
    expect(holdsCookie()).toBe(false);
  });

  it('a [200, 401] sequence sets it, and a 204 first answer counts as a 2xx', () => {
    stubAccountFetch({
      'GET /v1/me': [jsonResponse(signedInMe), problemResponse(unauthenticatedProblem)],
    });
    expect(holdsCookie()).toBe(true);
    clearXsrfCookie();
    stubAccountFetch({ 'GET /v1/me': rawResponse(204, null) });
    expect(holdsCookie()).toBe(true);
  });

  it('the test after it starts with no cookie', () => {
    expect(holdsCookie()).toBe(false);
  });
});

describe('the cookie of the csrf route is cleared with its test, and two stubs leave nothing', () => {
  it('two stubs in one test, the second one signed out, still end with one clean slate', async () => {
    stubAccountFetch({ 'GET /v1/me': jsonResponse(signedInMe) });
    stubAccountFetch({ 'GET /v1/me': problemResponse(unauthenticatedProblem) });
    await fetch('/sanctum/csrf-cookie');
    expect(holdsCookie()).toBe(true);
  });

  it('leaves no cookie after two stubs', () => {
    expect(holdsCookie()).toBe(false);
  });
});

describe('holdSessionCookieFor, for a stub of its own', () => {
  it('holds for a function or a 2xx Response, and not for a 401, a 500 or nothing', () => {
    holdSessionCookieFor(problemResponse(unauthenticatedProblem));
    holdSessionCookieFor(problemResponse(serverErrorProblem('/v1/me')));
    holdSessionCookieFor(undefined);
    holdSessionCookieFor(new TypeError('Failed to fetch'));
    expect(holdsCookie()).toBe(false);
    holdSessionCookieFor(() => jsonResponse(signedInMe));
    expect(holdsCookie()).toBe(true);
  });

  it('cleared it again for the next test', () => {
    expect(holdsCookie()).toBe(false);
  });
});
