// The card page's list controls, on every variant row of the Prices list, under the row's lines
// (right-aligned from 640 px). While the session is not known (no ['me'] answer: a visitor
// without the session cookie, see PublicSession) the row has Add to watchlist and Add to
// portfolio and nothing here fetches before a click, so the public card page keeps its exact
// request list. Once ['me'] names an account, the row reads the two lists (one shared query each,
// never one per variant) and says, for as long as they are true, "On your watchlist." with Remove
// and "In your portfolio: N." with Edit and Remove, both at once; the Add control of a list the
// variant is on gives way to its line, and comes back, focused, when the variant is removed. The
// buttons are <input type="button">: their words are the input's value, not text in the row, so a
// row's text stays the price lines it was (P3 exact-text tests). A session known to be the
// read-only demo (useReadOnly) sees the lines and no control that writes; one not yet known gets
// the Add controls, and a click is answered by the calm demo sentence.
import { useQueryClient } from '@tanstack/react-query';
import { useEffect, useId, useRef, useState, type ReactNode } from 'react';
import {
  deleteHolding,
  deleteWatchlistItem,
  putHolding,
  putWatchlistItem,
  type ApiFailure,
  type HoldingEntry,
  type PortfolioBody,
} from '../api/client';
import { variantLabel, type VariantNaming } from '../format';
import Link from '../Link';
import StatusLine from '../ui/StatusLine';
import { ProblemBlock } from './Form';
import { QUIET_BUTTON, TEXT_LINK } from '../ui/classes';
import { firstErrors } from './fields';
import { QuantityForm } from './ListParts';
import { usePortfolioQuery, useWatchlistQuery } from './listQueries';
import {
  DEMO_READ_ONLY_LINE,
  PORTFOLIO_KEY,
  WATCHLIST_KEY,
  isDemoReadOnly,
  quantityOf,
  signInPath,
} from './lists';
import { useAccountWrite, useWriteGuard } from './mutations';
import { useKnownSession, useReadOnly } from './session';

const WATCH_CONTEXT = 'Could not change your watchlist';
const HOLD_CONTEXT = 'Could not change your portfolio';
const RESULT = 'mt-2 flex flex-wrap items-center gap-x-3 text-sm';

type Outcome =
  | { readonly kind: 'watched' }
  | { readonly kind: 'held'; readonly quantity: number }
  | { readonly kind: 'sign-in' }
  | { readonly kind: 'read-only' }
  | { readonly kind: 'failed'; readonly context: string; readonly failure: ApiFailure };

/** A refused write: signed out, the read-only demo, or the Problem block. */
function refusal(failure: ApiFailure, context: string): Outcome {
  if (failure.status === 401) return { kind: 'sign-in' };
  if (isDemoReadOnly(failure)) return { kind: 'read-only' };
  return { kind: 'failed', context, failure };
}

function SignInPrompt({ cardId }: { readonly cardId: string }) {
  return (
    <p role="status" className={RESULT}>
      <span>Sign in to keep a watchlist and a portfolio.</span>
      <Link to={signInPath(cardId)} className={TEXT_LINK}>
        Sign in
      </Link>
    </p>
  );
}

/** "On your watchlist." with its link, and `children` (the line's controls) after it. */
function WatchedLine({ children }: { readonly children?: ReactNode }) {
  return (
    <p role="status" className={RESULT}>
      <span>On your watchlist.</span>
      <Link to="/watchlist" className={TEXT_LINK}>
        See your watchlist
      </Link>
      {children}
    </p>
  );
}

/** "In your portfolio: N." with its link, and `children` (the line's controls) after it. */
function HeldLine({
  quantity,
  children,
}: {
  readonly quantity: number;
  readonly children?: ReactNode;
}) {
  return (
    <p role="status" className={RESULT}>
      <span>{`In your portfolio: ${String(quantity)}.`}</span>
      <Link to="/portfolio" className={TEXT_LINK}>
        See your portfolio
      </Link>
      {children}
    </p>
  );
}

function OutcomeLine({ outcome, cardId }: { readonly outcome: Outcome; readonly cardId: string }) {
  switch (outcome.kind) {
    case 'watched':
      return <WatchedLine />;
    case 'held':
      return <HeldLine quantity={outcome.quantity} />;
    case 'sign-in':
      return <SignInPrompt cardId={cardId} />;
    case 'read-only':
      return (
        <p role="status" className={RESULT}>
          <span>{DEMO_READ_ONLY_LINE}</span>
        </p>
      );
    case 'failed':
      return (
        <ProblemBlock context={outcome.context} failure={outcome.failure} fields={['quantity']} />
      );
  }
}

