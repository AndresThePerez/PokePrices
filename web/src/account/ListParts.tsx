// The pieces the watchlist, the portfolio and the card-page buttons share (D10): the P3 movers row
// (card link, detail line, Market price, change line in the D8 tone) with its own "as of" line,
// the quantity form, and the signed-out, demo, degraded and footnote lines. Every amount is the
// API's decimal string printed through grouped(); nothing here reads the clock.
import { useId, useState, type ReactNode, type SubmitEvent } from 'react';
import type { WatchlistItem } from '../api/client';
import { PRICE_FOOTNOTE } from '../format';
import Link from '../Link';
import { cardPath } from '../router';
import { INPUT } from '../ui/classes';
import PriceTag from '../ui/PriceTag';
import DemoButton from './DemoButton';
import { FOCUS, MUTED, SECONDARY_BUTTON, TEXT_LINK } from './fields';
import { DEGRADED_LINE, DEMO_NOTICE, cardLabel, detailLine, type ListRow } from './lists';

const FAINT = 'text-slate-500 dark:text-slate-400';

/** A list page: one main landmark, one h1, the width of the movers board. */
export function ListMain({
  title,
  children,
}: {
  readonly title: string;
  readonly children: ReactNode;
}) {
  return (
    <main className="mx-auto max-w-3xl p-6">
      <h1 className="text-3xl font-semibold">{title}</h1>
      {children}
    </main>
  );
}

/** The loading line of a list page (D10). */
export function ListLoading({ children }: { readonly children: string }) {
  return (
    <p role="status" className={`mt-6 text-sm ${MUTED}`}>
      {children}
    </p>
  );
}

/** The degraded catalog line (P3 D10): names may come from the last good copy. */
export function DegradedLine() {
  return (
    <p className="mt-6 border-l-4 border-amber-500 bg-amber-50 px-4 py-3 text-amber-950 dark:border-amber-400 dark:bg-amber-950 dark:text-amber-100">
      {DEGRADED_LINE}
    </p>
  );
}

/** What the demo account is, on every page it can open (D10, phase honesty rule). */
export function DemoNotice() {
  return (
    <p className="mt-6 border-l-4 border-slate-400 bg-slate-50 px-4 py-3 text-slate-900 dark:border-slate-500 dark:bg-slate-800 dark:text-slate-100">
      {DEMO_NOTICE}
    </p>
  );
}

/** The footnote that closes every list of prices. */
export function ListFootnote() {
  return <p className={`mt-4 text-xs leading-snug ${FAINT}`}>{PRICE_FOOTNOTE}</p>;
}

/** Signed out on /watchlist or /portfolio: sign in (and come back), sign up, or try the demo. */
export function SignedOutList({ list }: { readonly list: 'watchlist' | 'portfolio' }) {
  return (
    <>
      <p className="mt-6">{`Sign in to keep a ${list}.`}</p>
      <p className="mt-2 flex flex-wrap gap-x-6">
        <Link to={`/sign-in?next=/${list}`} className={TEXT_LINK}>
          Sign in
        </Link>
        <Link to="/sign-up" className={TEXT_LINK}>
          Create an account
        </Link>
      </p>
      <DemoButton />
    </>
  );
}

/** The card link and the detail line of a row (the P3 movers row, without a rank). */
export function RowName({ row }: { readonly row: ListRow }) {
  return (
    <>
      <Link
        to={cardPath(row.card_id)}
        className={`inline-flex min-h-11 items-center font-semibold underline-offset-4 hover:underline ${FOCUS}`}
      >
        {cardLabel(row)}
      </Link>
      <span className={`block text-sm ${MUTED}`}>{detailLine(row)}</span>
    </>
  );
}

/** Market price, the change vs the previous close and the row's own "as of" line, or `missing`. */
export function PriceLines({
  price,
  missing,
}: {
  readonly price: WatchlistItem['price'];
  readonly missing: string;
}) {
  if (price === null) return <span className={`mt-1 block text-meta ${MUTED}`}>{missing}</span>;
  return <PriceTag price={price} size="row" />;
}

const QUANTITY_INPUT = `mt-1 w-28 num ${INPUT}`;

/**
 * The quantity form (D10): a labelled whole-number field (1 to 9999) and Save. The browser's own
 * limits are hints only: the form never blocks a submit, so the API's 422 is what the field shows.
 */
export function QuantityForm({
  initial,
  pending,
  error,
  onSave,
}: {
  readonly initial: string;
  readonly pending: boolean;
  readonly error: string | undefined;
  readonly onSave: (text: string) => void;
}) {
  const id = useId();
  const errorId = `${id}-error`;
  const [text, setText] = useState(initial);

  function submit(event: SubmitEvent<HTMLFormElement>): void {
    event.preventDefault();
    if (!pending) onSave(text);
  }

  return (
    <form noValidate onSubmit={submit} className="flex flex-wrap items-end gap-x-2 gap-y-1">
      <div>
        <label htmlFor={id} className="text-sm font-medium">
          Quantity owned
        </label>
        <input
          id={id}
          name="quantity"
          type="number"
          inputMode="numeric"
          min={1}
          max={9999}
          step={1}
          value={text}
          aria-invalid={error === undefined ? undefined : true}
          aria-describedby={error === undefined ? undefined : errorId}
          onChange={(event) => {
            setText(event.target.value);
          }}
          className={QUANTITY_INPUT}
        />
      </div>
      <button type="submit" disabled={pending} className={SECONDARY_BUTTON}>
        Save
      </button>
      {error !== undefined && (
        <p id={errorId} className="basis-full text-sm font-medium text-red-700 dark:text-red-300">
          {error}
        </p>
      )}
    </form>
  );
}
