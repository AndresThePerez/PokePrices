// /portfolio (D9, D10): the signed-in account's holdings over GET /v1/portfolio. Each holding's
// value and the total are the API's numeric strings, printed as they come: the browser never
// multiplies or adds money. A holding without a price has no value and is named as not counted;
// the total says which days it rests on. The summary panel leads; each row shows its quantity as an
// "×n" chip beside the visually hidden "Quantity n" line (RULING f).
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import {
  deleteHolding,
  getPortfolio,
  putHolding,
  type ApiFailure,
  type ApiResult,
  type Holding,
  type PortfolioBody,
} from '../api/client';
import { grouped, variantLabel } from '../format';
import { unwrap } from '../query';
import Panel from '../ui/Panel';
import { ProblemBlock } from './Form';
import { MUTED, SECONDARY_BUTTON, firstErrors } from './fields';
import {
  DegradedLine,
  ListFootnote,
  ListItem,
  ListLoading,
  ListMain,
  PriceLines,
  QuantityForm,
  SignedOutList,
} from './ListParts';
import { PORTFOLIO_KEY, cardLabel, quantityOf } from './lists';
import { useSession } from './session';

const EMPTY = 'Your portfolio is empty. Open a card from Search and choose Add to portfolio.';
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
}: {
  readonly holding: Holding;
  readonly readOnly: boolean;
}) {
  const queryClient = useQueryClient();
  const [pending, setPending] = useState(false);
  const [failure, setFailure] = useState<ApiFailure | null>(null);
  const value = valueLine(holding);

  async function write(send: () => Promise<ApiResult<unknown>>): Promise<void> {
    setPending(true);
    setFailure(null);
    const result = await send();
    setPending(false);
    if (!result.ok) {
      // The last good list stays on screen; the failure shows in the row.
      setFailure(result);
      return;
    }
    await queryClient.invalidateQueries({ queryKey: PORTFOLIO_KEY });
  }

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
      price={
        <>
          <PriceLines price={holding.price} missing={NOT_COUNTED} />
          {value !== null && <span className="mt-1 block num text-sm font-semibold">{value}</span>}
        </>
      }
    >
      {!readOnly && (
        <div className="mt-3 flex flex-wrap items-end gap-x-6 gap-y-3 sm:col-span-2">
          <QuantityForm
            key={quantity}
            initial={quantity}
            pending={pending}
            error={firstErrors(failure).quantity}
            onSave={(text) => {
              void write(() => putHolding(holding.variant_key, quantityOf(text)));
            }}
          />
          <button
            type="button"
            disabled={pending}
            aria-label={`Remove ${cardLabel(holding)}, ${variantLabel(holding)} from your portfolio`}
            onClick={() => {
              if (!pending) void write(() => deleteHolding(holding.variant_key));
            }}
            className={SECONDARY_BUTTON}
          >
            Remove
          </button>
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
  return meta.first_day === meta.last_day
    ? `as of ${meta.last_day} from TCGCSV (daily close)`
    : `Closes from ${meta.first_day} to ${meta.last_day} from TCGCSV (daily close)`;
}

function unpricedLine(count: number): string | null {
  if (count <= 0) return null;
  return count === 1
    ? '1 holding has no price in the collected snapshots and is not counted.'
    : `${String(count)} holdings have no price in the collected snapshots and are not counted.`;
}

/** The summary panel: the API's total, the days it rests on and what it leaves out (D9). */
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
  return (
    <Panel className="mt-6">
      <p className="num text-2xl font-semibold wrap-anywhere sm:text-4xl">{`Total value $${grouped(meta.total_value)}`}</p>
      {days !== null && <p className={`mt-1 text-meta ${MUTED}`}>{days}</p>}
      {unpriced !== null && <p className="mt-3 text-sm">{unpriced}</p>}
    </Panel>
  );
}

function Loaded({ body, readOnly }: { readonly body: PortfolioBody; readonly readOnly: boolean }) {
  return (
    <>
      {body.meta.degraded && <DegradedLine />}
      {body.data.length === 0 ? (
        <p className="mt-6">{EMPTY}</p>
      ) : (
        <>
          <Total meta={body.meta} />
          <ul
            aria-label="Portfolio"
            className="mt-6 divide-y divide-line-subtle border-y border-line-subtle"
          >
            {body.data.map((holding) => (
              <HoldingRow key={holding.variant_key} holding={holding} readOnly={readOnly} />
            ))}
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
  // Polled like every query (P3 D9); asked for only once the session is known to be signed in.
  const query = useQuery<PortfolioBody, ApiFailure>({
    queryKey: PORTFOLIO_KEY,
    queryFn: ({ signal }) => unwrap(getPortfolio({ signal })),
    enabled: signedIn,
  });
  const readOnly = session.state === 'signed-in' && session.me.read_only;

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
