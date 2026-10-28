// The envelope decoder (Q1-i2 T01, c1): a 200 whose top level is not the shape a page dereferences
// is the existing unexpected-answer failure, never data. And a 401 on an account write tells the
// one registered listener (c2); a read or any other status does not.
import { afterEach, describe, expect, it } from 'vitest';
import {
  cardBody,
  cardWithPrices,
  clearXsrfCookie,
  historyBody,
  holdingOf,
  jsonResponse,
  moverOf,
  moversBody,
  notificationsBody,
  pointOf,
  portfolioBody,
  pricedCardBody,
  problemResponse,
  rawResponse,
  risingPrice,
  searchPage,
  seriesOf,
  serverErrorProblem,
  setXsrfCookie,
  signedInMe,
  stubAccountFetch,
  stubFetch,
  unauthenticatedProblem,
  watchItemOf,
  watchlistBody,
  webhookOf,
  webhooksBody,
  type VariantPrice,
} from '../test/fixtures';
import {
  type ApiResult,
  deleteWatchlistItem,
  getCard,
  getCardPrices,
  getMe,
  getMovers,
  getNotifications,
  getPortfolio,
  getWatchlist,
  getWebhooks,
  onUnauthenticatedWrite,
  putNotifications,
  searchCards,
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

// Q1-i4 T06: each read also decodes the fields its page reads without a guard. A field of the wrong
// type or a missing one is the unexpected answer; a well-shaped answer still passes through as is.
describe('the field decoders on the nine reads (C6)', () => {
  const okMover = moversBody('up', [moverOf()]);
  const okWatch = watchlistBody([watchItemOf()]);
  const okHolding = portfolioBody([holdingOf()]);
  const okHooks = webhooksBody([webhookOf()]);

  const PUBLIC_FIELDS: readonly (readonly [string, Call, unknown])[] = [
    ['getMovers change as a number', () => getMovers('up', 5), withRow(okMover, { change: 5 })],
    ['getMovers card without name', () => getMovers('up', 5), withRow(okMover, { card: {} })],
    ['getMovers rank as a string', () => getMovers('up', 5), withRow(okMover, { rank: '1' })],
    [
      'getMovers meta without min_previous_close',
      () => getMovers('up', 5),
      { ...okMover, meta: { ...okMover.meta, min_previous_close: undefined } },
    ],
    [
      'getCard variants not a list',
      () => getCard('ex10-28'),
      { ...cardBody, data: { ...cardBody.data, variants: {} } },
    ],
    [
      'getCard set without a name',
      () => getCard('ex10-28'),
      { ...cardBody, data: { ...cardBody.data, set: {} } },
    ],
    [
      'getCard a variant price close as a number',
      () => getCard('ex10-28'),
      cardWithPrices({ ...risingPrice, close: 1 } as unknown as VariantPrice),
    ],
    ['getCard meta without degraded', () => getCard('ex10-28'), { ...cardBody, meta: {} }],
    [
      'searchCards image_small missing',
      () => searchCards({ q: 'unown' }),
      { ...searchPage, data: [{ ...searchPage.data[0], image_small: undefined }] },
    ],
    [
      'searchCards meta pages as a string',
      () => searchCards({ q: 'unown' }),
      { ...searchPage, meta: { ...searchPage.meta, pages: '1' } },
    ],
    [
      'getCardPrices a point of a held series that is not an object',
      () => getCardPrices('ex10-28', '1M'),
      {
        ...historyBody([]),
        data: [
          { ...seriesOf('ex10-28:holo:-:-', [pointOf('2026-10-12', '1.00')]), points: [null] },
        ],
      },
    ],
  ];

  it.each(PUBLIC_FIELDS)('%s is the unexpected answer', async (_name, call, body) => {
    stubFetch(() => jsonResponse(body));
    expect(await call()).toEqual(UNEXPECTED);
  });

  it('getCardPrices still passes an answer of a shape the page reads as nothing held', async () => {
    stubFetch(() => jsonResponse(cardBody));
    expect(await getCardPrices('ex10-28', '1M')).toEqual({ ok: true, data: cardBody });
  });

  const ACCOUNT_FIELDS: readonly (readonly [string, string, Call, unknown])[] = [
    [
      'getMe without data.email',
      'GET /v1/me',
      () => getMe(),
      { data: { email_verified: true, two_factor: false, read_only: false } },
    ],
    [
      'getMe read_only as a string',
      'GET /v1/me',
      () => getMe(),
      { data: { ...signedInMe.data, read_only: 'no' } },
    ],
    [
      'getWatchlist alert_pct as a string',
      'GET /v1/watchlist',
      () => getWatchlist(),
      withRow(okWatch, { alert_pct: '5' }),
    ],
    [
      'getWatchlist meta without degraded',
      'GET /v1/watchlist',
      () => getWatchlist(),
      { ...okWatch, meta: {} },
    ],
    [
      'getPortfolio quantity missing',
      'GET /v1/portfolio',
      () => getPortfolio(),
      withRow(okHolding, { quantity: undefined }),
    ],
    [
      'getPortfolio total_value as a number',
      'GET /v1/portfolio',
      () => getPortfolio(),
      { ...okHolding, meta: { ...okHolding.meta, total_value: 1047.93 } },
    ],
    [
      'getWebhooks a last delivery of an unknown status',
      'GET /v1/webhooks',
      () => getWebhooks(),
      withRow(okHooks, { last_delivery: { status: 'lost', at: '2026-10-12T20:05:44Z' } }),
    ],
    [
      'getWebhooks id as a string',
      'GET /v1/webhooks',
      () => getWebhooks(),
      withRow(okHooks, { id: '1' }),
    ],
    [
      'getNotifications channel outside the two',
      'GET /v1/me/notifications',
      () => getNotifications(),
      { data: { ...notificationsBody().data, channel: 'sms' } },
    ],
  ];

  it.each(ACCOUNT_FIELDS)('%s is the unexpected answer', async (_name, key, call, body) => {
    stubAccountFetch({ [key]: jsonResponse(body) });
    expect(await call()).toEqual(UNEXPECTED);
  });

  it.each([
    ['getMe', 'GET /v1/me', () => getMe(), signedInMe],
    ['getWatchlist with its demo rows', 'GET /v1/watchlist', () => getWatchlist(), watchlistBody()],
    [
      'getPortfolio with its worked rows',
      'GET /v1/portfolio',
      () => getPortfolio(),
      portfolioBody(),
    ],
    ['getWebhooks with a row', 'GET /v1/webhooks', () => getWebhooks(), okHooks],
  ] as const)('%s passes a well-shaped answer through as is', async (_name, key, call, body) => {
    stubAccountFetch({ [key]: jsonResponse(body) });
    expect(await call()).toEqual({ ok: true, data: body });
  });

  it.each([
    ['searchCards', () => searchCards({ q: 'unown' }), searchPage],
    ['getCard with prices', () => getCard('ex10-28'), pricedCardBody],
    ['getMovers with a row', () => getMovers('up', 5), okMover],
    [
      'getCardPrices with a held series',
      () => getCardPrices('ex10-28', '1M'),
      historyBody([seriesOf('ex10-28:holo:-:-', [pointOf('2026-10-12', '1.00')])]),
    ],
  ] as const)('%s passes a well-shaped answer through as is', async (_name, call, body) => {
    stubFetch(() => jsonResponse(body));
    expect(await call()).toEqual({ ok: true, data: body });
  });
});

/** The body with its first row's fields replaced. */
function withRow(body: { readonly data: readonly object[] }, fields: Record<string, unknown>) {
  return { ...body, data: [{ ...body.data[0], ...fields }] };
}
