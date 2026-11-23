// The portfolio estimate in the header, for a signed-in session: one short link to /portfolio
// under the bar ("Portfolio estimate $1,047.93 ▲ +1.64%"), whose accessible name goes on with the
// full sentence: what the figure is (an estimate at daily-close market prices, not a sale value),
// the change vs the previous close, the cards it covers and its source and "as of". It reads the
// ['me'] cache passively (useKnownSession): GET /v1/me carries the figures, so it sends no request
// of its own. Nothing shows signed out, while the session or its answer is not known, for an
// estimate with no priced card, or on /portfolio, which states the total itself. It shows only on
// the pages the stale-prices banner can show on, so its price sits under the same warning.
import {
  ESTIMATE_LABEL,
  cardsLine,
  changeCoverLine,
  daysText,
  estimateChangeLine,
  grouped,
  shortEstimateChange,
} from '../format';
import Link from '../Link';
import { useRoute, type Route } from '../router';
import { FOCUS, MUTED } from '../ui/classes';
import { useKnownSession } from './session';

/** The banner's routes without /portfolio. */
const SHOWN_ON: ReadonlySet<Route['name']> = new Set([
  'home',
  'search',
  'card',
  'movers',
  'watchlist',
]);

const LINK = `inline-flex min-h-11 items-center gap-x-2 text-sm underline-offset-4 hover:underline ${FOCUS}`;

export default function HeaderEstimate() {
  const session = useKnownSession();
  const { route } = useRoute();
  if (session === 'unknown' || session === null || !SHOWN_ON.has(route.name)) return null;
  const estimate = session.portfolio;
  if (estimate === undefined || estimate.total_value === null) return null;
  if (estimate.first_day === null || estimate.last_day === null) return null;
  const short = shortEstimateChange(estimate);
  const change = estimateChangeLine(estimate);
  const cover = changeCoverLine(estimate);
  const label = ESTIMATE_LABEL.charAt(0).toLowerCase() + ESTIMATE_LABEL.slice(1, -1);
  const sentence =
    `: ${label}. ${change.text} ${cardsLine(estimate)}${cover === null ? '' : ` ${cover}`} ` +
    `TCGplayer market prices, ${daysText(estimate.first_day, estimate.last_day)}.`;
  return (
    <div className="border-t border-line-subtle bg-surface">
      <div className="mx-auto flex max-w-3xl justify-end px-4 sm:px-6">
        <Link to="/portfolio" className={LINK}>
          <span className="num font-semibold">{`Portfolio estimate $${grouped(estimate.total_value)}`}</span>
          {short !== null && (
            <>
              {' '}
              <span className={`num ${change.tone}`}>{short}</span>
            </>
          )}
          <span className="sr-only">{sentence}</span>
          <span aria-hidden="true" className={MUTED}>
            ›
          </span>
        </Link>
      </div>
    </div>
  );
}
