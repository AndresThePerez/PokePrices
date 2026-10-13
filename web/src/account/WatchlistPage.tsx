// /watchlist (D10): the signed-in account's variants over GET /v1/watchlist, each row the P3
// movers row with its own "as of" line (rows can carry different days). Signed out, it offers
// sign-in, sign-up and the demo; the demo sees its rows without a Remove button.
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import {
  deleteWatchlistItem,
  getWatchlist,
  type ApiFailure,
  type WatchlistBody,
  type WatchlistItem,
} from '../api/client';
import { variantLabel } from '../format';
import { unwrap } from '../query';
import { ProblemBlock } from './Form';
import { SECONDARY_BUTTON } from './fields';
import {
  DegradedLine,
  DemoNotice,
  ListFootnote,
  ListLoading,
  ListMain,
  PriceLines,
  RowName,
  SignedOutList,
} from './ListParts';
import { NO_PRICE, WATCHLIST_KEY, cardLabel } from './lists';
import { useSession } from './session';

const EMPTY = 'Your watchlist is empty. Open a card from Search and choose Add to watchlist.';
const LOADING = 'Loading your watchlist…';

function WatchRow({
  item,
  readOnly,
}: {
  readonly item: WatchlistItem;
  readonly readOnly: boolean;
}) {
  const queryClient = useQueryClient();
  const [pending, setPending] = useState(false);
  const [failure, setFailure] = useState<ApiFailure | null>(null);

  async function remove(): Promise<void> {
    setPending(true);
    setFailure(null);
    const result = await deleteWatchlistItem(item.variant_key);
    setPending(false);
    if (!result.ok) {
      // The last good list stays on screen; the failure shows beside the row.
      setFailure(result);
      return;
    }
    await queryClient.invalidateQueries({ queryKey: WATCHLIST_KEY });
  }

  return (
    <li className="grid gap-x-4 py-4 sm:grid-cols-[minmax(0,1fr)_auto]">
      <div className="min-w-0 wrap-anywhere">
        <RowName row={item} />
        <PriceLines price={item.price} missing={NO_PRICE} />
      </div>
      {!readOnly && (
        <div className="mt-3 sm:mt-0">
          <button
            type="button"
            disabled={pending}
            aria-label={`Remove ${cardLabel(item)}, ${variantLabel(item)} from your watchlist`}
            onClick={() => {
              if (!pending) void remove();
            }}
            className={SECONDARY_BUTTON}
          >
            Remove
          </button>
        </div>
      )}
      {failure !== null && (
        <div className="sm:col-span-2">
          <ProblemBlock context="Could not change your watchlist" failure={failure} />
        </div>
      )}
    </li>
  );
}

function Loaded({ body, readOnly }: { readonly body: WatchlistBody; readonly readOnly: boolean }) {
  return (
    <>
      {body.meta.degraded && <DegradedLine />}
      {body.data.length === 0 ? (
        <p className="mt-6">{EMPTY}</p>
      ) : (
        <>
          <ul
            aria-label="Watchlist"
            className="mt-6 divide-y divide-slate-200 border-y border-slate-200 dark:divide-slate-700 dark:border-slate-700"
          >
            {body.data.map((item) => (
              <WatchRow key={item.variant_key} item={item} readOnly={readOnly} />
            ))}
          </ul>
          <ListFootnote />
        </>
      )}
    </>
  );
}

export default function WatchlistPage() {
  const session = useSession();
  const signedIn = session.state === 'signed-in';
  // Polled like every query (P3 D9); asked for only once the session is known to be signed in.
  const query = useQuery<WatchlistBody, ApiFailure>({
    queryKey: WATCHLIST_KEY,
    queryFn: ({ signal }) => unwrap(getWatchlist({ signal })),
    enabled: signedIn,
  });
  const readOnly = session.state === 'signed-in' && session.me.read_only;

  let body;
  if (session.state === 'loading') {
    body = <ListLoading>{LOADING}</ListLoading>;
  } else if (session.state === 'failed') {
    body = <ProblemBlock context="Could not load your account" failure={session.failure} />;
  } else if (session.state === 'signed-out') {
    body = <SignedOutList list="watchlist" />;
  } else if (query.data !== undefined) {
    // A failed poll or write keeps the last good list on screen.
    body = <Loaded body={query.data} readOnly={readOnly} />;
  } else if (query.error?.status === 401) {
    body = <SignedOutList list="watchlist" />;
  } else if (query.error !== null) {
    body = <ProblemBlock context="Could not load your watchlist" failure={query.error} />;
  } else {
    body = <ListLoading>{LOADING}</ListLoading>;
  }

  return (
    <ListMain title="Watchlist">
      {readOnly && <DemoNotice />}
      {body}
    </ListMain>
  );
}
