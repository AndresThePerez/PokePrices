// /watchlist (D10): the signed-in account's variants over GET /v1/watchlist, each row the P3
// movers row with its own "as of" line (rows can carry different days). Signed out, it offers
// sign-in, sign-up and the demo; the demo sees its rows without a Remove button. A verified account
// that is not the demo also sets each row's alert threshold here (P5 T07); an unverified one is told
// to verify first. The page asks for nothing beyond the session and the list.
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { useId, useState } from 'react';
import {
  deleteAlert,
  deleteWatchlistItem,
  getWatchlist,
  putAlert,
  type ApiFailure,
  type WatchlistBody,
  type WatchlistItem,
} from '../api/client';
import { variantLabel } from '../format';
import Link from '../Link';
import { unwrap } from '../query';
import { SELECT } from '../ui/classes';
import { MOVEMENT_ABS } from './alerts';
import { ProblemBlock } from './Form';
import { MUTED, SECONDARY_BUTTON, TEXT_LINK, firstErrors } from './fields';
import {
  DegradedLine,
  ListFootnote,
  ListItem,
  ListLoading,
  ListMain,
  PriceLines,
  SignedOutList,
} from './ListParts';
import { NO_PRICE, WATCHLIST_KEY, cardLabel } from './lists';
import { useAccountWrite } from './mutations';
import { useSession } from './session';

const EMPTY = 'Your watchlist is empty. Open a card from Search and choose Add to watchlist.';
const LOADING = 'Loading your watchlist…';
const UNVERIFIED_LINE = 'Verify your email address to set alerts.';

/** Who may do what on this page: remove rows (not the demo), set alerts (verified, not the demo). */
type Access = 'read-only' | 'unverified' | 'alerts';

/** The D10 thresholds; a stored value that is not one of them is offered too, in order. */
const THRESHOLDS: readonly number[] = [5, 10, 20, 50];

function thresholdsWith(stored: number | null): readonly number[] {
  if (stored === null || THRESHOLDS.includes(stored)) return THRESHOLDS;
  return [...THRESHOLDS, stored].sort((a, b) => a - b);
}

const ALERT_SELECT = `mt-1 ${SELECT}`;

/** The select value of a stored threshold. */
function choiceOf(stored: number | null): string {
  return stored === null ? 'off' : String(stored);
}

/** The row's alert: its line, the threshold select and Save alert (Off deletes, a number puts). */
function AlertControl({ item }: { readonly item: WatchlistItem }) {
  const queryClient = useQueryClient();
  const id = useId();
  const errorId = `${id}-error`;
  const [choice, setChoice] = useState(choiceOf(item.alert_pct));
  // Keyed by the variant, so a refetch never remounts the row: a refetched threshold resyncs the
  // select unless it holds an unsaved choice (a save leaves the saved value as the choice).
  const [held, setHeld] = useState(item.alert_pct);
  if (held !== item.alert_pct) {
    setHeld(item.alert_pct);
    if (choice === choiceOf(held)) setChoice(choiceOf(item.alert_pct));
  }
  // A failure leaves the last good list on screen and shows beside the row.
  const write = useAccountWrite({
    onSuccess: () => queryClient.invalidateQueries({ queryKey: WATCHLIST_KEY }),
  });
  const pending = write.isPending;
  const failure = write.error;
  const errors = firstErrors(failure);
  const fieldError = errors.pct ?? errors.variant_key;
  const name = `${cardLabel(item)}, ${variantLabel(item)}`;

  function save(value: string): void {
    const variantKey = item.variant_key;
    write.run(() =>
      value === 'off' ? deleteAlert(variantKey) : putAlert(variantKey, Number(value)),
    );
  }

  return (
    <>
      <p className="mt-2 text-sm font-semibold">
        {item.alert_pct === null ? 'Alert: off' : `Alert: at ${String(item.alert_pct)}%`}
      </p>
      <div className="flex flex-wrap items-end gap-x-2 gap-y-1">
        <div>
          <label htmlFor={id} className="text-sm font-semibold">
            Alert threshold
          </label>
          <select
            id={id}
            name="alert_pct"
            value={choice}
            aria-label={`Alert threshold for ${name}`}
            aria-invalid={fieldError === undefined ? undefined : true}
            aria-describedby={fieldError === undefined ? undefined : errorId}
            onChange={(event) => {
              setChoice(event.target.value);
            }}
            className={ALERT_SELECT}
          >
            <option value="off">Off</option>
            {thresholdsWith(item.alert_pct).map((pct) => (
              <option key={pct} value={String(pct)}>{`${String(pct)}%`}</option>
            ))}
          </select>
        </div>
        <button
          type="button"
          disabled={pending}
          aria-label={`Save the alert for ${name}`}
          onClick={() => {
            save(choice);
          }}
          className={SECONDARY_BUTTON}
        >
          Save alert
        </button>
        {fieldError !== undefined && (
          <p id={errorId} className="basis-full text-sm font-semibold text-problem">
            {fieldError}
          </p>
        )}
      </div>
      {failure !== null && (
        <ProblemBlock
          context="Could not save the alert"
          failure={failure}
          fields={['pct', 'variant_key']}
        />
      )}
    </>
  );
}

