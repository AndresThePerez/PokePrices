// The pieces the watchlist and the portfolio share: the short row (the card's 48 px picture or
// its card-back tile, the card link and set line, a fixed right-aligned price column with the
// price and the short change, the controls under them), the list's one source-and-"as of" stamp,
// the quantity form, and the signed-out, degraded and footnote lines. The demo is named once, by
// the header's strip. Every amount is the API's decimal string printed through grouped(); nothing
// here reads the clock.
import { useId, useState, type ReactNode, type SubmitEvent } from 'react';
import type { WatchlistItem } from '../api/client';
import { PRICE_FOOTNOTE, daysText, detailLine } from '../format';
import Link from '../Link';
import { cardVariantPath } from '../routes';
import CardThumbnail from '../ui/CardThumbnail';
import { FOCUS, INPUT, MUTED, SECONDARY_BUTTON } from '../ui/classes';
import DegradedNotice from '../ui/DegradedNotice';
import Page from '../ui/Page';
import { RowPrice } from '../ui/PriceTag';
import { rowPriceIds } from '../ui/rowPriceIds';
import Skeleton from '../ui/Skeleton';
import StatusLine from '../ui/StatusLine';
import DemoButton from './DemoButton';
import { cardLabel, priceDays, type ListRow } from './lists';
import SignInPrompt from './SignInPrompt';

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

/** The loading line of a list page. */
export function ListLoading({ children }: { readonly children: string }) {
  return (
    <>
      <StatusLine gap="mt-6">{children}</StatusLine>
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
    <SignInPrompt sentence={`Sign in to keep a ${list}.`} next={`/${list}`} signUp base>
      <DemoButton />
    </SignInPrompt>
  );
}

/**
 * The list's one source-and-"as of" line (daysText): one day as its "as of" line, several as
 * their first and last, followed by `base`, the line that names what each row's change is
 * measured against (the short rows show only an arrow and a percent); nothing when no row has a
 * price.
 */
export function ListStamp({
  rows,
  base,
}: {
  readonly rows: readonly ListRow[];
  readonly base: string;
}) {
  const days = priceDays(rows);
  if (days === null) return null;
  return (
    <>
      <p className={`mt-6 text-meta ${MUTED}`}>{daysText(days.first, days.last)}</p>
      <p className={`text-meta ${MUTED}`}>{base}</p>
    </>
  );
}

/**
 * The card link (16 px 600, a 44 px target) and the set line 2 px under it (14 px muted), with
 * the ids the row's accessible name reads them by.
 */
function RowName({ row, id }: { readonly row: ListRow; readonly id: string }) {
  return (
    <>
      <Link
        id={`${id}-name`}
        to={cardVariantPath(row.card_id, row.variant_key)}
        className={`-mt-3 inline-flex min-h-11 items-end text-base font-semibold underline-offset-4 hover:underline ${FOCUS}`}
      >
        {cardLabel(row)}
      </Link>
      <span id={`${id}-detail`} className={`mt-0.5 block text-sm ${MUTED}`}>
        {detailLine(row)}
      </span>
    </>
  );
}

/**
 * One short list row: the card's 48 px picture left, then the name block and a fixed
 * right-aligned price column (stacked under the name below 640 px) with the price and the short
 * change, or `missing`, then `extra` (the holding's value), then the row's controls and its failure
 * across both. The row's accessible name (aria-labelledby) is the name, the set line and the full
 * price lines with the row's own "as of" line, or the missing line. `showDay` prints the row's day
 * under its price, for a list whose rows span several days.
 */
export function ListItem({
  row,
  details,
  missing,
  showDay,
  extra,
  children,
}: {
  readonly row: ListRow;
  readonly details?: ReactNode;
  readonly missing: string;
  readonly showDay: boolean;
  readonly extra?: ReactNode;
  readonly children?: ReactNode;
}) {
  const id = useId();
  const price = row.price === null ? `${id}-missing` : rowPriceIds(id, true);
  return (
    <li aria-labelledby={`${id}-name ${id}-detail ${price}`} className="flex gap-4 py-3">
      <CardThumbnail src={row.card?.image_small} name={row.card?.name ?? ''} />
      <div className="grid min-w-0 flex-1 content-start gap-x-6 sm:grid-cols-[minmax(0,1fr)_minmax(12rem,18rem)]">
        <div className="min-w-0 wrap-anywhere">
          <RowName row={row} id={id} />
          {details}
        </div>
        <div className="mt-1 min-w-0 wrap-anywhere sm:pt-1.5 sm:text-right">
          <PriceLines price={row.price} missing={missing} id={id} showDay={showDay} />
          {extra}
        </div>
        {children}
      </div>
    </li>
  );
}

/**
 * A row's short price (RowPrice: the price and the short change, the full lines and the row's own
 * "as of" line for assistive technology only), or `missing` as one span.
 */
export function PriceLines({
  price,
  missing,
  id,
  showDay = false,
}: {
  readonly price: WatchlistItem['price'];
  readonly missing: string;
  readonly id: string;
  readonly showDay?: boolean;
}) {
  if (price === null) {
    return (
      <span
        id={`${id}-missing`}
        className={`block rounded-md border border-dashed border-line px-3 py-2 text-left text-meta ${MUTED}`}
      >
        {missing}
      </span>
    );
  }
  return (
    <span className="flex flex-col sm:items-end">
      <RowPrice price={price} id={id} day={price.day} showDay={showDay} />
    </span>
  );
}

const QUANTITY_INPUT = `mt-1 w-28 num ${INPUT}`;

/**
 * The quantity form: a labelled whole-number field (1 to 9999) and Save. The browser's own
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
