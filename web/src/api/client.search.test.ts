// The search answer's decoder with the headline price: a row carries variant and price (both null
// without a priced variant) and meta carries sort and ordered. Wrong kinds of any of them are the
// unexpected-answer failure, never data.
import { afterEach, describe, expect, it, vi } from 'vitest';
import {
  jsonResponse,
  pricedRow,
  searchBodyOf,
  searchPriceOf,
  stubFetch,
  unownExclamation,
  unownQuestion,
} from '../test/fixtures';
import { searchCards } from './client';

afterEach(() => {
  vi.unstubAllGlobals();
});

const priced = pricedRow(unownQuestion, 'holo:-:-', searchPriceOf());

const UNEXPECTED = {
  ok: false,
  status: 200,
  type: 'about:blank',
  title: 'The PokéPrices API sent an unexpected answer (HTTP 200)',
  requestId: null,
  problem: null,
};

async function decode(body: unknown): Promise<unknown> {
  stubFetch(() => jsonResponse(body));
  return searchCards({ q: 'unown' });
}

describe('the search decoder with prices', () => {
  it('passes a priced row and an unpriced row, in relevance and in a price order', async () => {
    for (const body of [
      searchBodyOf([priced, unownExclamation]),
      searchBodyOf([priced, unownExclamation], { sort: 'price_desc', ordered: 2 }),
      searchBodyOf([unownExclamation, priced], { sort: 'price_asc', ordered: 100, total: 4800 }),
      searchBodyOf([], { sort: 'price_desc', ordered: 0, total: 0 }),
    ]) {
      expect(await decode(body)).toEqual({ ok: true, data: body });
    }
  });

  it.each<readonly [string, unknown]>([
    ['a row without price', { ...unownQuestion, price: undefined }],
    ['a row without variant', { ...unownQuestion, variant: undefined }],
    ['a price that is a string', { ...priced, price: '70.81' }],
    ['a price whose close is a number', { ...priced, price: { ...priced.price, close: 70.81 } }],
    ['a price whose day is null', { ...priced, price: { ...priced.price, day: null } }],
    ['a price whose change is a number', { ...priced, price: { ...priced.price, change: 1.45 } }],
    ['a variant that is a string', { ...priced, variant: 'ex10-?:holo:-:-' }],
    ['a variant without a key', { ...priced, variant: { ...priced.variant, variant_key: null } }],
    [
      'a variant whose finish is a number',
      { ...priced, variant: { ...priced.variant, finish: 1 } },
    ],
  ])('refuses %s', async (_name, row) => {
    expect(await decode(searchBodyOf([row as typeof priced]))).toEqual(UNEXPECTED);
  });

  it.each<readonly [string, Record<string, unknown>]>([
    ['meta without sort', { sort: undefined }],
    ['an unknown sort', { sort: 'price' }],
    ['a sort that is not a string', { sort: 1 }],
    ['meta without ordered', { ordered: undefined }],
    ['ordered as a string', { ordered: '100' }],
    ['ordered as a boolean', { ordered: true }],
  ])('refuses %s', async (_name, meta) => {
    const body = searchBodyOf([priced]);
    expect(await decode({ ...body, meta: { ...body.meta, ...meta } })).toEqual(UNEXPECTED);
  });
});
