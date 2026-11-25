// The home page's "See it working" section, below the movers strip: the demo's portfolio total and
// its change on the day, three of its watched cards and one alert sample, each part linking to its
// page. It reads the demo's three public routes (no cookie, no session; the query keys are the ones
// the signed-out /watchlist, /portfolio and /alerts use, so a visitor who goes on finds the data
// already there) and shows itself only when all three have answered. Every part is labelled as
// sample data from the read-only demo collection, and every price keeps its source and "as of" day.
//
// The space is reserved before the answers: one wrapper (an empty div while loading: no text, no bar, nothing for assistive technology) holds the section's final height
// (SEE_IT_RESERVE, at 390 px and from 640 px) while the reads are pending and keeps it when the
// section is loaded, the same node throughout. It is the last thing in the page's main, after the
// movers strip, so when a read fails and the wrapper goes, nothing above it moves and only the
// footer below the fold rises. It never blocks the content above it: the queries run beside the
// page's own and nothing waits for them.
import { useId } from 'react';
import { type ApiFailure, type DemoAlertsBody } from '../api/client';
import { MOVEMENT_ABS, useSampleAlerts } from '../account/alerts';
import { ListItem, ListStamp } from '../account/ListParts';
import { NO_PRICE, useSamplePortfolioQuery, useSampleWatchlistQuery } from '../account/lists';
import { ESTIMATE_LABEL, daysText, estimateChangeLine, grouped, variantLabel } from '../format';
import Link from '../Link';
import { MUTED, TEXT_LINK } from '../ui/classes';

/**
 * The wrapper's classes, on the loading frame and on the loaded section alike: the section's
 * height with room to spare, at 390 px (rem, so it follows the text size) and from 640 px, where
 * the lines wrap less. The loaded content never needs more, so the wrapper's box is the same
 * height before and after the answers.
 */
export const SEE_IT_RESERVE = 'mt-12 min-h-[85rem] sm:min-h-[72rem]';

const TITLE = 'See it working';
const SAMPLE_WORDS = 'Sample data from the read-only demo collection, the same for every visitor.';
const WATCHED = 3;
const PART =
  'mt-6 rounded-lg border border-line bg-surface-raised px-4 py-4 text-ink shadow-panel sm:px-6';

type Portfolio = NonNullable<ReturnType<typeof useSamplePortfolioQuery>['data']>;
type Watchlist = NonNullable<ReturnType<typeof useSampleWatchlistQuery>['data']>;
type Samples = DemoAlertsBody['data'];

function PortfolioPart({ body }: { readonly body: Portfolio }) {
  const meta = body.meta;
  const change = estimateChangeLine(meta);
  const days =
    meta.first_day !== null && meta.last_day !== null
      ? daysText(meta.first_day, meta.last_day)
      : null;
  return (
    <div className={PART}>
      <h3 className="text-base font-semibold">Sample portfolio</h3>
      <p className={`mt-1 text-sm ${MUTED}`}>{SAMPLE_WORDS}</p>
      {meta.total_value === null ? (
        <p className="mt-3">The demo portfolio holds no priced cards.</p>
      ) : (
        <>
          <p className={`mt-3 mb-1 text-meta ${MUTED}`}>{ESTIMATE_LABEL}</p>
          <p className="num text-2xl font-semibold wrap-anywhere">{`Total value $${grouped(meta.total_value)}`}</p>
          {days !== null && <p className={`mt-1 text-meta ${MUTED}`}>{days}</p>}
          <p className={`mt-3 num text-base font-semibold ${change.tone}`}>{change.text}</p>
        </>
      )}
      <Link to="/portfolio" className={`mt-1 ${TEXT_LINK}`}>
        Open the sample portfolio
      </Link>
    </div>
  );
}

function WatchlistPart({ body }: { readonly body: Watchlist }) {
  const rows = body.data.slice(0, WATCHED);
  return (
    <div className={PART}>
      <h3 className="text-base font-semibold">Sample watchlist</h3>
      <p className={`mt-1 text-sm ${MUTED}`}>{SAMPLE_WORDS}</p>
      {rows.length === 0 ? (
        <p className="mt-3">The demo watchlist is empty.</p>
      ) : (
        <>
          <ListStamp rows={rows} base="Change vs previous close." />
          <ul
            aria-label="Sample watchlist"
            className="mt-3 divide-y divide-line-subtle border-y border-line-subtle"
          >
            {rows.map((row) => (
              <ListItem key={row.variant_key} row={row} missing={NO_PRICE} showDay={false} />
            ))}
          </ul>
        </>
      )}
      <Link to="/watchlist" className={`mt-1 ${TEXT_LINK}`}>
        Open the sample watchlist
      </Link>
    </div>
  );
}

function AlertPart({ alert }: { readonly alert: Samples['alert'] }) {
  return (
    <div className={PART}>
      <h3 className="text-base font-semibold">Sample alert</h3>
      <p className={`mt-1 text-sm ${MUTED}`}>{SAMPLE_WORDS}</p>
      {alert === null ? (
        <p className="mt-3">The demo has no alert sample.</p>
      ) : (
        <>
          <p className="mt-3 font-semibold wrap-anywhere">
            {`${alert.card === null ? `Card ${alert.card_id}` : alert.card.name} · ${variantLabel(alert)}`}
          </p>
          <p className="mt-1">
            {`An alert at ${String(alert.alert_pct)}% (and at least $${MOVEMENT_ABS}) on the demo's ` +
              `watchlist: an email when this card's daily close moves by that much against the ` +
              `previous close.`}
          </p>
        </>
      )}
      <Link to="/alerts" className={`mt-1 ${TEXT_LINK}`}>
        Open the sample alert
      </Link>
    </div>
  );
}

/** True for a read that has no answer to show and has failed; a failed poll keeps its last answer. */
function failedFirst(query: {
  readonly data: unknown;
  readonly error: ApiFailure | null;
}): boolean {
  return query.data === undefined && query.error !== null;
}

export default function SeeItWorking() {
  const headingId = useId();
  const watchlist = useSampleWatchlistQuery(true);
  const portfolio = useSamplePortfolioQuery(true);
  const alerts = useSampleAlerts(true);
  if (failedFirst(watchlist) || failedFirst(portfolio) || failedFirst(alerts)) return null;
  if (watchlist.data === undefined || portfolio.data === undefined || alerts.data === undefined) {
    return <div className={SEE_IT_RESERVE} />;
  }
  return (
    <div className={SEE_IT_RESERVE}>
      <section aria-labelledby={headingId}>
        <h2 id={headingId} className="text-lg font-semibold">
          {TITLE}
        </h2>
        <p className={`mt-1 text-sm ${MUTED}`}>
          A look at the watchlist, the portfolio and an alert, read from the demo's collection with
          no sign-in.
        </p>
        <PortfolioPart body={portfolio.data} />
        <WatchlistPart body={watchlist.data} />
        <AlertPart alert={alerts.data.data.alert} />
      </section>
    </div>
  );
}
