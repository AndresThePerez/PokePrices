// The pieces the watchlist and the portfolio share: the row (a CSS-only card-back
// placeholder, the card link and set line, a fixed right-aligned price column with its own "as of"
// line, the controls under them), the quantity form, and the signed-out, degraded and footnote
// lines. The demo is named once, by the header's strip. Every amount is the API's decimal string
// printed through grouped(); nothing here reads the clock.
import { useId, useState, type ReactNode, type SubmitEvent } from 'react';
import type { WatchlistItem } from '../api/client';
import { PRICE_FOOTNOTE, detailLine, initialOf } from '../format';
import Link from '../Link';
import { cardPath } from '../router';
import { INPUT } from '../ui/classes';
import DegradedNotice from '../ui/DegradedNotice';
import Page from '../ui/Page';
import PriceTag from '../ui/PriceTag';
import Skeleton from '../ui/Skeleton';
import DemoButton from './DemoButton';
import { FOCUS, MUTED, SECONDARY_BUTTON, TEXT_LINK } from './fields';
import { cardLabel, type ListRow } from './lists';

/** A list page: one main landmark, one h1, the width of the movers board. */
export function ListMain({
  title,
  children,
}: {
  readonly title: string;
  readonly children: ReactNode;
}) {
  return (
    <Page>
      <h1 className="text-2xl font-semibold">{title}</h1>
      {children}
    </Page>
  );
}

/** The loading line of a list page (D10). */
export function ListLoading({ children }: { readonly children: string }) {
  return (
    <>
      <p role="status" className={`mt-6 text-sm ${MUTED}`}>
        {children}
      </p>
      <Skeleton className="mt-4" />
    </>
  );
}

/** The degraded catalog line: names may come from the last good copy. */
export function DegradedLine() {
  return <DegradedNotice className="mt-6" />;
}

/** The footnote that closes every list of prices. */
export function ListFootnote() {
  return <p className={`mt-4 text-meta ${MUTED}`}>{PRICE_FOOTNOTE}</p>;
}

/** Signed out on /watchlist or /portfolio: sign in (and come back), sign up, or try the demo. */
export function SignedOutList({ list }: { readonly list: 'watchlist' | 'portfolio' }) {
  return (
    <>
      <p className="mt-6 text-base">{`Sign in to keep a ${list}.`}</p>
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

/**
 * The card-back placeholder of a row (the list APIs carry no image URL): a 56 × 78 (2:3) sunken
 * box with the card's initial drawn by CSS from data-initial, so it holds no DOM text. It is no
 * link, no image and, having no text, needs no aria-hidden.
 */
export function CardBack({ row }: { readonly row: ListRow }) {
  return (
    <div
      data-initial={row.card === null ? '' : initialOf(row.card.name)}
      className={`flex h-19.5 w-14 shrink-0 items-center justify-center rounded-md border border-line-subtle bg-surface-sunken text-lg font-semibold ${MUTED} before:content-[attr(data-initial)]`}
    />
  );
}

/** The card link (16 px 600, a 44 px target) and the set line 2 px under it (14 px muted). */
export function RowName({ row }: { readonly row: ListRow }) {
  return (
    <>
      <Link
        to={cardPath(row.card_id)}
        className={`-mt-3 inline-flex min-h-11 items-end text-base font-semibold underline-offset-4 hover:underline ${FOCUS}`}
      >
        {cardLabel(row)}
      </Link>
      <span className={`mt-0.5 block text-sm ${MUTED}`}>{detailLine(row)}</span>
    </>
  );
}

/**
 * One list row: the placeholder left, then the name block and a fixed right-aligned price column
 * (stacked under the name below 640 px), then the row's controls and its failure across both.
 */
export function ListItem({
  row,
  details,
  price,
  children,
}: {
  readonly row: ListRow;
  readonly details?: ReactNode;
  readonly price: ReactNode;
  readonly children?: ReactNode;
}) {
  return (
    <li className="flex gap-4 py-3">
      <CardBack row={row} />
      <div className="grid min-w-0 flex-1 content-start gap-x-6 sm:grid-cols-[minmax(0,1fr)_minmax(12rem,18rem)]">
        <div className="min-w-0 wrap-anywhere">
          <RowName row={row} />
          {details}
        </div>
        <div className="min-w-0 wrap-anywhere sm:pt-1.5 sm:text-right">{price}</div>
        {children}
      </div>
    </li>
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
  if (price === null) {
    return (
      <span
        className={`mt-1 block rounded-md border border-dashed border-line px-3 py-2 text-left text-meta ${MUTED}`}
      >
        {missing}
      </span>
    );
  }
  return <PriceTag price={price} size="row" />;
}

const QUANTITY_INPUT = `mt-1 w-28 num ${INPUT}`;

/**
 * The quantity form (D10): a labelled whole-number field (1 to 9999) and Save. The browser's own
 * limits are hints only: the form never blocks a submit, so the API's 422 is what the field shows.
 * The field shows the typed draft (null until something is typed) over the quantity the API
 * holds (`initial`): a refetch moves an untouched field and never a typed one. A successful save
 * drops the draft (`saves`, the owner's count of them, went up; or the owner closes the form), and
 * so does another account, which unmounts the form (the forgotten list shows its loading line).
 */
export function QuantityForm({
  initial,
  saves = 0,
  pending,
  error,
  onSave,
}: {
  readonly initial: string;
  readonly saves?: number;
  readonly pending: boolean;
  readonly error: string | undefined;
  readonly onSave: (text: string) => void;
}) {
  const id = useId();
  const errorId = `${id}-error`;
  const [draft, setDraft] = useState<string | null>(null);
  const [savesSeen, setSavesSeen] = useState(saves);
  if (savesSeen !== saves) {
    setSavesSeen(saves);
    setDraft(null);
  }
  const text = draft ?? initial;

  function submit(event: SubmitEvent<HTMLFormElement>): void {
    event.preventDefault();
    if (!pending) onSave(text);
  }

  return (
    <form noValidate onSubmit={submit} className="flex flex-wrap items-end gap-x-2 gap-y-1">
      <div>
        <label htmlFor={id} className="text-sm font-semibold">
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
            setDraft(event.target.value);
          }}
          className={QUANTITY_INPUT}
        />
      </div>
      <button type="submit" disabled={pending} className={SECONDARY_BUTTON}>
        Save
      </button>
      {error !== undefined && (
        <p id={errorId} className="basis-full text-sm font-semibold text-problem">
          {error}
        </p>
      )}
    </form>
  );
}
