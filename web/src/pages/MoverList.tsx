// The PokéPrices movers list (D3, D10, D11), shared by the /movers board and the home page's
// biggest-moves strip. Every rank, name, day and amount is the API's (R132): a row's direction
// comes from the sign of its change string, never from change_pct, and nothing reads the clock.
// The strip is a static ticker: no marquee, no animation; it changes only when a D9 poll brings
// a new answer.
import { useId } from 'react';
import { useQuery } from '@tanstack/react-query';
import Link from '../Link';
import {
  getMovers,
  type ApiFailure,
  type Mover,
  type MoversDirection,
  type MoversPage,
} from '../api/client';
import { PRICE_FOOTNOTE, grouped, variantLabel } from '../format';
import { unwrap } from '../query';
import { cardPath } from '../router';
import { AsOf, ChangeChip, PriceFigure } from '../ui/PriceTag';

const DEGRADED_LINE = 'Catalog data may be out of date: PokéSearch is unavailable';
const FOCUS =
  'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-sky-700 dark:focus-visible:outline-sky-400';
const MUTED = 'text-slate-600 dark:text-slate-400';
const FAINT = 'text-slate-500 dark:text-slate-400';

/** The number of rows each sub-list of the home strip asks for. */
const STRIP_LIMIT = 5;

const LIST_NAME: Record<MoversDirection, string> = { up: 'Rises', down: 'Falls' };

function capitalised(label: string): string {
  return label.charAt(0).toUpperCase() + label.slice(1);
}

/** The second line of a row: set and number when the catalog named the card, then the variant. */
function detailLine(mover: Mover): string {
  const variant = capitalised(variantLabel(mover));
  if (mover.card === null) return variant;
  return `${mover.card.set_name} No. ${mover.card.number} · ${variant}`;
}

function MoverRow({ mover }: { readonly mover: Mover }) {
  return (
    <li className="grid grid-cols-[2.75rem_minmax(0,1fr)] gap-x-2 py-3">
      <span className={`pt-2.5 text-lg leading-6 font-semibold tabular-nums ${FAINT}`}>
        {`#${String(mover.rank)}`}
      </span>
      <div className="min-w-0 wrap-anywhere">
        <Link
          to={cardPath(mover.card_id)}
          className={`inline-flex min-h-11 items-center font-semibold underline-offset-4 hover:underline ${FOCUS}`}
        >
          {mover.card === null ? `Card ${mover.card_id}` : mover.card.name}
        </Link>
        <span className={`block text-sm ${MUTED}`}>{detailLine(mover)}</span>
        <PriceFigure close={mover.close} size="row" />
        <ChangeChip price={mover} />
      </div>
    </li>
  );
}

/** The one "as of" line of a list whose rows all share meta.day (D3, D11); none without a day. */
export function MoversStamp({ day }: { readonly day: string | null }) {
  if (day === null) return null;
  return <AsOf day={day} as="p" className="mt-2" />;
}

/** What the ranking counts (D11). */
export function MoversScope({ body }: { readonly body: MoversPage }) {
  return (
    <p className={`mt-3 text-sm ${MUTED}`}>
      {`Ranked by change vs previous close among variants whose previous close was at least $${grouped(body.meta.min_previous_close)}.`}
    </p>
  );
}

/** The degraded catalog line (D10), shown when an answer's names come from a stale copy. */
export function MoversDegraded() {
  return (
    <p className="mt-4 border-l-4 border-amber-500 bg-amber-50 px-4 py-3 text-amber-950 dark:border-amber-400 dark:bg-amber-950 dark:text-amber-100">
      {DEGRADED_LINE}
    </p>
  );
}

export function MoversFootnote() {
  return <p className={`mt-4 text-xs leading-snug ${FAINT}`}>{PRICE_FOOTNOTE}</p>;
}

/** A failure with no answer to show (D10). */
export function MoversProblem({ failure }: { readonly failure: ApiFailure }) {
  return (
    <div
      role="alert"
      className="mt-4 border-l-4 border-red-700 bg-red-50 px-4 py-3 text-red-950 dark:border-red-400 dark:bg-red-950 dark:text-red-100"
    >
      <p className="font-semibold">Could not load the movers: {failure.title}</p>
      {failure.requestId !== null && (
        <p className="mt-1 text-sm">
          Request ID <code className="break-all">{failure.requestId}</code>
        </p>
      )}
    </div>
  );
}

