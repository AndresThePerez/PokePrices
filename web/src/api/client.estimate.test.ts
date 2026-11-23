// The portfolio estimate's fields in the decoders: GET /v1/portfolio's meta carries the day's
// change in dollars and percent and the count of holdings without a previous close; GET /v1/me
// carries the same figures as data.portfolio. Well-shaped answers pass as the same value; a field
// of the wrong kind is the unexpected-answer failure.
import { afterEach, describe, expect, it } from 'vitest';
import {
  clearXsrfCookie,
  estimateOf,
  jsonResponse,
  portfolioBody,
  signedInMe,
  stubAccountFetch,
} from '../test/fixtures';
import { getMe, getPortfolio } from './client';

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

describe('the portfolio estimate in GET /v1/portfolio', () => {
  it('passes the change, its percent and the no-previous-close count, null where there is none', async () => {
    const worked = portfolioBody();
    const none = portfolioBody([], {
      total_value: null,
      change: null,
      change_pct: null,
      no_previous_close: 0,
      priced: 0,
      unpriced: 0,
      first_day: null,
      last_day: null,
    });
    for (const body of [worked, none]) {
      stubAccountFetch({ 'GET /v1/portfolio': jsonResponse(body) });
      expect(await getPortfolio()).toEqual({ ok: true, data: body });
    }
    expect([worked.meta.change, worked.meta.change_pct, worked.meta.no_previous_close]).toEqual([
      '16.93',
      '1.64',
      0,
    ]);
  });

  it.each([
    ['change as a number', { change: 16.93 }],
    ['change_pct as a number', { change_pct: 1.64 }],
    ['no_previous_close as a string', { no_previous_close: '0' }],
    ['no_previous_close missing', { no_previous_close: undefined }],
    ['change missing', { change: undefined }],
  ])('refuses %s', async (_name, meta) => {
    const body = portfolioBody();
    stubAccountFetch({
      'GET /v1/portfolio': jsonResponse({ ...body, meta: { ...body.meta, ...meta } }),
    });
    expect(await getPortfolio()).toEqual(UNEXPECTED);
  });
});

describe('the portfolio estimate in GET /v1/me', () => {
  it('passes an account with its estimate, and one without it', async () => {
    const withEstimate = { data: { ...signedInMe.data, portfolio: estimateOf() } };
    const bare: Record<string, unknown> = { ...signedInMe.data };
    delete bare['portfolio'];
    for (const body of [withEstimate, { data: bare }]) {
      stubAccountFetch({ 'GET /v1/me': jsonResponse(body) });
      expect(await getMe()).toEqual({ ok: true, data: body });
    }
  });

  it.each([
    ['the estimate as a string', '1047.93'],
    ['the estimate null', null],
    ['total_value as a number', estimateOf({ total_value: 1047.93 as unknown as string })],
    ['change_pct as a number', estimateOf({ change_pct: 1.64 as unknown as string })],
    ['count as a string', estimateOf({ count: '3' as unknown as number })],
    ['no_previous_close missing', estimateOf({ no_previous_close: undefined })],
    ['last_day as a number', estimateOf({ last_day: 20261012 as unknown as string })],
    ['source missing', estimateOf({ source: undefined })],
  ])('refuses %s', async (_name, portfolio) => {
    stubAccountFetch({ 'GET /v1/me': jsonResponse({ data: { ...signedInMe.data, portfolio } }) });
    expect(await getMe()).toEqual(UNEXPECTED);
  });
});
