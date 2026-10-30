// Try the read-only demo: POST /v1/demo/session signs this browser in to the shared,
// seeded demo account (after the CSRF cookie, like every account write), then the session is read
// again and the demo's watchlist opens. The server refuses every write of the demo.
import { useId } from 'react';
import { startDemo } from '../api/client';
import Panel from '../ui/Panel';
import { ProblemBlock } from './Form';
import { SECONDARY_BUTTON, TEXT_LINK } from '../ui/classes';
import { useAccountWrite, useSessionTransition } from './mutations';

/**
 * Where the button sits: `panel` (sign-in and the signed-out lists) is an outlined button under a
 * hairline; `hero` (the home landing, under the search form) is an underlined 16 px accent link
 * on the field's left edge, with a 44 px target and no rule; `block` (the Just looking? panel of
 * sign-in and sign-up) is a full-width outlined button with no rule.
 */
type DemoPlacement = 'panel' | 'hero' | 'block';

const WRAPPER: Readonly<Record<DemoPlacement, string>> = {
  panel: 'mt-8 border-t border-line-subtle pt-6',
  hero: 'mt-2',
  block: 'mt-4',
};

const LOOK: Readonly<Record<DemoPlacement, string>> = {
  panel: SECONDARY_BUTTON,
  hero: `${TEXT_LINK} text-base font-semibold text-accent disabled:opacity-60`,
  block: `${SECONDARY_BUTTON} w-full`,
};

export default function DemoButton({
  placement = 'panel',
}: {
  readonly placement?: DemoPlacement;
}) {
  const transition = useSessionTransition({ clearsMe: false });
  // Nothing of another account shows under the demo's session.
  const write = useAccountWrite<undefined>({ onSuccess: () => transition('/watchlist') });
  const pending = write.isPending;
  const failure = write.error;

  return (
    <div className={WRAPPER[placement]}>
      <button
        type="button"
        disabled={pending}
        onClick={() => {
          if (!pending) write.run(startDemo);
        }}
        className={LOOK[placement]}
      >
        Try the read-only demo
      </button>
      {failure !== null && <ProblemBlock context="Could not start the demo" failure={failure} />}
    </div>
  );
}

/**
 * The Just looking? panel under the sign-in and sign-up panels: the same centred 420 px
 * width, an h2, the demo sentence and the full-width demo button.
 */
export function DemoPanel() {
  const headingId = useId();
  return (
    <Panel
      as="section"
      aria-labelledby={headingId}
      className="mx-auto mt-6 w-full max-w-[420px] max-sm:px-4 sm:py-6"
    >
      <h2 id={headingId} className="text-lg font-semibold">
        Just looking?
      </h2>
      <p className="mt-2 text-base">
        Open the read-only demo account to see a watchlist and a portfolio without signing up.
      </p>
      <DemoButton placement="block" />
    </Panel>
  );
}
