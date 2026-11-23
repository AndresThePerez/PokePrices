// Shared PokéPrices formatting. Every amount and sign is copied from the API's decimal
// string; nothing here parses money into a number.

/** The naming fields of a variant, as the card answer and the history series both carry them. */
export interface VariantNaming {
  readonly variant_key: string;
  readonly finish: string;
  readonly edition: string;
  readonly treatment: string;
}

/**
 * The fields of a change vs the previous close. The card's per-variant price (nullable) and a
 * movers row (non-null strings) both satisfy it, so every ticker prints through one function.
 */
export interface ChangeFields {
  readonly previous_close: string | null;
  readonly previous_day: string | null;
  readonly change: string | null;
  readonly change_pct: string | null;
}

/** The footnote under every list of prices: where the market prices come from and how often. */
export const PRICE_FOOTNOTE = 'TCGplayer market prices via TCGCSV, collected once a day.';

/**
 * The one "as of" text of a price: "as of <day> from TCGCSV (daily close)". AsOf (ui/PriceTag)
 * renders it; the price history and the portfolio total print it too.
 */
export function asOfText(day: string): string {
  return `as of ${day} from TCGCSV (daily close)`;
}

/** "Lowest listing $X": the lowest listing of the snapshot a close came from, beside that close. */
export function lowestListingText(amount: string): string {
  return `Lowest listing $${grouped(amount)}`;
}

/** The note under a close the API flags as a thin market (under half its lowest listing). */
export function thinMarketText(lowest: string): string {
  return `Thin market: the market price is from few or old sales; the lowest listing in the same snapshot is $${grouped(lowest)}.`;
}

/** What a variant with a listing and no close reads in place of a price. */
export function noRecentSalesText(lowest: string): string {
  return `No recent sales; lowest listing $${grouped(lowest)}`;
}

/** The source and "as of" line of a listing shown without a close: the day of its snapshot. */
export function listingAsOfText(day: string): string {
  return `Listing as of ${day} from TCGCSV (daily snapshot)`;
}

/**
 * The one source-and-"as of" line of a list whose prices rest on the days `first` to `last`: the
 * "as of" text of one day, or "Closes from <first> to <last> from TCGCSV (daily close)".
 */
export function daysText(first: string, last: string): string {
  return first === last
    ? asOfText(last)
    : `Closes from ${first} to ${last} from TCGCSV (daily close)`;
}

/**
 * What a price order of a search covers: the API orders the first `ordered` of `total` matches
 * (PokéSearch's first page of at most 100), so the sentence names the top `ordered` only when
 * more matches exist than were ordered, and every match otherwise.
 */
export function priceOrderText(ordered: number, total: number): string {
  if (total === 0 || ordered === 0) return 'No matches to order by price.';
  if (ordered >= total) {
    return total === 1
      ? 'Ordered by price among the only match.'
      : `Ordered by price among all ${grouped(String(total))} matches; cards with no price come last.`;
  }
  return `Ordered by price among the top ${grouped(String(ordered))} of ${grouped(String(total))} matches; cards with no price come last.`;
}

/**
 * The initial a card-back placeholder draws: the first code point of the trimmed name (an astral
 * character is never split), upper-cased; '' for a blank name.
 */
export function initialOf(name: string): string {
  const code = name.trim().codePointAt(0);
  return code === undefined ? '' : String.fromCodePoint(code).toUpperCase();
}

/** finish, edition and treatment in words; '-' and '' mean "none" in the variant key. */
export function variantLabel(variant: VariantNaming): string {
  const parts = [variant.finish, variant.edition, variant.treatment].filter(
    (part) => part !== '' && part !== '-',
  );
  return parts.length > 0 ? parts.join(', ') : variant.variant_key;
}

/** A row that names a variant and, when the catalog named it, its card's set and number. */
interface DetailRow extends VariantNaming {
  readonly card: { readonly set_name: string; readonly number: string } | null;
}

/**
 * The second line of a movers, watchlist or portfolio row: set and number when the catalog named
 * the card, then the variant with its first letter upper-cased.
 */
export function detailLine(row: DetailRow): string {
  const label = variantLabel(row);
  const variant = label.charAt(0).toUpperCase() + label.slice(1);
  if (row.card === null) return variant;
  return `${row.card.set_name} No. ${row.card.number} · ${variant}`;
}

const NEUTRAL = 'text-flat';
const DECIMAL = /^(\d+)(\.\d+)?$/;

/**
 * An unsigned API decimal string with ',' between thousands. The digits are copied, never parsed
 * into a number (no re-rounding, "0.10" stays "0.10"); anything that is not a plain decimal is
 * copied as it came.
 */
export function grouped(amount: string): string {
  const match = DECIMAL.exec(amount);
  if (match === null) return amount;
  const whole = match[1] ?? '';
  return `${whole.replace(/\B(?=(\d{3})+(?!\d))/g, ',')}${match[2] ?? ''}`;
}

/** The sign of an API decimal string and its digits without it. */
export function signed(amount: string): { readonly negative: boolean; readonly digits: string } {
  return { negative: amount.startsWith('-'), digits: amount.replace(/^[-+]/, '') };
}

/**
 * The change line and its tone. Direction comes from the API's change string; the arrow and the
 * sign carry it, the colour only repeats it. change_pct is printed only when the API sent one.
 */
