// /portfolio: the signed-in account's holdings over GET /v1/portfolio. Each holding's
// value and the total are the API's numeric strings, printed as they come: the browser never
// multiplies or adds money. A holding without a price has no value and is named as not counted;
// the total says which days it rests on, the list's one source-and-"as of" line, so each row is
// short (its own day only when the days differ). The summary panel leads: the total, labelled an
// estimate at daily-close market prices, not a sale value, with the day's change against the
// previous close and the card counts the API sends beside it; each row shows its quantity as an
// "×n" chip beside the visually hidden "Quantity n" line. A saved quantity says "Saved."; Remove
// leaves "Removed." with Undo in the row's place (RemovedLine), which puts the holding back with
// its quantity.
import { useQueryClient } from '@tanstack/react-query';
import { useCallback, useEffect, useRef, useState } from 'react';
import { deleteHolding, putHolding, type Holding, type PortfolioBody } from '../api/client';
import {
  ESTIMATE_LABEL,
  cardsLine,
  changeCoverLine,
  daysText,
  estimateChangeLine,
  grouped,
  variantLabel,
} from '../format';
import Panel from '../ui/Panel';
import { ProblemBlock, StatusLine } from './Form';
import { MUTED, SECONDARY_BUTTON } from '../ui/classes';
import { firstErrors } from './fields';
import {
  DegradedLine,
  ListFootnote,
  ListItem,
  ListLoading,
  ListMain,
  QuantityForm,
  SignedOutList,
} from './ListParts';
import { usePortfolioQuery } from './listQueries';
import { PORTFOLIO_KEY, cardLabel, quantityOf } from './lists';
import { useAccountWrite, useWriteGuard } from './mutations';
import RemovedLine from './RemovedLine';
import { useRemoval } from './removal';
import { useReadOnly, useSession } from './session';

const EMPTY = 'Your portfolio is empty. Open a card from Search and choose Add to portfolio.';
/**
 * What each short row's change is measured against, printed once under the total's days line: the
 * card's daily close against its previous close, never a gain or loss on the holding.
 */
const BASE = "Change is each card's close vs its previous close, not a gain on your holding.";
const LOADING = 'Loading your portfolio…';
const NOT_COUNTED = 'No price in the collected snapshots; not counted in the total.';
const CONTEXT = 'Could not change your portfolio';

/** "Value $<value> (<quantity> × $<close>)": both amounts are the API's strings. */
function valueLine(holding: Holding): string | null {
  if (holding.value === null || holding.price === null) return null;
  return `Value $${grouped(holding.value)} (${String(holding.quantity)} × $${grouped(holding.price.close)})`;
}

function HoldingRow({
  holding,
  readOnly,
  showDay,
  onRemoved,
}: {
  readonly holding: Holding;
  readonly readOnly: boolean;
  readonly showDay: boolean;
  readonly onRemoved: (holding: Holding) => void;
}) {
  const queryClient = useQueryClient();
  // Successful saves: a new one (and nothing else, not a refetch) drops the quantity form's typed
  // draft, once the list was read again, and shows "Saved." until the next write on the row.
  const [saves, setSaves] = useState(0);
  const [saved, setSaved] = useState(false);
  // The row's last write, whose failure the row shows.
  const [last, setLast] = useState<'save' | 'remove'>('save');
  const removeRef = useRef<HTMLButtonElement>(null);
  // Save and Remove share one guard: one write at a time on a row.
  const guard = useWriteGuard();
  // A failure leaves the last good list on screen and shows in the row.
  const write = useAccountWrite(
    {
      onMutate: () => {
        setSaved(false);
        setLast('save');
      },
      onSuccess: async () => {
        await queryClient.invalidateQueries({ queryKey: PORTFOLIO_KEY });
        setSaves((count) => count + 1);
        setSaved(true);
      },
    },
    guard,
  );
  // Removed: the row gives way to its "Removed." line at once, then the list is read again; a
  // failed removal leaves the row with its problem and gives focus back to its Remove.
  const remove = useAccountWrite(
    {
      onMutate: () => {
        setSaved(false);
        setLast('remove');
      },
      onSuccess: async () => {
        onRemoved(holding);
        await queryClient.invalidateQueries({ queryKey: PORTFOLIO_KEY });
      },
    },
    guard,
  );
  const pending = write.isPending || remove.isPending;
  const failure = last === 'save' ? write.error : remove.error;
  const value = valueLine(holding);
  // Each new removal failure gives focus back to Remove, once it is enabled again.
  const removeFailure = remove.error;
  useEffect(() => {
    if (removeFailure !== null && !pending) removeRef.current?.focus();
  }, [removeFailure, pending]);

  const quantity = String(holding.quantity);
  return (
    <ListItem
      row={holding}
      details={
        <span className="mt-2 block">
          <span
            aria-hidden="true"
            className="inline-block rounded-sm border border-line px-1.5 num text-sm font-semibold"
          >
            {`×${quantity}`}
          </span>
          <span className="sr-only">{`Quantity ${quantity}`}</span>
        </span>
      }
      missing={NOT_COUNTED}
      showDay={showDay}
      extra={
        value !== null && <span className="mt-1 block num text-sm font-semibold">{value}</span>
      }
    >
      {!readOnly && (
        <div className="mt-3 flex flex-wrap items-end gap-x-6 gap-y-3 sm:col-span-2">
          <QuantityForm
            initial={quantity}
            saves={saves}
            pending={pending}
            error={firstErrors(write.error).quantity}
            onSave={(text) => {
              write.run(() => putHolding(holding.variant_key, quantityOf(text)));
            }}
          />
          <button
            ref={removeRef}
            type="button"
            disabled={pending}
            aria-label={`Remove ${cardLabel(holding)}, ${variantLabel(holding)} from your portfolio`}
            onClick={() => {
              if (!pending) remove.run(() => deleteHolding(holding.variant_key));
            }}
            className={SECONDARY_BUTTON}
          >
            Remove
          </button>
        </div>
      )}
      {saved && failure === null && (
        <div className="sm:col-span-2">
          <StatusLine>Saved.</StatusLine>
        </div>
      )}
      {failure !== null && (
        <div className="sm:col-span-2">
          <ProblemBlock context={CONTEXT} failure={failure} fields={['quantity']} />
        </div>
      )}
    </ListItem>
  );
}

