// Adversarial review of Q1-i4 T06 (round 1): the field decoders must let every documented null and
// empty list through as the same value (a page shows "No price in the collected snapshots" or its
// empty state for those, never the problem block), and the one pipeline keeps the old order of a
// 419 retry, the cancelled-request rule and the 401 listener on the account side only.
import { afterEach, describe, expect, it } from 'vitest';
import {
  cardWithPrices,
  clearXsrfCookie,
  historyBody,
  holdingOf,
  jsonResponse,
  moverOf,
  moversBody,
  portfolioBody,
  problemResponse,
  rawResponse,
  risingPrice,
  seriesOf,
  serverErrorProblem,
  setXsrfCookie,
  stubAccountFetch,
  stubFetch,
  watchItemOf,
  watchlistBody,
  webhookOf,
  webhooksBody,
} from '../test/fixtures';
import {
  deleteWatchlistItem,
  getCard,
  getCardPrices,
  getMovers,
  getPortfolio,
  getWatchlist,
  getWebhooks,
  onUnauthenticatedWrite,
  signOut,
} from './client';

afterEach(() => {
  clearXsrfCookie();
});

describe('a documented null or empty list is not an unexpected answer', () => {
  it('movers: a row whose card is null, no rows and a null day all pass through as the same value', async () => {
    const degradedRow = moversBody('up', [moverOf({ card: null })], { degraded: true });
    stubFetch(() => jsonResponse(degradedRow));
    expect(await getMovers('up', 5)).toEqual({ ok: true, data: degradedRow });
    const empty = moversBody('down', [], { day: null, as_of: null });
    stubFetch(() => jsonResponse(empty));
    expect(await getMovers('down', 5)).toEqual({ ok: true, data: empty });
  });

  it('a card with no variants, a null rarity, a null seed and a variant with no price or no previous close passes', async () => {
    const unpriced = cardWithPrices(null, {
      ...risingPrice,
      previous_close: null,
      previous_day: null,
      change: null,
      change_pct: null,
    });
    const body = {
      ...unpriced,
      data: {
        ...unpriced.data,
        rarity: null,
        catalog: { seed_ref: null, seeded_at: null, status: 'unverified' },
      },
    };
    stubFetch(() => jsonResponse(body));
    expect(await getCard('ex10-28')).toEqual({ ok: true, data: body });
    const bare = { ...body, data: { ...body.data, variants: [] } };
    stubFetch(() => jsonResponse(bare));
    expect(await getCard('ex10-28')).toEqual({ ok: true, data: bare });
  });

  it('the account lists: an unpriced row, a null card, no alert, a null total, no rows and an undelivered webhook pass', async () => {
    const watch = watchlistBody([watchItemOf({ price: null, card: null, alert_pct: null })]);
    const folio = portfolioBody([holdingOf({ value: null, card: null })], {
      total_value: null,
      first_day: null,
      last_day: null,
    });
    const hooks = webhooksBody([webhookOf({ description: null, last_delivery: null })]);
    const none = webhooksBody([]);
    const calls: [string, () => Promise<unknown>, unknown][] = [
      ['GET /v1/watchlist', () => getWatchlist(), watch],
      ['GET /v1/portfolio', () => getPortfolio(), folio],
      ['GET /v1/webhooks', () => getWebhooks(), hooks],
      ['GET /v1/webhooks', () => getWebhooks(), none],
    ];
    for (const [key, call, body] of calls) {
      stubAccountFetch({ [key]: jsonResponse(body) });
      expect(await call()).toEqual({ ok: true, data: body });
    }
  });

  it('a history of no series, and of a series with no points, is an answer and not a refusal', async () => {
    for (const body of [historyBody([]), historyBody([seriesOf('ex10-28:holo:-:-', [])])]) {
      stubFetch(() => jsonResponse(body));
      const result = await getCardPrices('ex10-28', '1M');
      expect(result.ok).toBe(true);
    }
  });
});

describe('one pipeline keeps the 419 order, the cancel rule and the 401 listener on the account side', () => {
  it('a write that answers 419 asks for a fresh cookie, sends once more, and a second 419 stops there', async () => {
    setXsrfCookie();
    const calls = stubAccountFetch({ 'POST /logout': rawResponse(419, '') });
    const result = await signOut();
    expect(result.ok).toBe(false);
    expect(calls.map((call) => call.key)).toEqual([
      'POST /logout',
      'GET /sanctum/csrf-cookie',
      'POST /logout',
    ]);
  });

  it('a 401 write whose body is not JSON still tells the listener once, and a 500 problem write does not', async () => {
    setXsrfCookie();
    let heard = 0;
    const stop = onUnauthenticatedWrite(() => {
      heard += 1;
    });
    stubAccountFetch({
      'DELETE /v1/watchlist/ex10-28%3Aholo%3A-%3A-': rawResponse(401, '<html>', 'text/html'),
    });
    await deleteWatchlistItem('ex10-28:holo:-:-');
    expect(heard).toBe(1);
    stubAccountFetch({
      'DELETE /v1/watchlist/ex10-28%3Aholo%3A-%3A-': problemResponse(
        serverErrorProblem('/v1/watchlist'),
      ),
    });
    await deleteWatchlistItem('ex10-28:holo:-:-');
    stop();
    expect(heard).toBe(1);
  });

  it('a cancelled public read and a cancelled account read reject as cancelled, they do not answer unreachable', async () => {
    const controller = new AbortController();
    controller.abort();
    const abort = new DOMException('cancelled', 'AbortError');
    stubFetch(() => abort);
    await expect(getMovers('up', 5, { signal: controller.signal })).rejects.toBe(abort);
    stubAccountFetch({ 'GET /v1/watchlist': abort });
    await expect(getWatchlist({ signal: controller.signal })).rejects.toBe(abort);
  });

  it('a public read of JSON null is an answer of nothing, a whitespace body and a 204 are refusals by status', async () => {
    stubFetch(() => rawResponse(200, 'null'));
    expect(await getCardPrices('ex10-28', '1M')).toEqual({ ok: true, data: null });
    stubFetch(() => rawResponse(200, '  \n'));
    const blank = await getMovers('up', 5);
    expect(blank.ok ? '' : blank.title).toBe('The PokéPrices API answered HTTP 200');
    stubFetch(() => rawResponse(204, null));
    const none = await getMovers('up', 5);
    expect(none.ok ? '' : none.title).toBe('The PokéPrices API answered HTTP 204');
  });
});
