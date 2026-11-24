// Sign out, on the Account page and in the persistent demo strip. The strip is
// the one place the read-only demo is named on a signed-in page: a neutral sunken strip under the
// header bar with the demo sentence as one role-less paragraph and a quiet Sign out at the right
// (omitted on /account, which has its own). It reads the session passively (useKnownSession), so it
// adds no observer and no request, and it leaves once another tab's session signal arrives (A1-7).
import type { ReactNode } from 'react';
import { signOut, type ApiResult } from '../api/client';
import { useRoute } from '../router';
import { ROUTES } from '../routes';
import { ProblemBlock, StatusLine } from './Form';
import { QUIET_BUTTON, SECONDARY_BUTTON } from '../ui/classes';
import { DEMO_NOTICE } from './lists';
import { useAccountWrite, useSessionTransition } from './mutations';
import { useArrivalStatus, useKnownSession } from './session';

/** The line the home page shows once after a sign-out. */
const SIGNED_OUT_LINE = 'You are signed out.';

/**
 * Signs out (POST /logout; a 401 is already signed out), marks ['me'] signed out, signals the other
 * tabs, then opens / with the signed-out line as its arrival status. Given a `heading`, the button sits at the right of the heading's row and a
 * failure's problem block follows the row as a sibling, so it spans the whole panel the row is in.
 */
export function SignOutButton({
  quiet = false,
  className,
  heading,
}: {
  readonly quiet?: boolean;
  readonly className?: string;
  readonly heading?: ReactNode;
}) {
  const transition = useSessionTransition({ clearsMe: true });
  // The data is whether the API confirmed the sign-out. A 401 already asked GET /v1/me again (the
  // query client hears every 401 write), so only a confirmed sign-out asks here: either way, one
  // request.
  const write = useAccountWrite<boolean>({
    onSuccess: (confirmed) => transition('/', { reask: confirmed, status: SIGNED_OUT_LINE }),
  });
  const pending = write.isPending;
  const failure = write.error;

  async function leave(): Promise<ApiResult<boolean>> {
    const result = await signOut();
    if (result.ok) return { ok: true, data: true };
    // A 401 means the session had already ended: that is signed out too, not a failure.
    return result.status === 401 ? { ok: true, data: false } : result;
  }

  const look = quiet ? QUIET_BUTTON : SECONDARY_BUTTON;
  const button = (
    <button
      type="button"
      disabled={pending}
      onClick={() => {
        if (!pending) write.run(leave);
      }}
      className={heading === undefined ? look : `${look} -mr-4 shrink-0`}
    >
      Sign out
    </button>
  );
  const problem = failure !== null && (
    <ProblemBlock context="Could not sign out" failure={failure} />
  );
  if (heading !== undefined) {
    return (
      <>
        <div className={`flex items-center justify-between gap-3 ${className ?? ''}`.trim()}>
          {heading}
          {button}
        </div>
        {problem}
      </>
    );
  }
  return (
    <div className={className}>
      {button}
      {problem}
    </div>
  );
}

function ArrivalLine() {
  const status = useArrivalStatus();
  return status === SIGNED_OUT_LINE ? <StatusLine>{SIGNED_OUT_LINE}</StatusLine> : null;
}

/**
 * The home page's "You are signed out." line: the status the sign-out's navigation carried, in a
 * status region, shown once (useArrivalStatus takes it off the history entry, so a reload, or
 * leaving and coming back, does not show it). Keyed by the known session, so a sign-out that
 * starts on the home page itself (the demo strip) reads the status too.
 */
export function SignedOutLine() {
  const session = useKnownSession();
  return <ArrivalLine key={session === null ? 'signed-out' : 'other'} />;
}

/**
 * The slim demo strip under the header bar, on every route while the known session is the demo.
 * From 640 px it is one 40 px line on every route: the sentence at 13 px and a tighter Sign out
 * whose 44 px target reaches 2 px past the strip's edges; at 390 it wraps to two lines.
 */
export function DemoStrip() {
  const session = useKnownSession();
  const { route } = useRoute();
  if (session === 'unknown' || session === null || !session.read_only) return null;
  return (
    <div className="border-t border-line-subtle bg-surface-sunken text-ink">
      <div className="mx-auto flex min-h-10 max-w-3xl flex-wrap items-center justify-between gap-x-3 px-4 py-1 sm:flex-nowrap sm:px-6 sm:py-0">
        <p className="min-w-0 py-1.5 text-sm sm:text-meta">{DEMO_NOTICE}</p>
        {ROUTES[route.name].footerSettings && (
          <SignOutButton
            quiet
            className="ml-auto flex shrink-0 flex-col items-end sm:-my-0.5 sm:[&>button]:px-2"
          />
        )}
      </div>
    </div>
  );
}
