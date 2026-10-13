// The PokéPrices movers board: /movers over GET /v1/movers?direction=&limit=50 (T09). It shows the
// newest movers day only, with one scope line and one "as of" line for the whole list (D11).
import { useQuery } from '@tanstack/react-query';
import { getMovers, type ApiFailure, type MoversDirection, type MoversPage } from '../api/client';
import { unwrap } from '../query';
import { navigate } from '../router';
import MoverList, {
  MoversDegraded,
  MoversFootnote,
  MoversLoading,
  MoversProblem,
  MoversScope,
} from './MoverList';

const BOARD_LIMIT = 50;

const FOCUS =
  'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-sky-700 dark:focus-visible:outline-sky-400';

/** A pressable button: 44 px tall and wide, the pressed state filled in both themes. */
const TOGGLE =
  `inline-flex min-h-11 min-w-11 items-center justify-center rounded-md border px-4 text-sm font-medium ` +
  `border-slate-300 bg-white text-slate-900 dark:border-slate-600 dark:bg-slate-900 dark:text-slate-100 ` +
  `aria-pressed:border-slate-900 aria-pressed:bg-slate-900 aria-pressed:text-white ` +
  `dark:aria-pressed:border-slate-100 dark:aria-pressed:bg-slate-100 dark:aria-pressed:text-slate-900 ${FOCUS}`;

const DIRECTIONS: readonly {
  readonly direction: MoversDirection;
  readonly label: string;
  readonly path: string;
}[] = [
  { direction: 'up', label: 'Rises', path: '/movers' },
  { direction: 'down', label: 'Falls', path: '/movers?direction=down' },
];

function Board({
  body,
  direction,
}: {
  readonly body: MoversPage;
  readonly direction: MoversDirection;
}) {
  return (
    <>
      {body.meta.degraded && <MoversDegraded />}
      <MoversScope body={body} />
      <MoverList body={body} direction={direction} stamped />
      {body.data.length > 0 && <MoversFootnote />}
    </>
  );
}

export default function MoversPage({ direction }: { readonly direction: MoversDirection }) {
  // Polled every five minutes while the tab is visible (D9); a failed poll keeps the last answer.
  const query = useQuery<MoversPage, ApiFailure>({
    queryKey: ['movers', direction, BOARD_LIMIT],
    queryFn: ({ signal }) => unwrap(getMovers(direction, BOARD_LIMIT, { signal })),
  });

  let body;
  if (query.data !== undefined) {
    body = <Board body={query.data} direction={direction} />;
  } else if (query.error !== null) {
    body = <MoversProblem failure={query.error} />;
  } else {
    body = <MoversLoading />;
  }

  return (
    <main className="mx-auto max-w-3xl p-6">
      <h1 className="text-3xl font-semibold">Biggest moves</h1>
      <div role="group" aria-label="Direction" className="mt-4 flex flex-wrap gap-2">
        {DIRECTIONS.map((option) => (
          <button
            key={option.direction}
            type="button"
            aria-pressed={option.direction === direction}
            onClick={() => {
              navigate(option.path);
            }}
            className={TOGGLE}
          >
            {option.label}
          </button>
        ))}
      </div>
      {body}
    </main>
  );
}
