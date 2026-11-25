// Shared pieces of the watchlist and portfolio views that are not components: the strings
// every list view prints, the row naming of the P3 movers row and the answers' refusals.
import { useQuery } from '@tanstack/react-query';
import {
  getDemoPortfolio,
  getDemoWatchlist,
  hasProblemType,
  type ApiFailure,
  type DemoPortfolioBody,
  type DemoWatchlistBody,
  type WatchlistItem,
} from '../api/client';
import { unwrap } from '../query';
import { cardPath } from '../router';
import { accountKey } from './session';

export const WATCHLIST_KEY = accountKey('watchlist');
export const PORTFOLIO_KEY = accountKey('portfolio');

/**
 * The demo's public sample lists. They belong to no account, so their keys are outside
 * ['account', …]: a session change never forgets them, and no session is ever needed to read them.
 */
export const SAMPLE_WATCHLIST_KEY = ['sample', 'watchlist'] as const;
export const SAMPLE_PORTFOLIO_KEY = ['sample', 'portfolio'] as const;

/** GET /v1/demo/watchlist as a query; asked for only while `enabled` (a signed-out visitor). */
export function useSampleWatchlistQuery(enabled: boolean) {
  return useQuery<DemoWatchlistBody, ApiFailure>({
    queryKey: SAMPLE_WATCHLIST_KEY,
    queryFn: ({ signal }) => unwrap(getDemoWatchlist({ signal })),
    enabled,
  });
}

/** GET /v1/demo/portfolio as a query; it never touches the header's estimate. */
export function useSamplePortfolioQuery(enabled: boolean) {
  return useQuery<DemoPortfolioBody, ApiFailure>({
    queryKey: SAMPLE_PORTFOLIO_KEY,
    queryFn: ({ signal }) => unwrap(getDemoPortfolio({ signal })),
    enabled,
  });
}

export const DEMO_NOTICE =
  'You are signed in to the read-only demo account. Its watchlist and portfolio cannot be changed.';
export const NO_PRICE = 'No price in the collected snapshots';

/**
 * The row fields both lists share (a holding is a watchlist item, without its alert threshold,
 * plus quantity and value).
 */
export type ListRow = Omit<WatchlistItem, 'alert_pct'>;

/** The card's name from the PokéSearch catalog, else its id (P3 movers row). */
export function cardLabel(row: ListRow): string {
  return row.card === null ? `Card ${row.card_id}` : row.card.name;
}

/** The days a list's prices rest on, first and last, or null when no row has a price. */
export function priceDays(
  rows: readonly ListRow[],
): { readonly first: string; readonly last: string } | null {
  const days = rows.flatMap((row) => (row.price === null ? [] : [row.price.day])).sort();
  const first = days[0];
  const last = days[days.length - 1];
  return first === undefined || last === undefined ? null : { first, last };
}

/** Where sign-in brings a visitor back to: this card's page. */
export function signInPath(cardId: string): string {
  return `/sign-in?next=${encodeURIComponent(cardPath(cardId))}`;
}

/** The calm sentence every refused write of the read-only demo shows (ProblemBlock), no request id. */
export const DEMO_READ_ONLY_LINE = 'The demo account is read-only.';

/** True for the 403 every write of the demo account answers. */
export function isDemoReadOnly(failure: ApiFailure): boolean {
  return hasProblemType(failure, 403, 'demo-read-only');
}

/**
 * The quantity typed into a field as the whole number the API takes, or null when the text is not
 * one (the API then answers 422 under the field). It is the only number the list views read from
 * text; no money string is ever converted.
 */
export function quantityOf(text: string): number | null {
  const trimmed = text.trim();
  return /^\d{1,9}$/.test(trimmed) ? Number(trimmed) : null;
}