/** The days a total rests on: one day as its "as of" line, several as their first and last. */
function daysLine(meta: PortfolioBody['meta']): string | null {
  if (meta.first_day === null || meta.last_day === null) return null;
  return daysText(meta.first_day, meta.last_day);
}

function unpricedLine(count: number): string | null {
  if (count <= 0) return null;
  return count === 1
    ? '1 holding has no price in the collected snapshots and is not counted.'
    : `${String(count)} holdings have no price in the collected snapshots and are not counted.`;
}

/**
 * The summary panel: what the total is (an estimate at daily-close market prices, not a sale
 * value), the API's total, the days it rests on (the list's one stamp, which the change shares),
 * what it leaves out, the day's change against the previous close, the card counts and how many
 * cards the change leaves out, and what each row's change is measured against.
 */
function Total({ meta }: { readonly meta: PortfolioBody['meta'] }) {
  if (meta.total_value === null) {
    return (
      <Panel className="mt-6">
        <p className="text-base">No holding has a price in the collected snapshots yet.</p>
      </Panel>
    );
  }
  const days = daysLine(meta);
  const unpriced = unpricedLine(meta.unpriced);
  const change = estimateChangeLine(meta);
  const cover = changeCoverLine(meta);
  return (
    <Panel className="mt-6">
      <p className={`mb-1 text-meta ${MUTED}`}>{ESTIMATE_LABEL}</p>
      <p className="num text-2xl font-semibold wrap-anywhere sm:text-4xl">{`Total value $${grouped(meta.total_value)}`}</p>
      {days !== null && <p className={`mt-1 text-meta ${MUTED}`}>{days}</p>}
      {unpriced !== null && <p className="mt-3 text-sm">{unpriced}</p>}
      <p className={`mt-3 num text-base font-semibold ${change.tone}`}>{change.text}</p>
      <p className="mt-1 text-sm">{cardsLine(meta)}</p>
      {cover !== null && <p className="mt-1 text-sm">{cover}</p>}
      {days !== null && <p className={`mt-3 text-meta ${MUTED}`}>{BASE}</p>}
    </Panel>
  );
}

function Loaded({ body, readOnly }: { readonly body: PortfolioBody; readonly readOnly: boolean }) {
  const queryClient = useQueryClient();
  const { removal, shown, removed } = useRemoval(body.data, body);
  const undo = useAccountWrite({
    onSuccess: () => queryClient.invalidateQueries({ queryKey: PORTFOLIO_KEY }),
  });
  const { reset } = undo;
  const onRemoved = useCallback(
    (holding: Holding) => {
      reset();
      removed(holding);
    },
    [reset, removed],
  );
  const showDay = body.meta.first_day !== body.meta.last_day;
  const rows = shown.map((holding) => (
    <HoldingRow
      key={holding.variant_key}
      holding={holding}
      readOnly={readOnly}
      showDay={showDay}
      onRemoved={onRemoved}
    />
  ));
  if (removal !== null) {
    const holding = removal.row;
    const at = Math.min(removal.index, rows.length);
    rows.splice(
      at,
      0,
      <RemovedLine
        key={`removed:${holding.variant_key}`}
        index={at}
        undoLabel={`Undo removing ${cardLabel(holding)}, ${variantLabel(holding)} from your portfolio`}
        pending={undo.isPending}
        failure={undo.error}
        onUndo={() => {
          undo.run(() => putHolding(holding.variant_key, holding.quantity));
        }}
      />,
    );
  }
  return (
    <>
      {body.meta.degraded && <DegradedLine />}
      {body.data.length === 0 && removal === null ? (
        <p className="mt-6">{EMPTY}</p>
      ) : (
        <>
          {body.data.length > 0 && <Total meta={body.meta} />}
          <ul
            aria-label="Portfolio"
            className="mt-6 divide-y divide-line-subtle border-y border-line-subtle"
          >
            {rows}
          </ul>
          <ListFootnote />
        </>
      )}
    </>
  );
}

export default function PortfolioPage() {
  const session = useSession();
  const signedIn = session.state === 'signed-in';
  // Polled like every query; asked for only once the session is known to be signed in.
  const query = usePortfolioQuery(signedIn);
  const readOnly = useReadOnly();

  let body;
  if (session.state === 'loading') {
    body = <ListLoading>{LOADING}</ListLoading>;
  } else if (session.state === 'failed') {
    body = <ProblemBlock context="Could not load your account" failure={session.failure} />;
  } else if (session.state === 'signed-out') {
    body = <SignedOutList list="portfolio" />;
  } else if (query.data !== undefined) {
    // A failed poll or write keeps the last good list on screen.
    body = <Loaded body={query.data} readOnly={readOnly} />;
  } else if (query.error?.status === 401) {
    body = <SignedOutList list="portfolio" />;
  } else if (query.error !== null) {
    body = <ProblemBlock context="Could not load your portfolio" failure={query.error} />;
  } else {
    body = <ListLoading>{LOADING}</ListLoading>;
  }

  return <ListMain title="Portfolio">{body}</ListMain>;
}