/** Under the list: how an alert is decided, and where to choose where it goes. */
function AlertExplainer() {
  return (
    <p className="mt-4 text-sm">
      {`An alert is sent when a card's daily close moves by at least its threshold and at least ` +
        `$${MOVEMENT_ABS} against the previous close. Choose where alerts go on `}
      <Link to="/alerts" className={TEXT_LINK}>
        Alerts and webhooks
      </Link>
      .
    </p>
  );
}

function WatchRow({ item, access }: { readonly item: WatchlistItem; readonly access: Access }) {
  const queryClient = useQueryClient();
  // A failure leaves the last good list on screen and shows beside the row.
  const remove = useAccountWrite({
    onSuccess: () => queryClient.invalidateQueries({ queryKey: WATCHLIST_KEY }),
  });
  const pending = remove.isPending;
  const failure = remove.error;

  return (
    <ListItem row={item} price={<PriceLines price={item.price} missing={NO_PRICE} />}>
      {access !== 'read-only' && (
        <div className="mt-3 flex flex-wrap items-end gap-x-6 gap-y-3 sm:col-span-2">
          {access === 'alerts' && (
            <div className="min-w-0">
              <AlertControl key={item.variant_key} item={item} />
            </div>
          )}
          <button
            type="button"
            disabled={pending}
            aria-label={`Remove ${cardLabel(item)}, ${variantLabel(item)} from your watchlist`}
            onClick={() => {
              if (!pending) remove.run(() => deleteWatchlistItem(item.variant_key));
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
    </ListItem>
  );
}

function Loaded({ body, access }: { readonly body: WatchlistBody; readonly access: Access }) {
  return (
    <>
      {body.meta.degraded && <DegradedLine />}
      {body.data.length === 0 ? (
        <p className="mt-6">{EMPTY}</p>
      ) : (
        <>
          <ul
            aria-label="Watchlist"
            className="mt-6 divide-y divide-line-subtle border-y border-line-subtle"
          >
            {body.data.map((item) => (
              <WatchRow key={item.variant_key} item={item} access={access} />
            ))}
          </ul>
          {access === 'alerts' && <AlertExplainer />}
          {access === 'unverified' && <p className={`mt-4 text-sm ${MUTED}`}>{UNVERIFIED_LINE}</p>}
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
  let access: Access = 'read-only';
  if (session.state === 'signed-in' && !readOnly) {
    access = session.me.email_verified ? 'alerts' : 'unverified';
  }

  let body;
  if (session.state === 'loading') {
    body = <ListLoading>{LOADING}</ListLoading>;
  } else if (session.state === 'failed') {
    body = <ProblemBlock context="Could not load your account" failure={session.failure} />;
  } else if (session.state === 'signed-out') {
    body = <SignedOutList list="watchlist" />;
  } else if (query.data !== undefined) {
    // A failed poll or write keeps the last good list on screen.
    body = <Loaded body={query.data} access={access} />;
  } else if (query.error?.status === 401) {
    body = <SignedOutList list="watchlist" />;
  } else if (query.error !== null) {
    body = <ProblemBlock context="Could not load your watchlist" failure={query.error} />;
  } else {
    body = <ListLoading>{LOADING}</ListLoading>;
  }

  return <ListMain title="Watchlist">{body}</ListMain>;
}
