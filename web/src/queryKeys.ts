// The PokéPrices public query keys: every key whose data is the same for every visitor (cards, card
// prices, movers, search) is built here, so a page and its tests share one shape. Per-user keys
// never live here: they go through accountKey() (account/session.ts), under ['account', …], so a
// session transition forgets all of them at once.
import type { MoversDirection, PriceRange } from './api/client';

/** GET /v1/cards/{id}. */
export function cardKey(id: string): readonly ['card', string] {
  return ['card', id];
}

/** GET /v1/cards/{id}/prices?range=… */
export function cardPricesKey(
  id: string,
  range: PriceRange,
): readonly ['card-prices', string, PriceRange] {
  return ['card-prices', id, range];
}

/** GET /v1/movers?direction=…&limit=… */
export function moversKey(
  direction: MoversDirection,
  limit: number,
): readonly ['movers', MoversDirection, number] {
  return ['movers', direction, limit];
}

/** GET /v1/prices/freshness (the shell's stale-prices banner). */
export function freshnessKey(): readonly ['price-freshness'] {
  return ['price-freshness'];
}

/** GET /v1/cards?q=…[&page=…] */
export function searchKey(q: string, page: number): readonly ['search', string, number] {
  return ['search', q, page];
}
