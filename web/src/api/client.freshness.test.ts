// The freshness decoder and the days without prices: the answer's missing_days (ISO dates) and
// missing_days_count pass through when they are well shaped, an answer from before the fields is
// still an answer, and a wrong kind of either is the unexpected-answer failure, never data.
import { describe, expect, it } from 'vitest';
import { freshnessBody, jsonResponse, stubFetch } from '../test/fixtures';
import { getPriceFreshness } from './client';

const UNEXPECTED = {
  ok: false,
  status: 200,
  type: 'about:blank',
  title: 'The PokéPrices API sent an unexpected answer (HTTP 200)',
  requestId: null,
  problem: null,
};

/** The fixture's answer without the two fields, as the API answered before it had them. */
function withoutMissingDays(): unknown {
  const data: Record<string, unknown> = { ...freshnessBody().data };
  delete data.missing_days;
  delete data.missing_days_count;
  return { data };
}

describe('the freshness decoder and missing_days', () => {
  it.each([
    ['no day missing', freshnessBody()],
    [
      'two days missing',
      freshnessBody({ missing_days: ['2026-10-10', '2026-10-11'], missing_days_count: 2 }),
    ],
    [
      'more days missing than the answer lists',
      freshnessBody({ missing_days: ['2026-10-11'], missing_days_count: 40 }),
    ],
    ['an answer from before the fields', withoutMissingDays()],
  ] as const)('passes %s through as is', async (_name, body) => {
    const calls = stubFetch(() => jsonResponse(body));
    expect(await getPriceFreshness()).toEqual({ ok: true, data: body });
    expect(calls.map((call) => call.url)).toEqual(['/v1/prices/freshness']);
  });

  it.each([
    ['missing_days a string', { missing_days: '2026-10-10' }],
    ['missing_days null', { missing_days: null }],
    ['missing_days an object', { missing_days: { 0: '2026-10-10' } }],
    ['missing_days a list holding a number', { missing_days: ['2026-10-10', 20261011] }],
    ['missing_days a list holding null', { missing_days: [null] }],
    ['missing_days_count a string', { missing_days_count: '2' }],
    ['missing_days_count null', { missing_days_count: null }],
    ['missing_days_count a list', { missing_days_count: [2] }],
  ] as const)('refuses %s as the unexpected answer', async (_name, wrong) => {
    stubFetch(() => jsonResponse({ data: { ...freshnessBody().data, ...wrong } }));
    expect(await getPriceFreshness()).toEqual(UNEXPECTED);
  });
});
