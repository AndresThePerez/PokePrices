// Shared pieces of the watchlist and portfolio views (D10) that are not components: the strings
// every list view prints, the row naming of the P3 movers row and the answers' refusals.
import type { ApiFailure, WatchlistItem } from '../api/client';
import { cardPath } from '../router';
import { accountKey } from './session';

export const WATCHLIST_KEY = accountKey('watchlist');
export const PORTFOLIO_KEY = accountKey('portfolio');

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

/** Where sign-in brings a visitor back to: this card's page (D10). */
export function signInPath(cardId: string): string {
  return `/sign-in?next=${encodeURIComponent(cardPath(cardId))}`;
}

/** True for the 403 every write of the demo account answers. */
export function isDemoReadOnly(failure: ApiFailure): boolean {
  return failure.status === 403 && failure.type.endsWith('/demo-read-only');
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
