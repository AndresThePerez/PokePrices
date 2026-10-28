// The PokéPrices movers board: /movers over GET /v1/movers?direction=&limit=50 (T09). It shows the
// newest movers day only, with one scope line and one "as of" line for the whole list (D11): the
// stamp is the caption of the sticky Direction bar (D2), so it stays beside the rows as they scroll.
import { useQuery } from '@tanstack/react-query';
import { getMovers, type ApiFailure, type MoversDirection, type MoversPage } from '../api/client';
import { unwrap } from '../query';
import { moversKey } from '../queryKeys';
import { navigate } from '../router';
import { Button } from '../ui/Button';
import { TEXT_LINK } from '../ui/classes';
import MoverList, {
  MoversDegraded,
  MoversFootnote,
  MoversLoading,
  MoversProblem,
  MoversScope,
  MoversStamp,
} from './MoverList';

const BOARD_LIMIT = 50;

const DIRECTIONS: readonly {
  readonly direction: MoversDirection;
  readonly label: string;
  readonly path: string;
}[] = [
  { direction: 'up', label: 'Rises', path: '/movers' },
  { direction: 'down', label: 'Falls', path: '/movers?direction=down' },
];

/** The rows (or the day's empty line), then the footnote and Back to top when there are rows. */
function Board({
  body,
  direction,
}: {
  readonly body: MoversPage;
  readonly direction: MoversDirection;
}) {
  const hasRows = body.data.length > 0;
  return (
    <>
      <MoverList body={body} direction={direction} stamped={false} />
      {hasRows && <MoversFootnote />}
      {hasRows && (
        <p className="mt-6">
          <a href="#content" className={TEXT_LINK}>
            Back to top
          </a>
        </p>
      )}
    </>
  );
}

export default function MoversPage({ direction }: { readonly direction: MoversDirection }) {
  // Polled every five minutes while the tab is visible (D9); a failed poll keeps the last answer.
  const query = useQuery<MoversPage, ApiFailure>({
    queryKey: moversKey(direction, BOARD_LIMIT),
    queryFn: ({ signal }) => unwrap(getMovers(direction, BOARD_LIMIT, { signal })),
  });
  const answer = query.data;

  let body;
  if (answer !== undefined) {
    body = <Board body={answer} direction={direction} />;
  } else if (query.error !== null) {
    body = <MoversProblem failure={query.error} />;
  } else {
    body = <MoversLoading />;
  }

  // The children keep their positions in every state (a slot is null, never dropped), so the
  // sticky bar is never remounted and a pressed segment keeps focus through Rises to Falls.
  // While loading, the page reserves one viewport so the footer starts below the fold (CLS).
  const loading = answer === undefined && query.error === null;
  return (
    <main className={loading ? 'mx-auto min-h-svh max-w-3xl p-6' : 'mx-auto max-w-3xl p-6'}>
      <h1 className="text-2xl font-semibold">Biggest moves</h1>
      {answer?.meta.degraded === true ? <MoversDegraded /> : null}
      {answer === undefined ? null : <MoversScope body={answer} />}
      <div className="sticky top-0 z-10 -mx-6 mt-4 flex flex-wrap items-center gap-x-4 gap-y-1 border-b border-line-subtle bg-surface px-6 py-2">
        <div role="group" aria-label="Direction" className="flex gap-2">
          {DIRECTIONS.map((option) => (
            <Button
              key={option.direction}
              variant="segment"
              pressed={option.direction === direction}
              onClick={() => {
                navigate(option.path);
              }}
            >
              {option.label}
            </Button>
          ))}
        </div>
        <MoversStamp day={answer?.meta.day ?? null} className="sm:ml-auto" />
      </div>
      {body}
    </main>
  );
}