/** The quantity held of this variant, or 1 when the portfolio does not hold it. */
function heldQuantity(body: PortfolioBody, variantKey: string): string {
  const holding = body.data.find((one) => one.variant_key === variantKey);
  return holding === undefined ? '1' : String(holding.quantity);
}

/**
 * The opened quantity form (Add to portfolio, or Edit): it reads the portfolio once, then sets
 * the quantity. A typed quantity survives a refetch (QuantityForm's draft); another account's
 * forgotten portfolio shows the loading line first, which drops it, and a successful save closes
 * the form.
 */
function HoldingForm({
  id,
  cardId,
  variantKey,
  pending,
  error,
  onSave,
}: {
  readonly id: string;
  readonly cardId: string;
  readonly variantKey: string;
  readonly pending: boolean;
  readonly error: string | undefined;
  readonly onSave: (text: string) => void;
}) {
  const portfolio = usePortfolioQuery();
  let body;
  if (portfolio.error?.status === 401) {
    body = <SignInPrompt cardId={cardId} />;
  } else if (portfolio.data !== undefined) {
    body = (
      <QuantityForm
        initial={heldQuantity(portfolio.data, variantKey)}
        pending={pending}
        error={error}
        onSave={onSave}
      />
    );
  } else if (portfolio.error !== null) {
    body = <ProblemBlock context="Could not load your portfolio" failure={portfolio.error} />;
  } else {
    body = <StatusLine gap="mt-2">Loading your portfolio…</StatusLine>;
  }
  return (
    <div id={id} className="mt-3">
      {body}
    </div>
  );
}

/** The control that takes focus once a write has changed which controls the row shows. */
type Refocus = 'add-watch' | 'add-hold' | 'remove-watch' | 'remove-hold' | 'edit' | null;

