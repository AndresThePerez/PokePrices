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
import { cardVariantPath } from '../routes';
import CardThumbnail from '../ui/CardThumbnail';
import { FOCUS, MUTED, TEXT_LINK } from '../ui/classes';
import StatusLine from '../ui/StatusLine';
import DegradedNotice from '../ui/DegradedNotice';
import { AsOf, RowPrice } from '../ui/PriceTag';
import { rowPriceIds } from '../ui/rowPriceIds';
import ProblemNotice from '../ui/ProblemNotice';
import Skeleton from '../ui/Skeleton';

/** The number of rows each sub-list of the home strip asks for. */
const STRIP_LIMIT = 5;

const LIST_NAME: Record<MoversDirection, string> = { up: 'Rises', down: 'Falls' };

/**
 * One mover row, the same markup on the home strip and the board, short because the list prints
 * its source and "as of" once: rank in a 28 px mono muted column, the card's 48 px picture, then
 * the card link (16 px 600; its 44 px target reaches 10 px up into the row's top padding and 10 px
 * down over the set line, so the name takes one 24 px line), its set line (14 px muted) and one
 * 20 px line with the price and the short change chip: about 86 px a row at 390 px, about 106 px
 * when the set line wraps. The row's accessible name (aria-labelledby) is the name, the set line,
 * the full "Market price" and change lines and the list's stamp (`stampId`).
 */
function MoverRow({ mover, stampId }: { readonly mover: Mover; readonly stampId: string }) {
  const id = useId();
  const name = mover.card === null ? `Card ${mover.card_id}` : mover.card.name;
  return (
    <li
      aria-labelledby={`${id}-name ${id}-detail ${rowPriceIds(id, false)} ${stampId}`}
      className="grid grid-cols-[1.75rem_3rem_minmax(0,1fr)] items-start gap-x-3 py-2"
    >
      <span className={`pt-1 num text-sm ${MUTED}`}>{`#${String(mover.rank)}`}</span>
      <CardThumbnail src={mover.card?.image_small} name={mover.card?.name ?? ''} />
      <div className="min-w-0 wrap-anywhere">
        <Link
          id={`${id}-name`}
          to={cardVariantPath(mover.card_id, mover.variant_key)}
          className={`-my-2.5 inline-flex min-h-11 items-center text-base font-semibold underline-offset-4 hover:underline ${FOCUS}`}
        >
          {name}
        </Link>
        <span id={`${id}-detail`} className={`block text-sm ${MUTED}`}>
          {detailLine(mover)}
        </span>
        <div className="mt-1">
          <RowPrice price={mover} id={id} />
        </div>
      </div>
    </li>
  );
}

/** The one "as of" line of a list whose rows all share meta.day; none without a day. */
export function MoversStamp({
  day,
  id,
  className = 'mt-2',
}: {
  readonly day: string | null;
  readonly id?: string;
  readonly className?: string;
}) {
  if (day === null) return null;
  return <AsOf day={day} as="p" id={id} className={className} />;
}

/**
 * What the ranking counts, as the API ranks it: the floor of the previous close from the answer,
 * then the move, recent-activity and listing conditions the ranking applies on top of it.
 */
export function MoversScope({ body }: { readonly body: MoversPage }) {
  return (
    <p className={`mt-3 text-sm ${MUTED}`}>
      {`Ranked by change vs previous close among variants whose previous close was at least $${grouped(body.meta.min_previous_close)}, that moved at least 5% and at least $1.00, had four different closes among their last five, and whose two closes each sat between half the lowest listing and 1.5 times the mid listing of their own day.`}
    </p>
  );
}

/** The degraded catalog line, shown when an answer's names come from a stale copy. */
export function MoversDegraded() {
  return <DegradedNotice className="mt-4" />;
}

export function MoversFootnote() {
  return <p className={`mt-4 text-meta ${MUTED}`}>{PRICE_FOOTNOTE}</p>;
}

/** A failure with no answer to show. */
export function MoversProblem({ failure }: { readonly failure: ApiFailure }) {
  return <ProblemNotice context="Could not load the movers" failure={failure} className="mt-4" />;
}

export function MoversLoading() {
  return (
    <>
      <StatusLine gap="mt-4">Loading movers…</StatusLine>
      <Skeleton className="mt-4" />
    </>
  );
}

/**
 * One direction's ranked rows, or its empty state: "No movers yet" before any movers day,
 * "No rises/falls ranked for <day>" when the day has none in this direction. `stampId` is the id
 * of the "as of" line the caller printed for this list; without it the list prints its own first.
 * Either way each row's accessible name ends with that one stamp.
 */
export default function MoverList({
  body,
  direction,
  stampId,
}: {
  readonly body: MoversPage;
  readonly direction: MoversDirection;
  readonly stampId?: string | undefined;
}) {
  const ownStamp = useId();
  const day = body.meta.day;
  if (day === null) return <p className="mt-3">No movers yet</p>;
  const name = LIST_NAME[direction];
  return (
    <>
      {stampId === undefined && <MoversStamp day={day} id={ownStamp} />}
      {body.data.length === 0 ? (
        <p className="mt-3">{`No ${name.toLowerCase()} ranked for ${day}`}</p>
      ) : (
        <ol
          aria-label={name}
          className="mt-3 divide-y divide-line-subtle border-y border-line-subtle"
        >
          {body.data.map((mover) => (
            <MoverRow key={mover.variant_key} mover={mover} stampId={stampId ?? ownStamp} />
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
  const stampId = useId();
  return (
    <>
      {(up.meta.degraded || down.meta.degraded) && <MoversDegraded />}
      <MoversScope body={up} />
      {sameDay && <MoversStamp day={up.meta.day} id={stampId} />}
      <div className="mt-2 grid gap-x-8 gap-y-4 sm:grid-cols-2">
        {[
          { direction: 'up' as const, body: up },
          { direction: 'down' as const, body: down },
        ].map((list) => (
          <div key={list.direction} className="min-w-0">
            <h3 className="mt-4 text-base font-semibold">{LIST_NAME[list.direction]}</h3>
            <MoverList
              body={list.body}
              direction={list.direction}
              stampId={sameDay ? stampId : undefined}
            />
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
