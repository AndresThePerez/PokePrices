// Shared PokéPrices formatting (D2, D3). Every amount and sign is copied from the API's decimal
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

/** finish, edition and treatment in words; '-' and '' mean "none" in the variant key. */
export function variantLabel(variant: VariantNaming): string {
  const parts = [variant.finish, variant.edition, variant.treatment].filter(
    (part) => part !== '' && part !== '-',
  );
  return parts.length > 0 ? parts.join(', ') : variant.variant_key;
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
