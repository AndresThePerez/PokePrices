// The envelope decoder (Q1-i2 T01, c1): a 200 whose top level is not the shape a page dereferences
// is the existing unexpected-answer failure, never data. And a 401 on an account write tells the
// one registered listener (c2); a read or any other status does not.
import { afterEach, describe, expect, it } from 'vitest';
import {
  cardBody,
  clearXsrfCookie,
  jsonResponse,
  moversBody,
  notificationsBody,
  portfolioBody,
  problemResponse,
  rawResponse,
  serverErrorProblem,
  setXsrfCookie,
  stubAccountFetch,
  stubFetch,
  unauthenticatedProblem,
  watchlistBody,
  webhooksBody,
} from '../test/fixtures';
import {
  type ApiResult,
  deleteWatchlistItem,
  getCard,
  getMovers,
  getNotifications,
  getPortfolio,
  getWatchlist,
  getWebhooks,
  onUnauthenticatedWrite,
  putNotifications,
  signOut,
} from './client';

afterEach(() => {
  clearXsrfCookie();
});

const UNEXPECTED = {
  ok: false,
  status: 200,
  type: 'about:blank',
  title: 'The PokéPrices API sent an unexpected answer (HTTP 200)',
  requestId: null,
  problem: null,
};

/** Wrong shapes every envelope refuses: no data, no meta, the wrong kind of either. */
const LIST_MISSES: readonly (readonly [string, unknown])[] = [
  ['an empty object', {}],
  ['data only', { data: [] }],
  ['data a string', { data: 'x', meta: {} }],
  ['meta not an object', { data: [], meta: [] }],
  ['meta null', { data: [], meta: null }],
  ['a bare array', []],
  ['null', null],
  ['a string', 'ok'],
];

type Call = () => Promise<ApiResult<unknown>>;

const PUBLIC: readonly (readonly [string, Call, unknown])[] = [
  ['getMovers', () => getMovers('up', 5), moversBody('up', [])],
  ['getCard', () => getCard('ex10-?'), cardBody],
];

const ACCOUNT: readonly (readonly [string, string, Call, unknown])[] = [
  ['getWatchlist', 'GET /v1/watchlist', () => getWatchlist(), watchlistBody()],
  ['getPortfolio', 'GET /v1/portfolio', () => getPortfolio(), portfolioBody()],
  ['getWebhooks', 'GET /v1/webhooks', () => getWebhooks(), webhooksBody()],
];

describe('the envelope decoder on the public reads', () => {
  it.each(PUBLIC)('%s passes a well-shaped answer through', async (_name, call, body) => {
    stubFetch(() => jsonResponse(body));
    expect(await call()).toEqual({ ok: true, data: body });
  });

  for (const [name, call] of PUBLIC) {
    it.each(LIST_MISSES)(`${name} refuses %s as the unexpected answer`, async (_shape, body) => {
      stubFetch(() => jsonResponse(body));
      expect(await call()).toEqual(UNEXPECTED);
    });
  }

  it('getCard refuses a data array where the card object belongs', async () => {
    stubFetch(() => jsonResponse({ data: [], meta: { degraded: false } }));
    expect(await getCard('ex10-?')).toEqual(UNEXPECTED);
  });

  it('getMovers refuses a data object where the list belongs', async () => {
    stubFetch(() => jsonResponse({ data: {}, meta: moversBody('up', []).meta }));
    expect(await getMovers('up', 5)).toEqual(UNEXPECTED);
  });
});

describe('the envelope decoder on the account reads', () => {
  it.each(ACCOUNT)('%s passes a well-shaped answer through', async (_name, key, call, body) => {
    stubAccountFetch({ [key]: jsonResponse(body) });
    expect(await call()).toEqual({ ok: true, data: body });
  });

  for (const [name, key, call] of ACCOUNT) {
    it.each(LIST_MISSES)(`${name} refuses %s as the unexpected answer`, async (_shape, body) => {
      stubAccountFetch({ [key]: jsonResponse(body) });
      expect(await call()).toEqual(UNEXPECTED);
    });

    it(`${name} refuses an empty 200 as the unexpected answer`, async () => {
      stubAccountFetch({ [key]: rawResponse(200, null) });
      expect(await call()).toEqual(UNEXPECTED);
    });
  }

  it('getNotifications passes its object through and refuses a missing or listed data', async () => {
    stubAccountFetch({
      'GET /v1/me/notifications': [
        jsonResponse(notificationsBody()),
        jsonResponse({}),
        jsonResponse({ data: [] }),
        jsonResponse({ data: null }),
      ],
    });
    expect(await getNotifications()).toEqual({ ok: true, data: notificationsBody() });
    expect(await getNotifications()).toEqual(UNEXPECTED);
    expect(await getNotifications()).toEqual(UNEXPECTED);
    expect(await getNotifications()).toEqual(UNEXPECTED);
  });

  it('leaves a failure as it was: a problem stays the problem', async () => {
    stubAccountFetch({
      'GET /v1/watchlist': problemResponse(serverErrorProblem('/v1/watchlist')),
    });
    const result = await getWatchlist();
    expect(result.ok).toBe(false);
    expect(result.ok ? 0 : result.status).toBe(500);
  });
});

describe('a 401 on an account write tells the registered listener (c2)', () => {
  const unauthenticated = (instance: string): Response =>
    problemResponse({ ...unauthenticatedProblem, instance });

  it('hears a DELETE, a PUT and a POST that answer 401, once each', async () => {
    setXsrfCookie();
    stubAccountFetch({
      'DELETE /v1/watchlist/base1-4%3Aholo%3A1st%3A-': unauthenticated('/v1/watchlist'),
      'PUT /v1/me/notifications': unauthenticated('/v1/me/notifications'),
      'POST /logout': unauthenticated('/logout'),
    });
    let heard = 0;
    const stop = onUnauthenticatedWrite(() => {
      heard += 1;
    });
    try {
      expect((await deleteWatchlistItem('base1-4:holo:1st:-')).ok).toBe(false);
      expect(heard).toBe(1);
      await putNotifications({ channel: 'email', digest: false });
      expect(heard).toBe(2);
      await signOut();
      expect(heard).toBe(3);
    } finally {
      stop();
    }
  });

  it('stays quiet for a 401 read, a non-401 write and once unsubscribed', async () => {
    setXsrfCookie();
    stubAccountFetch({
      'GET /v1/watchlist': unauthenticated('/v1/watchlist'),
      'POST /logout': [problemResponse(serverErrorProblem('/logout')), unauthenticated('/logout')],
    });
    let heard = 0;
    const stop = onUnauthenticatedWrite(() => {
      heard += 1;
    });
    await getWatchlist();
    await signOut();
    expect(heard).toBe(0);
    stop();
    await signOut();
    expect(heard).toBe(0);
  });
});
