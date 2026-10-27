// Sign out (D10), on the Account page and in the persistent demo strip (D2 RULING e). The strip is
// the one place the read-only demo is named on a signed-in page: a neutral sunken strip under the
// header bar with the demo sentence as one role-less paragraph and a quiet Sign out at the right
// (omitted on /account, which has its own). It reads the session passively (useKnownSession), so it
// adds no observer and no request, and it leaves once another tab's session signal arrives (A1-7).
import { useQueryClient } from '@tanstack/react-query';
import { useState, type ReactNode } from 'react';
import { signOut, type ApiFailure } from '../api/client';
import { navigate, useRoute } from '../router';
import { ProblemBlock } from './Form';
import { QUIET_BUTTON, SECONDARY_BUTTON } from './fields';
import { DEMO_NOTICE } from './lists';
import { ME_KEY, endSession, useKnownSession } from './session';

/**
 * Signs out (POST /logout; a 401 is already signed out), marks ['me'] signed out, signals the other
 * tabs, then opens /. Given a `heading`, the button sits at the right of the heading's row and a
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
  const queryClient = useQueryClient();
  const [pending, setPending] = useState(false);
  const [failure, setFailure] = useState<ApiFailure | null>(null);

  async function leave(): Promise<void> {
    setPending(true);
    setFailure(null);
    const result = await signOut();
    setPending(false);
    // A 401 means the session had already ended: that is signed out too, not a failure.
    if (!result.ok && result.status !== 401) {
      setFailure(result);
      return;
    }
    // Signed out at once (no stale "Signed in as" on the next visit), then confirmed by the API. A
    // 401 already asked GET /v1/me again (the query client hears every 401 write), so only a
    // successful sign-out asks here: either way, one request.
    queryClient.setQueryData(ME_KEY, null);
    endSession(queryClient);
    if (result.ok) void queryClient.invalidateQueries({ queryKey: ME_KEY });
    navigate('/');
  }

  const look = quiet ? QUIET_BUTTON : SECONDARY_BUTTON;
  const button = (
    <button
      type="button"
      disabled={pending}
      onClick={() => {
        if (!pending) void leave();
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
        {route.name !== 'account' && (
          <SignOutButton
            quiet
            className="ml-auto flex shrink-0 flex-col items-end sm:-my-0.5 sm:[&>button]:px-2"
          />
        )}
      </div>
    </div>
  );
}
