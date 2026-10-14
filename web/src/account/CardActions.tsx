// The card page's Add to watchlist and Add to portfolio controls (D10), on every variant row of the
// Prices list. Nothing here fetches before a click: the public card page keeps its exact request
// list. Opening Add to portfolio reads PORTFOLIO_KEY once, to prefill the quantity already held.
// The two controls are <input type="button">: their words are the input's value, not text in the
// row, so a row's text stays the price lines it was (P3 exact-text tests).
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { useId, useState } from 'react';
import {
  getPortfolio,
  putHolding,
  putWatchlistItem,
  type ApiFailure,
  type PortfolioBody,
} from '../api/client';
import { variantLabel, type VariantNaming } from '../format';
import Link from '../Link';
import { unwrap } from '../query';
import { ProblemBlock } from './Form';
import { SECONDARY_BUTTON, TEXT_LINK, firstErrors } from './fields';
import { QuantityForm } from './ListParts';
import { PORTFOLIO_KEY, WATCHLIST_KEY, isDemoReadOnly, quantityOf, signInPath } from './lists';

const WATCH_CONTEXT = 'Could not change your watchlist';
const HOLD_CONTEXT = 'Could not change your portfolio';
const RESULT = 'mt-2 flex flex-wrap items-center gap-x-3 text-sm';

type Outcome =
  | { readonly kind: 'watched' }
  | { readonly kind: 'held'; readonly quantity: number }
  | { readonly kind: 'sign-in' }
  | { readonly kind: 'read-only' }
  | { readonly kind: 'failed'; readonly context: string; readonly failure: ApiFailure };

/** A refused write: signed out, the read-only demo, or the Problem block (D10). */
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

function OutcomeLine({ outcome, cardId }: { readonly outcome: Outcome; readonly cardId: string }) {
  switch (outcome.kind) {
    case 'watched':
      return (
        <p role="status" className={RESULT}>
          <span>On your watchlist.</span>
          <Link to="/watchlist" className={TEXT_LINK}>
            See your watchlist
          </Link>
        </p>
      );
    case 'held':
      return (
        <p role="status" className={RESULT}>
          <span>{`In your portfolio: ${String(outcome.quantity)}.`}</span>
          <Link to="/portfolio" className={TEXT_LINK}>
            See your portfolio
          </Link>
        </p>
      );
    case 'sign-in':
      return <SignInPrompt cardId={cardId} />;
    case 'read-only':
      return (
        <p role="status" className={RESULT}>
          <span>The demo account is read-only.</span>
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

/** The opened Add to portfolio form: it reads the portfolio once, then sets the quantity. */
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
  const portfolio = useQuery<PortfolioBody, ApiFailure>({
    queryKey: PORTFOLIO_KEY,
    queryFn: ({ signal }) => unwrap(getPortfolio({ signal })),
  });
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
    body = (
      <p role="status" className="mt-2 text-sm text-slate-600 dark:text-slate-400">
        Loading your portfolio…
      </p>
    );
  }
  return (
    <div id={id} className="mt-3">
      {body}
    </div>
  );
}

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
  const [open, setOpen] = useState(false);
  const [pending, setPending] = useState(false);
  const [outcome, setOutcome] = useState<Outcome | null>(null);

  async function watch(): Promise<void> {
    setPending(true);
    setOutcome(null);
    const result = await putWatchlistItem(variant.variant_key);
    setPending(false);
    if (!result.ok) {
      setOutcome(refusal(result, WATCH_CONTEXT));
      return;
    }
    setOutcome({ kind: 'watched' });
    await queryClient.invalidateQueries({ queryKey: WATCHLIST_KEY });
  }

  async function hold(text: string): Promise<void> {
    setPending(true);
    setOutcome(null);
    const result = await putHolding(variant.variant_key, quantityOf(text));
    setPending(false);
    if (!result.ok) {
      setOutcome(refusal(result, HOLD_CONTEXT));
      return;
    }
    setOpen(false);
    setOutcome({ kind: 'held', quantity: result.data.data.quantity });
    await queryClient.invalidateQueries({ queryKey: PORTFOLIO_KEY });
  }

  const quantityError =
    open && outcome?.kind === 'failed' ? firstErrors(outcome.failure).quantity : undefined;

  return (
    <div className="mt-3">
      <div className="flex flex-wrap gap-2">
        <input
          type="button"
          value="Add to watchlist"
          aria-label={`Add ${label} to your watchlist`}
          disabled={pending}
          onClick={() => {
            if (!pending) void watch();
          }}
          className={SECONDARY_BUTTON}
        />
        <input
          type="button"
          value="Add to portfolio"
          aria-label={`Add ${label} to your portfolio`}
          aria-expanded={open}
          aria-controls={open ? formId : undefined}
          onClick={() => {
            setOpen(!open);
            setOutcome(null);
          }}
          className={SECONDARY_BUTTON}
        />
      </div>
      {open && (
        <HoldingForm
          id={formId}
          cardId={cardId}
          variantKey={variant.variant_key}
          pending={pending}
          error={quantityError}
          onSave={(text) => {
            void hold(text);
          }}
        />
      )}
      {outcome !== null && <OutcomeLine outcome={outcome} cardId={cardId} />}
    </div>
  );
}