export function changeLine(price: ChangeFields): { readonly text: string; readonly tone: string } {
  if (price.previous_close === null || price.previous_day === null || price.change === null) {
    return { text: 'No previous close yet', tone: NEUTRAL };
  }
  const versus = `vs previous close (${price.previous_day})`;
  const change = signed(price.change);
  if (/^0+(\.0+)?$/.test(change.digits)) return { text: `No change ${versus}`, tone: NEUTRAL };
  const arrow = change.negative ? '▼ -' : '▲ +';
  let percent = '';
  if (price.change_pct !== null) {
    const pct = signed(price.change_pct);
    percent = ` (${pct.negative ? '-' : '+'}${pct.digits}%)`;
  }
  return {
    text: `${arrow}$${grouped(change.digits)}${percent} ${versus}`,
    tone: change.negative ? 'text-down' : 'text-up',
  };
}

/** The fields of the portfolio's estimate the lines below read (GET /v1/portfolio meta, /v1/me). */
export interface EstimateFields {
  readonly count: number;
  readonly unpriced: number;
  readonly no_previous_close: number;
  readonly total_value: string | null;
  readonly change: string | null;
  readonly change_pct: string | null;
  readonly first_day: string | null;
  readonly last_day: string | null;
}

/** What the portfolio total is: the label printed with it, on the page and in the header's name. */
export const ESTIMATE_LABEL = 'An estimate at daily-close market prices, not a sale value.';

/**
 * The portfolio's change vs the previous close, in the tone of changeLine(): the arrow, the signed
 * amount and, when the API sent one, the signed percent ("▲ +$16.93 (+1.64%) vs previous close");
 * the amount alone with the reason when there is no percent (the previous closes add up to
 * $0.00); "No change vs previous close"; or no change at all when no card has a previous close.
 */
export function estimateChangeLine(estimate: EstimateFields): {
  readonly text: string;
  readonly tone: string;
} {
  if (estimate.change === null) {
    return { text: 'No previous close yet, so no change to show.', tone: NEUTRAL };
  }
  const change = signed(estimate.change);
  if (/^0+(\.0+)?$/.test(change.digits)) {
    return { text: 'No change vs previous close.', tone: NEUTRAL };
  }
  const arrow = change.negative ? '▼ -' : '▲ +';
  const amount = `${arrow}$${grouped(change.digits)}`;
  const tone = change.negative ? 'text-down' : 'text-up';
  if (estimate.change_pct === null) {
    return {
      text: `${amount} vs previous close; no percent, as the previous closes add up to $0.00.`,
      tone,
    };
  }
  const pct = signed(estimate.change_pct);
  return {
    text: `${amount} (${pct.negative ? '-' : '+'}${pct.digits}%) vs previous close.`,
    tone,
  };
}

/** The short change of the header's estimate: "▲ +1.64%", "▲ +$4.00", "No change", or null. */
export function shortEstimateChange(estimate: EstimateFields): string | null {
  if (estimate.change === null) return null;
  const change = signed(estimate.change);
  if (/^0+(\.0+)?$/.test(change.digits)) return 'No change';
  const arrow = change.negative ? '▼ ' : '▲ ';
  if (estimate.change_pct === null) {
    return `${arrow}${change.negative ? '-' : '+'}$${grouped(change.digits)}`;
  }
  const pct = signed(estimate.change_pct);
  return `${arrow}${pct.negative ? '-' : '+'}${pct.digits}%`;
}

function cards(count: number): string {
  return count === 1 ? '1 card' : `${grouped(String(count))} cards`;
}

/** "12 cards, 2 without a price." (or "12 cards." when every card has one). */
export function cardsLine(estimate: EstimateFields): string {
  return estimate.unpriced > 0
    ? `${cards(estimate.count)}, ${grouped(String(estimate.unpriced))} without a price.`
    : `${cards(estimate.count)}.`;
}

/** How many priced cards the change leaves out (no previous close), or null when none. */
export function changeCoverLine(estimate: EstimateFields): string | null {
  const left = estimate.no_previous_close;
  if (left <= 0) return null;
  return left === 1
    ? 'The change leaves out 1 card with no previous close.'
    : `The change leaves out ${grouped(String(left))} cards with no previous close.`;
}

/**
 * The change chip of a short list row, in the tone of changeLine(): the arrow and the signed
 * percent ("▲ +361.30%"), or the signed amount when the API sent no change_pct, without
 * "vs previous close (<day>)"; "No change" and "No previous close yet" as they are. The full line
 * stays in the row's accessible name.
 */
export function shortChangeLine(price: ChangeFields): {
  readonly text: string;
  readonly tone: string;
} {
  const full = changeLine(price);
  if (price.previous_close === null || price.previous_day === null || price.change === null) {
    return full;
  }
  if (full.tone === NEUTRAL) return { text: 'No change', tone: NEUTRAL };
  const arrow = signed(price.change).negative ? '▼ ' : '▲ ';
  if (price.change_pct === null) {
    const change = signed(price.change);
    return {
      text: `${arrow}${change.negative ? '-' : '+'}$${grouped(change.digits)}`,
      tone: full.tone,
    };
  }
  const pct = signed(price.change_pct);
  return { text: `${arrow}${pct.negative ? '-' : '+'}${pct.digits}%`, tone: full.tone };
}
