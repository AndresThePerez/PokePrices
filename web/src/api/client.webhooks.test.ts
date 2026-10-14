// The webhook functions of the account layer (P5 T04, D9/D10): same-origin /v1/webhooks paths,
// the numeric id as the path segment, the XSRF header on every write.
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { createWebhook, deleteWebhook, getWebhooks, testWebhook } from './client';
import {
  FAKE_SIGNING_SECRET,
  XSRF_HEADER,
  clearXsrfCookie,
  jsonResponse,
  rawResponse,
  sentBody,
  setXsrfCookie,
  stubAccountFetch,
  webhookCreatedBody,
  webhookOf,
  webhooksBody,
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

describe('the webhook requests (P5 T04, D9)', () => {
  it('reads GET /v1/webhooks with the account flags', async () => {
    const calls = stubAccountFetch({
      'GET /v1/webhooks': jsonResponse(webhooksBody([webhookOf()])),
    });
    const result = await getWebhooks();
    expect(result).toEqual({ ok: true, data: webhooksBody([webhookOf()]) });
    expect(calls.map((call) => call.key)).toEqual(['GET /v1/webhooks']);
    expect(calls[0]?.init?.credentials).toBe('same-origin');
    expect(calls[0]?.init?.redirect).toBe('error');
  });

  it('POSTs the form as JSON with the XSRF header and returns the 201 body', async () => {
    setXsrfCookie();
    const created = webhookCreatedBody(webhookOf(), FAKE_SIGNING_SECRET);
    const calls = stubAccountFetch({
      'POST /v1/webhooks': jsonResponse(created, { status: 201 }),
    });
    const form = {
      kind: 'standard',
      url: 'https://hooks.example.com/pokeprices',
      description: 'Home bot',
    } as const;
    expect(await createWebhook(form)).toEqual({ ok: true, data: created });
    expect(calls.map((call) => call.key)).toEqual(['POST /v1/webhooks']);
    expect(sentBody(calls[0])).toEqual(form);
    expect(headersOf(calls[0]?.init)['X-XSRF-TOKEN']).toBe(XSRF_HEADER);
  });

  it('addresses delete and test by the numeric id', async () => {
    setXsrfCookie();
    const calls = stubAccountFetch({
      'DELETE /v1/webhooks/41': rawResponse(204, null),
      'POST /v1/webhooks/41/test': jsonResponse(
        { data: { message_id: 'msg_01J0000000000000000000000A' } },
        { status: 202 },
      ),
    });
    expect(await deleteWebhook(41)).toEqual({ ok: true, data: undefined });
    expect(await testWebhook(41)).toEqual({
      ok: true,
      data: { data: { message_id: 'msg_01J0000000000000000000000A' } },
    });
    expect(calls.map((call) => call.key)).toEqual([
      'DELETE /v1/webhooks/41',
      'POST /v1/webhooks/41/test',
    ]);
    expect(calls.every((call) => headersOf(call.init)['X-XSRF-TOKEN'] === XSRF_HEADER)).toBe(true);
  });

  it.each([Number.NaN, -1, 1.5, Number.MAX_SAFE_INTEGER + 2])(
    'never fetches for the id %s',
    async (id) => {
      const calls = stubAccountFetch({});
      const deleted = await deleteWebhook(id);
      const tested = await testWebhook(id);
      expect(deleted.ok).toBe(false);
      expect(tested.ok).toBe(false);
      expect(calls).toHaveLength(0);
    },
  );
});
