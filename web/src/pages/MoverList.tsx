// The PokéPrices movers list, shared by the /movers board and the home page's
// biggest-moves strip. Every rank, name, day and amount is the API's: a row's direction
// comes from the sign of its change string, never from change_pct, and nothing reads the clock.
// The strip is a static ticker: no marquee, no animation; it changes only when a poll brings
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
import { PRICE_FOOTNOTE, detailLine, grouped } from '../format';
import { unwrap } from '../query';
import { moversKey } from '../queryKeys';
import { cardPath } from '../router';
import { FOCUS, MUTED, TEXT_LINK } from '../ui/classes';
import DegradedNotice from '../ui/DegradedNotice';
import { AsOf, ChangeChip, PriceFigure } from '../ui/PriceTag';
import ProblemNotice from '../ui/ProblemNotice';
import Skeleton from '../ui/Skeleton';

/** The number of rows each sub-list of the home strip asks for. */
const STRIP_LIMIT = 5;

const LIST_NAME: Record<MoversDirection, string> = { up: 'Rises', down: 'Falls' };

/**
 * One mover row (D2), the same markup on the home strip and the board: rank in a 28 px mono muted
 * column, the card link (16 px 600; its 44 px target reaches up into the row's top padding so the
 * set line sits right under the name) with its set line (14 px muted) beneath, then the price and
 * the chip at the right edge, the chip's tint hugging its text and its text left-aligned. A list at least 32 rem wide (the board) puts them in a right column of their
 * own; a narrower one (a home sub-list, 390 px) puts them under the name. No thumbnail: the movers
 * API carries no image URL.
 */
function MoverRow({ mover }: { readonly mover: Mover }) {
  return (
    <li className="grid min-h-16 grid-cols-[1.75rem_minmax(0,1fr)] gap-x-3 py-2 @lg:grid-cols-[1.75rem_minmax(0,1fr)_minmax(0,20rem)]">
      <span className={`pt-3 num text-sm ${MUTED}`}>{`#${String(mover.rank)}`}</span>
      <div className="min-w-0 wrap-anywhere">
        <Link
          to={cardPath(mover.card_id)}
          className={`-mt-3 inline-flex min-h-11 items-end text-base font-semibold underline-offset-4 hover:underline ${FOCUS}`}
        >
          {mover.card === null ? `Card ${mover.card_id}` : mover.card.name}
        </Link>
        <span className={`block text-sm ${MUTED}`}>{detailLine(mover)}</span>
      </div>
      <div className="col-start-2 flex min-w-0 flex-col items-end @lg:col-start-3 @lg:pt-1.5">
        <PriceFigure close={mover.close} size="row" />
        <ChangeChip price={mover} />
      </div>
    </li>
  );
}

/** The one "as of" line of a list whose rows all share meta.day (D3, D11); none without a day. */
export function MoversStamp({
  day,
  className = 'mt-2',
}: {
  readonly day: string | null;
  readonly className?: string;
}) {
  if (day === null) return null;
  return <AsOf day={day} as="p" className={className} />;
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
  return <DegradedNotice className="mt-4" />;
}

export function MoversFootnote() {
  return <p className={`mt-4 text-meta ${MUTED}`}>{PRICE_FOOTNOTE}</p>;
}

/** A failure with no answer to show (D10). */
export function MoversProblem({ failure }: { readonly failure: ApiFailure }) {
  return <ProblemNotice context="Could not load the movers" failure={failure} className="mt-4" />;
}

export function MoversLoading() {
  return (
    <>
      <p role="status" className="mt-4 text-sm text-ink-muted">
        Loading movers…
      </p>
      <Skeleton className="mt-4" />
    </>
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
          className="@container mt-3 divide-y divide-line-subtle border-y border-line-subtle"
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
  // while both answers carry the same day, else one per sub-list.
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
        <Link to="/movers" className={TEXT_LINK}>
          See all movers
        </Link>
      </p>
    </>
  );
}

/**
 * The home page's "Biggest moves vs previous close" strip: the top five rises and falls of
 * the newest movers day, polled like every query. A failed poll keeps the last good answer;
 * the Problem block shows only while a direction has never answered.
 */
export function BiggestMoves() {
  const headingId = useId();
  const up = useQuery<MoversPage, ApiFailure>({
    queryKey: moversKey('up', STRIP_LIMIT),
    queryFn: ({ signal }) => unwrap(getMovers('up', STRIP_LIMIT, { signal })),
  });
  const down = useQuery<MoversPage, ApiFailure>({
    queryKey: moversKey('down', STRIP_LIMIT),
    queryFn: ({ signal }) => unwrap(getMovers('down', STRIP_LIMIT, { signal })),
  });

  let body;
  let loading = false;
  if (up.data !== undefined && down.data !== undefined) {
    body = <StripLoaded up={up.data} down={down.data} />;
  } else {
    const failure =
      (up.data === undefined ? up.error : null) ?? (down.data === undefined ? down.error : null);
    loading = failure === null;
    body = failure === null ? <MoversLoading /> : <MoversProblem failure={failure} />;
  }

  // While loading, the strip reserves one viewport so the footer starts below the fold (CLS).
  return (
    <section aria-labelledby={headingId} className={loading ? 'mt-12 min-h-svh' : 'mt-12'}>
      <h2 id={headingId} className="text-lg font-semibold">
        Biggest moves vs previous close
      </h2>
      {body}
    </section>
  );
}
