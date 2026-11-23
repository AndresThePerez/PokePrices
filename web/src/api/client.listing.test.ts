// The card decoder and the lowest listing: a price block's lowest_listing (a decimal string or
// null) and thin_market (a boolean), and a variant's optional listing (the lowest listing of a
// variant without a close), are accepted when they are there and of their kind, and refused when
// they are of another kind.
import { describe, expect, it } from 'vitest';
import { getCard } from './client';
import { cardWithPrices, jsonResponse, risingPrice, stubFetch } from '../test/fixtures';

const LISTING = {
  source: 'tcgplayer',
  feed: 'tcgcsv',
  currency: 'USD',
  cadence: 'daily',
  lowest_listing: '100000.00',
  day: '2026-10-13',
  as_of: '2026-10-13T20:05:00Z',
};

/** A one-variant card whose variant carries the given price and, when given, listing. */
function cardOf(price: unknown, listing?: unknown): unknown {
  const body = cardWithPrices(risingPrice);
  const variant = body.data.variants[0];
  return {
    ...body,
    data: {
      ...body.data,
      variants: [{ ...variant, price, ...(listing === undefined ? {} : { listing }) }],
    },
  };
}

async function decodes(body: unknown): Promise<boolean> {
  stubFetch(() => jsonResponse(body));
  return (await getCard('ex10-?')).ok;
}

describe('getCard and the lowest listing', () => {
  it('accepts a price with a lowest listing and a thin-market flag, and one with a null listing', async () => {
    expect(
      await decodes(cardOf({ ...risingPrice, lowest_listing: '2500.00', thin_market: true })),
    ).toBe(true);
    expect(
      await decodes(cardOf({ ...risingPrice, lowest_listing: null, thin_market: false })),
    ).toBe(true);
  });

  it('accepts a price without the two fields, as an answer from before them', async () => {
    expect(await decodes(cardOf(risingPrice))).toBe(true);
  });

  it('refuses a lowest listing or a thin-market flag of another kind', async () => {
    for (const price of [
      { ...risingPrice, lowest_listing: 2500, thin_market: true },
      { ...risingPrice, lowest_listing: '2500.00', thin_market: 'yes' },
      { ...risingPrice, lowest_listing: '2500.00', thin_market: null },
      { ...risingPrice, lowest_listing: false, thin_market: false },
    ]) {
      expect(await decodes(cardOf(price)), JSON.stringify(price)).toBe(false);
    }
  });

  it('accepts a variant without a close that carries a listing, and one without it', async () => {
    expect(await decodes(cardOf(null, LISTING))).toBe(true);
    expect(await decodes(cardOf(null))).toBe(true);
  });

  it('refuses a listing that is not an object, or whose amount, day or marker is of another kind', async () => {
    for (const listing of [
      null,
      '100000.00',
      { ...LISTING, lowest_listing: 100000 },
      { ...LISTING, lowest_listing: null },
      { ...LISTING, day: 20261013 },
      { ...LISTING, as_of: null },
      { ...LISTING, source: 1 },
    ]) {
      expect(await decodes(cardOf(null, listing)), JSON.stringify(listing)).toBe(false);
    }
  });
});
