// The PokéPrices movers board: /movers over GET /v1/movers?direction=&limit=50 (T09). It shows the
// newest movers day only, with one scope line and one "as of" line for the whole list (D11).
import { useQuery } from '@tanstack/react-query';
import { getMovers, type ApiFailure, type MoversDirection, type MoversPage } from '../api/client';
import { unwrap } from '../query';
import { navigate } from '../router';
import { Button } from '../ui/Button';
import MoverList, {
  MoversDegraded,
  MoversFootnote,
  MoversLoading,
  MoversProblem,
  MoversScope,
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
      <h1 className="text-2xl font-semibold">Biggest moves</h1>
      <div role="group" aria-label="Direction" className="mt-4 flex flex-wrap gap-2">
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
      {body}
    </main>
  );
}
