// The alert functions of the account layer (P5 T07, D9/D10): GET and PUT /v1/me/notifications,
// PUT and DELETE /v1/alerts/{variantKey} with the key as one segment encoded exactly once, the XSRF
// header on every write.
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { deleteAlert, getNotifications, putAlert, putNotifications } from './client';
import {
  XSRF_HEADER,
  alertBody,
  clearXsrfCookie,
  jsonResponse,
  notificationsBody,
  rawResponse,
  sentBody,
  setXsrfCookie,
  stubAccountFetch,
} from '../test/fixtures';

beforeEach(() => {
  clearXsrfCookie();
});

afterEach(() => {
  clearXsrfCookie();
});

function headersOf(init: RequestInit | undefined): Record<string, string> {
  return (init?.headers ?? {}) as Record<string, string>;
}

describe('the alert settings requests (P5 T07, D9)', () => {
  it('reads GET /v1/me/notifications with the account flags', async () => {
    const calls = stubAccountFetch({
      'GET /v1/me/notifications': jsonResponse(notificationsBody({ digest: true })),
    });
    expect(await getNotifications()).toEqual({
      ok: true,
      data: notificationsBody({ digest: true }),
    });
    expect(calls.map((call) => call.key)).toEqual(['GET /v1/me/notifications']);
    expect(calls[0]?.init?.credentials).toBe('same-origin');
    expect(calls[0]?.init?.redirect).toBe('error');
  });

  it('PUTs exactly channel and digest with the XSRF header', async () => {
    setXsrfCookie();
    const answer = notificationsBody({ channel: 'discord', digest: true, discord_available: true });
    const calls = stubAccountFetch({ 'PUT /v1/me/notifications': jsonResponse(answer) });
    expect(await putNotifications({ channel: 'discord', digest: true })).toEqual({
      ok: true,
      data: answer,
    });
    expect(calls.map((call) => call.key)).toEqual(['PUT /v1/me/notifications']);
    expect(sentBody(calls[0])).toEqual({ channel: 'discord', digest: true });
    expect(headersOf(calls[0]?.init)['X-XSRF-TOKEN']).toBe(XSRF_HEADER);
  });
});

describe('the per-card alert requests (P5 T07, D9)', () => {
  it('PUTs {"pct": n} to the encoded key and DELETEs it', async () => {
    setXsrfCookie();
    const calls = stubAccountFetch({
      'PUT /v1/alerts/base1-4%3Aholo%3Aunlimited%3A-': jsonResponse(
        alertBody('base1-4:holo:unlimited:-', 10),
      ),
      'DELETE /v1/alerts/base1-4%3Aholo%3Aunlimited%3A-': rawResponse(204, null),
    });
    expect(await putAlert('base1-4:holo:unlimited:-', 10)).toEqual({
      ok: true,
      data: alertBody('base1-4:holo:unlimited:-', 10),
    });
    expect(await deleteAlert('base1-4:holo:unlimited:-')).toEqual({ ok: true, data: undefined });
    expect(sentBody(calls[0])).toEqual({ pct: 10 });
    expect(calls[1]?.init?.body).toBeUndefined();
    expect(calls.every((call) => headersOf(call.init)['X-XSRF-TOKEN'] === XSRF_HEADER)).toBe(true);
  });

  it.each([
    ['ex10-?:holo:-:-', '/v1/alerts/ex10-%3F%3Aholo%3A-%3A-'],
    ['ex10-!:reverse:-:-', '/v1/alerts/ex10-!%3Areverse%3A-%3A-'],
    ['sv1-25%:holo:-:-', '/v1/alerts/sv1-25%25%3Aholo%3A-%3A-'],
    ['a/b:holo:-:-', '/v1/alerts/a%2Fb%3Aholo%3A-%3A-'],
  ])('encodes the key %s once as one segment', async (key, path) => {
    setXsrfCookie();
    const calls = stubAccountFetch({
      [`PUT ${path}`]: jsonResponse(alertBody(key, 5)),
      [`DELETE ${path}`]: rawResponse(204, null),
    });
    expect((await putAlert(key, 5)).ok).toBe(true);
    expect((await deleteAlert(key)).ok).toBe(true);
    expect(calls.map((call) => call.key)).toEqual([`PUT ${path}`, `DELETE ${path}`]);
  });

  it.each(['', '.', '..'])('never fetches for the key %j', async (key) => {
    const calls = stubAccountFetch({});
    expect((await putAlert(key, 5)).ok).toBe(false);
    expect((await deleteAlert(key)).ok).toBe(false);
    expect(calls).toHaveLength(0);
  });
});
