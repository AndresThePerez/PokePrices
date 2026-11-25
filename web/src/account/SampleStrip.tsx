// The strip above the demo's sample rows on the signed-out /watchlist and /portfolio: it says the
// rows are sample data from the read-only demo collection, the same for every visitor, that signing
// in gives you your own, and offers the page's way in (Sign in, back to the page, and the one-click
// demo). A labelled region, not a live region: a screen reader meets it once, in reading order.
// It promises no sign-up (accounts are invite-only for the moment); sign-up's own page says so.
import Link from '../Link';
import { TEXT_LINK } from '../ui/classes';
import Notice from '../ui/Notice';
import DemoButton from './DemoButton';

/** The strip's region name. */
export const SAMPLE_LABEL = 'Sample data';

/**
 * The class of the wrapper a signed-out list page keeps through its loading line, its sample and
 * its sign-in prompt: one viewport (svh) minimum, so the page is taller than the screen from the
 * first frame and the footer below it never moves into view, whatever the sample's row count.
 */
export const SAMPLE_RESERVE = 'min-h-svh';

export default function SampleStrip({ list }: { readonly list: 'watchlist' | 'portfolio' }) {
  return (
    <section aria-label={SAMPLE_LABEL} className="mt-6">
      <Notice tone="info">
        <p className="wrap-anywhere">
          {`This is sample data from the read-only demo collection, the same for every visitor. ` +
            `Signing in gives you your own ${list}.`}
        </p>
        <p className="mt-2">
          <Link to={`/sign-in?next=/${list}`} className={TEXT_LINK}>
            Sign in
          </Link>
        </p>
        <DemoButton placement="hero" />
      </Notice>
    </section>
  );
}
