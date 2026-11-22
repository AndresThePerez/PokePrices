// The card's small image on the list rows (movers, watchlist, portfolio): an optional string. A row
// with one or without one is a success that passes through unchanged; an image_small of any other
// kind is the unexpected answer, so a row never draws a picture from a value it cannot use.
import { afterEach, describe, expect, it } from 'vitest';
import {
  clearXsrfCookie,
  holdingOf,
  jsonResponse,
  moverOf,
  moversBody,
  portfolioBody,
  stubAccountFetch,
  stubFetch,
  watchItemOf,
  watchlistBody,
} from '../test/fixtures';
import { getMovers, getPortfolio, getWatchlist } from './client';

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

const IMAGE = 'https://images.example.com/ex5-17.png';
const NAMED = { name: 'Chimecho', set_name: 'Hidden Legends', number: '17' };

/** Each list read with a body whose one row carries `card`. */
function reads(card: unknown) {
  const row = { card } as never;
  return [
    {
      name: 'getMovers',
      body: moversBody('up', [moverOf(row)]),
      call: () => {
        stubFetch(() => jsonResponse(moversBody('up', [moverOf(row)])));
        return getMovers('up', 5);
      },
    },
    {
      name: 'getWatchlist',
      body: watchlistBody([watchItemOf(row)]),
      call: () => {
        stubAccountFetch({ 'GET /v1/watchlist': jsonResponse(watchlistBody([watchItemOf(row)])) });
        return getWatchlist();
      },
    },
    {
      name: 'getPortfolio',
      body: portfolioBody([holdingOf(row)]),
      call: () => {
        stubAccountFetch({ 'GET /v1/portfolio': jsonResponse(portfolioBody([holdingOf(row)])) });
        return getPortfolio();
      },
    },
  ];
}

describe('image_small on a list row', () => {
  it.each([
    ['with an image', { ...NAMED, image_small: IMAGE }],
    ['without one', NAMED],
  ])('passes a row %s through unchanged', async (_case, card) => {
    for (const read of reads(card)) {
      expect(await read.call(), read.name).toEqual({ ok: true, data: read.body });
    }
  });

  it.each([
    ['a number', 42],
    ['null', null],
    ['an object', { url: IMAGE }],
    ['a list', [IMAGE]],
    ['a boolean', true],
  ])('refuses an image_small that is %s as the unexpected answer', async (_case, image) => {
    for (const read of reads({ ...NAMED, image_small: image })) {
      expect(await read.call(), read.name).toEqual(UNEXPECTED);
    }
  });
});