export function MoversLoading() {
  return (
    <p role="status" className={`mt-4 text-sm ${MUTED}`}>
      Loading movers…
    </p>
  );
}

/**
 * One direction's ranked rows, or its empty state (D11): "No movers yet" before any movers day,
 * "No rises/falls ranked for <day>" when the day has none in this direction. With `stamped`, the
 * list prints its own "as of" line first.
 */
export default function MoverList({
  body,
  direction,
  stamped,
}: {
  readonly body: MoversPage;
  readonly direction: MoversDirection;
  readonly stamped: boolean;
}) {
  const day = body.meta.day;
  if (day === null) return <p className="mt-3">No movers yet</p>;
  const name = LIST_NAME[direction];
  return (
    <>
      {stamped && <MoversStamp day={day} />}
      {body.data.length === 0 ? (
        <p className="mt-3">{`No ${name.toLowerCase()} ranked for ${day}`}</p>
      ) : (
        <ol
          aria-label={name}
          className="mt-3 divide-y divide-slate-200 border-y border-slate-200 dark:divide-slate-700 dark:border-slate-700"
        >
          {body.data.map((mover) => (
            <MoverRow key={mover.variant_key} mover={mover} />
          ))}
        </ol>
      )}
    </>
  );
}

function StripLoaded({ up, down }: { readonly up: MoversPage; readonly down: MoversPage }) {
  // The two directions are separate, separately polled requests: one stamp for the section only
  // while both answers carry the same day, else one per sub-list (review F-P3-05).
  const sameDay = up.meta.day === down.meta.day;
  const hasRows = up.data.length > 0 || down.data.length > 0;
  return (
    <>
      {(up.meta.degraded || down.meta.degraded) && <MoversDegraded />}
      <MoversScope body={up} />
      {sameDay && <MoversStamp day={up.meta.day} />}
      <div className="mt-2 grid gap-x-8 gap-y-4 sm:grid-cols-2">
        {[
          { direction: 'up' as const, body: up },
          { direction: 'down' as const, body: down },
        ].map((list) => (
          <div key={list.direction} className="min-w-0">
            <h3 className="mt-4 text-base font-semibold">{LIST_NAME[list.direction]}</h3>
            <MoverList body={list.body} direction={list.direction} stamped={!sameDay} />
          </div>
        ))}
      </div>
      {hasRows && <MoversFootnote />}
      <p className="mt-4">
        <Link
          to="/movers"
          className={`inline-flex min-h-11 items-center underline underline-offset-4 ${FOCUS}`}
        >
          See all movers
        </Link>
      </p>
    </>
  );
}

/**
 * The home page's "Biggest moves vs previous close" strip (T10): the top five rises and falls of
 * the newest movers day, polled like every query (D9). A failed poll keeps the last good answer;
 * the Problem block shows only while a direction has never answered.
 */
export function BiggestMoves() {
  const headingId = useId();
  const up = useQuery<MoversPage, ApiFailure>({
    queryKey: ['movers', 'up', STRIP_LIMIT],
    queryFn: ({ signal }) => unwrap(getMovers('up', STRIP_LIMIT, { signal })),
  });
  const down = useQuery<MoversPage, ApiFailure>({
    queryKey: ['movers', 'down', STRIP_LIMIT],
    queryFn: ({ signal }) => unwrap(getMovers('down', STRIP_LIMIT, { signal })),
  });

  let body;
  if (up.data !== undefined && down.data !== undefined) {
    body = <StripLoaded up={up.data} down={down.data} />;
  } else {
    const failure =
      (up.data === undefined ? up.error : null) ?? (down.data === undefined ? down.error : null);
    body = failure === null ? <MoversLoading /> : <MoversProblem failure={failure} />;
  }

  return (
    <section aria-labelledby={headingId} className="mt-10">
      <h2 id={headingId} className="text-xl font-semibold">
        Biggest moves vs previous close
      </h2>
      {body}
    </section>
  );
}
