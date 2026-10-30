// Review tests for the write-answer decoders (Q1-i6 T03): a bad 2xx names the status the API sent,
// a problem answer passes the decoder untouched, and a refused answer never carries a signing secret.
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import {
  type ApiFailure,
  type ApiResult,
  createWebhook,
  onUnauthenticatedWrite,
  putAlert,
  putHolding,
  putNotifications,
  putWatchlistItem,
  resetPassword,
  testWebhook,
} from './client';
import {
  accountValidationProblem,
  clearXsrfCookie,
  jsonResponse,
  problemResponse,
  setXsrfCookie,
  stubAccountFetch,
  unauthenticatedProblem,
} from '../test/fixtures';

beforeEach(() => {
  setXsrfCookie();
});

afterEach(() => {
  clearXsrfCookie();
});

const KEY = 'base1-4:holo:unlimited:-';
const SEGMENT = 'base1-4%3Aholo%3Aunlimited%3A-';
const SENTINEL = 'fake0review0sentinel0000';

interface Route {
  readonly name: string;
  readonly route: string;
  readonly call: () => Promise<ApiResult<unknown>>;
}

const ROUTES: readonly Route[] = [
  {
    name: 'resetPassword',
    route: 'POST /reset-password',
    call: () =>
      resetPassword({
        token: 'fake-reset-token-0000',
        email: 'collector@example.com',
        password: 'fake-password-0000-ok',
        password_confirmation: 'fake-password-0000-ok',
      }),
  },
  {
    name: 'putWatchlistItem',
    route: `PUT /v1/watchlist/${SEGMENT}`,
    call: () => putWatchlistItem(KEY),
  },
  { name: 'putHolding', route: `PUT /v1/portfolio/${SEGMENT}`, call: () => putHolding(KEY, 3) },
  {
    name: 'createWebhook',
    route: 'POST /v1/webhooks',
    call: () => createWebhook({ kind: 'standard', url: 'https://hooks.example.com/pokeprices' }),
  },
  { name: 'testWebhook', route: 'POST /v1/webhooks/41/test', call: () => testWebhook(41) },
  {
    name: 'putNotifications',
    route: 'PUT /v1/me/notifications',
    call: () => putNotifications({ channel: 'email', digest: true }),
  },
  { name: 'putAlert', route: `PUT /v1/alerts/${SEGMENT}`, call: () => putAlert(KEY, 10) },
];

describe('a write answered 2xx with a body of the wrong shape', () => {
  // A problem block that says "HTTP 200" for an answer the API sent as 201 or 202 misreports the
  // status the user quotes when they report it (the 201/202 non-JSON answer already names its own).
  it.each([
    [
      'createWebhook',
      'POST /v1/webhooks',
      201,
      () => createWebhook({ kind: 'standard', url: 'https://hooks.example.com/pokeprices' }),
    ],
    ['testWebhook', 'POST /v1/webhooks/41/test', 202, () => testWebhook(41)],
  ] as const)('names the real status in the %s failure', async (_name, route, status, call) => {
    stubAccountFetch({ [route]: jsonResponse({ data: null }, { status }) });
    const result = await call();
    expect(result.ok).toBe(false);
    const failure = result as ApiFailure;
    expect(failure.status).toBe(status);
    expect(failure.title).toBe(
      `The PokéPrices API sent an unexpected answer (HTTP ${String(status)})`,
    );
  });

  it('keeps a signing secret out of the createWebhook failure it refuses', async () => {
    stubAccountFetch({
      'POST /v1/webhooks': jsonResponse(
        { data: { kind: 'slack', secret: SENTINEL, id: 41 } },
        { status: 201 },
      ),
    });
    const result = await createWebhook({
      kind: 'standard',
      url: 'https://hooks.example.com/pokeprices',
    });
    expect(result.ok).toBe(false);
    expect(JSON.stringify(result)).not.toContain(SENTINEL);
  });
});

describe('a problem answer to a decoded write', () => {
  it.each(ROUTES.map((one) => [one.name, one] as const))(
    'passes %s its 422 field errors untouched',
    async (_name, one) => {
      const problem = accountValidationProblem('/x', { field: [`The ${SENTINEL} is bad.`] });
      stubAccountFetch({ [one.route]: problemResponse(problem) });
      const result = await one.call();
      expect(result).toEqual({
        ok: false,
        status: 422,
        type: problem.type,
        title: 'Validation Failed',
        requestId: problem.request_id,
        problem,
      });
    },
  );

  it.each(ROUTES.map((one) => [one.name, one] as const))(
    'still tells the unauthenticated-write listeners when %s answers 401',
    async (_name, one) => {
      stubAccountFetch({ [one.route]: problemResponse(unauthenticatedProblem) });
      let told = 0;
      const stop = onUnauthenticatedWrite(() => {
        told += 1;
      });
      const result = await one.call();
      stop();
      expect(result.ok).toBe(false);
      expect((result as ApiFailure).status).toBe(401);
      expect(told).toBe(1);
    },
  );
});
