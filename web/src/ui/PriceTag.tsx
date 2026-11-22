// The PriceTag primitive: a market price, its change vs the previous close and the as-of
// line. Each line is one element whose own text is the pinned string, verbatim (never split into
// child spans): the price and the change in the mono stack with tabular figures, the change as a
// 2 px chip in its tone with a 12 % tint of the same token (flat untinted), as-of 13 px muted.
import type { ReactNode } from 'react';
import { asOfText, changeLine, grouped, shortChangeLine, type ChangeFields } from '../format';

export type PriceTagSize = 'variant' | 'row';

/** A price as the card, the watchlist and the portfolio carry it. */
export interface PriceTagFields extends ChangeFields {
  readonly close: string;
  readonly day: string;
}

/** The price figure per size: variant 28/34, row 16/22; both 600. */
const PRICE_SIZE: Readonly<Record<PriceTagSize, string>> = {
  variant: 'text-2xl',
  row: 'text-base',
};

/** The headline price, 36/40 700: the card hero's figure. */
const HERO_PRICE = 'block num text-4xl font-bold';

/** The chip tint of each tone: 12 % of the tone's own token; flat stays untinted. */
const TINT: Readonly<Record<string, string>> = {
  'text-up': 'bg-up/12',
  'text-down': 'bg-down/12',
};

/** "Market price $X" as one span. */
export function PriceFigure({
  close,
  size,
}: {
  readonly close: string;
  readonly size: PriceTagSize;
}) {
  return (
    <span className={`mt-1 block num ${PRICE_SIZE[size]} font-semibold`}>
      {`Market price $${grouped(close)}`}
    </span>
  );
}

/**
 * "$X" alone as one span, 36/40 700 mono tabular: the card hero's figure. Its label line
 * ("Market price · <variant>") is the caller's, so "Market price $X" stays unique per variant.
 */
export function HeroPrice({ close }: { readonly close: string }) {
  return <span className={HERO_PRICE}>{`$${grouped(close)}`}</span>;
}

/**
 * The chip classes of a tone: the tone plus its tint, a flat chip untinted and without inset;
 * `pad` is its vertical padding (none on a short row, which keeps the row to one 20 px line).
 */
function chipClass(tone: string, pad = 'py-0.5'): string {
  const tint = TINT[tone];
  const box = tint === undefined ? 'px-0' : `px-1 ${tint}`;
  return `inline-block rounded-sm ${pad} num text-sm font-semibold ${tone} ${box}`;
}

/**
 * The change line of changeLine() as one chip span: its tone class plus the tone's tint. A flat
 * chip has no tint and no inset, so its text starts on the price figure's left edge.
 */
export function ChangeChip({ price }: { readonly price: ChangeFields }) {
  const change = changeLine(price);
  return <span className={`mt-1 ${chipClass(change.tone)}`}>{change.text}</span>;
}

/**
 * The short price of a list row whose list prints its source and "as of" once: "$X" and the
 * change chip of shortChangeLine() on one line, hidden from assistive technology, which reads the
 * full lines instead from screen-reader-only spans: "Market price $X", the change line of
 * changeLine() and, with `day`, the row's own "as of" line. `id` prefixes their ids
 * (rowPriceIds). `showDay` prints "Close of <day>" under the price, for a list whose rows span
 * several days.
 */
export function RowPrice({
  price,
  id,
  day,
  showDay = false,
}: {
  readonly price: ChangeFields & { readonly close: string };
  readonly id: string;
  readonly day?: string;
  readonly showDay?: boolean;
}) {
  const short = shortChangeLine(price);
  return (
    <>
      <span aria-hidden="true" className="flex flex-wrap items-baseline gap-x-2">
        <span className="num text-base leading-5 font-semibold">{`$${grouped(price.close)}`}</span>
        <span className={chipClass(short.tone, 'py-0')}>{short.text}</span>
      </span>
      {showDay && day !== undefined && (
        <span aria-hidden="true" className="block text-meta text-ink-muted">
          {`Close of ${day}`}
        </span>
      )}
      <span id={`${id}-price`} className="sr-only">
        {`Market price $${grouped(price.close)}`}
      </span>
      <span id={`${id}-change`} className="sr-only">
        {changeLine(price).text}
      </span>
      {day !== undefined && (
        <span id={`${id}-as-of`} className="sr-only">
          {asOfText(day)}
        </span>
      )}
    </>
  );
}

/** "as of <day> from TCGCSV (daily close)" as one element: 13/18, muted, sans. */
export function AsOf({
  day,
  as = 'span',
  id,
  className = '',
}: {
  readonly day: string;
  readonly as?: 'span' | 'p';
  readonly id?: string | undefined;
  readonly className?: string;
}) {
  const text = asOfText(day);
  const classes = `${as === 'span' ? 'block ' : ''}text-meta text-ink-muted ${className}`.trim();
  return as === 'p' ? (
    <p id={id} className={classes}>
      {text}
    </p>
  ) : (
    <span id={id} className={classes}>
      {text}
    </span>
  );
}

/**
 * The price, change and as-of lines in that order. `beside` (the sparkline) sits in a div next to
 * the price span; nothing wraps the change span, so a caller's spans stay in their order.
 */
export default function PriceTag({
  price,
  size,
  beside,
}: {
  readonly price: PriceTagFields;
  readonly size: PriceTagSize;
  readonly beside?: ReactNode;
}) {
  const figure = <PriceFigure close={price.close} size={size} />;
  return (
    <>
      {beside === undefined ? (
        figure
      ) : (
        <div className="flex flex-wrap items-center gap-x-3">
          {figure}
          {beside}
        </div>
      )}
      <ChangeChip price={price} />
      <AsOf day={price.day} />
    </>
  );
}
