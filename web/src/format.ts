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