export default function CardActions({
  cardId,
  variant,
}: {
  readonly cardId: string;
  readonly variant: VariantNaming;
}) {
  const queryClient = useQueryClient();
  const formId = useId();
  const label = variantLabel(variant);
  const key = variant.variant_key;
  const [open, setOpen] = useState(false);
  const [outcome, setOutcome] = useState<Outcome | null>(null);
  const [refocus, setRefocus] = useState<Refocus>(null);
  const addWatchRef = useRef<HTMLInputElement>(null);
  const addHoldRef = useRef<HTMLInputElement>(null);
  const removeWatchRef = useRef<HTMLInputElement>(null);
  const removeHoldRef = useRef<HTMLInputElement>(null);
  const editRef = useRef<HTMLInputElement>(null);
  const guard = useWriteGuard();
  const readOnly = useReadOnly();
  const known = useKnownSession();
  const signedIn = known !== 'unknown' && known !== null;
  const watchlist = useWatchlistQuery(signedIn);
  const portfolio = usePortfolioQuery(signedIn);
  // The lists' answers, when a known session has read them: null while they are not known (a
  // portfolio read by the Add to portfolio form of an unknown session does not count).
  const watchBody = signedIn ? watchlist.data : undefined;
  const holdBody = signedIn ? portfolio.data : undefined;
  const watched = watchBody?.data.some((item) => item.variant_key === key) ?? null;
  const holding = holdBody?.data.find((one) => one.variant_key === key) ?? null;
  const held = holdBody === undefined ? null : holding !== null;

  const watch = useAccountWrite(
    {
      onMutate: () => {
        setOutcome(null);
      },
      onSuccess: async () => {
        setOutcome({ kind: 'watched' });
        await queryClient.invalidateQueries({ queryKey: WATCHLIST_KEY });
        if (signedIn) setRefocus('remove-watch');
      },
      onError: (failure) => {
        setOutcome(refusal(failure, WATCH_CONTEXT));
      },
    },
    guard,
  );
  const hold = useAccountWrite<HoldingEntry>(
    {
      onMutate: () => {
        setOutcome(null);
      },
      onSuccess: async (entry) => {
        setOpen(false);
        setOutcome({ kind: 'held', quantity: entry.data.quantity });
        await queryClient.invalidateQueries({ queryKey: PORTFOLIO_KEY });
        if (signedIn) setRefocus('edit');
      },
      onError: (failure) => {
        setOutcome(refusal(failure, HOLD_CONTEXT));
      },
    },
    guard,
  );
  const unwatch = useAccountWrite(
    {
      onMutate: () => {
        setOutcome(null);
      },
      onSuccess: async () => {
        await queryClient.invalidateQueries({ queryKey: WATCHLIST_KEY });
        setRefocus('add-watch');
      },
      onError: (failure) => {
        setOutcome(refusal(failure, WATCH_CONTEXT));
        setRefocus('remove-watch');
      },
    },
    guard,
  );
  const unhold = useAccountWrite(
    {
      onMutate: () => {
        setOutcome(null);
        setOpen(false);
      },
      onSuccess: async () => {
        await queryClient.invalidateQueries({ queryKey: PORTFOLIO_KEY });
        setRefocus('add-hold');
      },
      onError: (failure) => {
        setOutcome(refusal(failure, HOLD_CONTEXT));
        setRefocus('remove-hold');
      },
    },
    guard,
  );
  // One write at a time on a row, as one pending flag before.
  const pending = watch.isPending || hold.isPending || unwatch.isPending || unhold.isPending;

  // A write that takes its control away hands focus to the control that takes its place (the Add
  // a removal gives back, the line's Remove or Edit after an Add), and a failed removal back to its
  // Remove once it is enabled again: never to the page body.
  useEffect(() => {
    if (refocus === null) return;
    const target = {
      'add-watch': addWatchRef,
      'add-hold': addHoldRef,
      'remove-watch': removeWatchRef,
      'remove-hold': removeHoldRef,
      edit: editRef,
    }[refocus].current;
    if (target !== null && !target.disabled) {
      target.focus();
      setRefocus(null);
    }
  }, [refocus, watched, held, pending]);

  const quantityError =
    open && outcome?.kind === 'failed' ? firstErrors(outcome.failure).quantity : undefined;

  // The lines say what the lists hold; a write's own outcome line gives way to them.
  const shownOutcome =
    (outcome?.kind === 'watched' && watched === true) || (outcome?.kind === 'held' && held === true)
      ? null
      : outcome;

  if (readOnly) {
    if (watched !== true && holding === null) return null;
    return (
      <div className="mt-3">
        {watched === true && <WatchedLine />}
        {holding !== null && <HeldLine quantity={holding.quantity} />}
      </div>
    );
  }
  return (
    <div className="mt-3">
      {(watched !== true || held !== true) && (
        <div className="flex flex-wrap gap-2 sm:justify-end">
          {watched !== true && (
            <input
              ref={addWatchRef}
              type="button"
              value="Add to watchlist"
              aria-label={`Add ${label} to your watchlist`}
              disabled={pending}
              onClick={() => {
                if (!pending) watch.run(() => putWatchlistItem(key));
              }}
              className={QUIET_BUTTON}
            />
          )}
          {held !== true && (
            <input
              ref={addHoldRef}
              type="button"
              value="Add to portfolio"
              aria-label={`Add ${label} to your portfolio`}
              aria-expanded={open}
              aria-controls={open ? formId : undefined}
              onClick={() => {
                setOpen(!open);
                setOutcome(null);
              }}
              className={QUIET_BUTTON}
            />
          )}
        </div>
      )}
      {watched === true && (
        <WatchedLine>
          <input
            ref={removeWatchRef}
            type="button"
            value="Remove"
            aria-label={`Remove ${label} from your watchlist`}
            disabled={pending}
            onClick={() => {
              if (!pending) unwatch.run(() => deleteWatchlistItem(key));
            }}
            className={QUIET_BUTTON}
          />
        </WatchedLine>
      )}
      {holding !== null && (
        <HeldLine quantity={holding.quantity}>
          <input
            ref={editRef}
            type="button"
            value="Edit"
            aria-label={`Edit how many ${label} you hold`}
            aria-expanded={open}
            aria-controls={open ? formId : undefined}
            onClick={() => {
              setOpen(!open);
              setOutcome(null);
            }}
            className={QUIET_BUTTON}
          />
          <input
            ref={removeHoldRef}
            type="button"
            value="Remove"
            aria-label={`Remove ${label} from your portfolio`}
            disabled={pending}
            onClick={() => {
              if (!pending) unhold.run(() => deleteHolding(key));
            }}
            className={QUIET_BUTTON}
          />
        </HeldLine>
      )}
      {open && (
        <HoldingForm
          id={formId}
          cardId={cardId}
          variantKey={key}
          pending={pending}
          error={quantityError}
          onSave={(text) => {
            hold.run(() => putHolding(key, quantityOf(text)));
          }}
        />
      )}
      {shownOutcome !== null && <OutcomeLine outcome={shownOutcome} cardId={cardId} />}
    </div>
  );
}
