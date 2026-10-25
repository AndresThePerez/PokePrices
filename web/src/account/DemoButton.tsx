// Try the read-only demo (D8, D10): POST /v1/demo/session signs this browser in to the shared,
// seeded demo account (after the CSRF cookie, like every account write), then the session is read
// again and the demo's watchlist opens. The server refuses every write of the demo.
import { useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { startDemo, type ApiFailure } from '../api/client';
import { navigate } from '../router';
import { ProblemBlock } from './Form';
import { SECONDARY_BUTTON, TEXT_LINK } from './fields';
import { ME_KEY, endSession } from './session';

/**
 * Where the button sits: `panel` (sign-in and the signed-out lists) is an outlined button under a
 * hairline; `hero` (the home landing, under the search form) is an underlined 16 px accent link
 * on the field's left edge, with a 44 px target and no rule.
 */
export type DemoPlacement = 'panel' | 'hero';

const WRAPPER: Readonly<Record<DemoPlacement, string>> = {
  panel: 'mt-8 border-t border-line-subtle pt-6',
  hero: 'mt-2',
};

const LOOK: Readonly<Record<DemoPlacement, string>> = {
  panel: SECONDARY_BUTTON,
  hero: `${TEXT_LINK} text-base font-semibold text-accent disabled:opacity-60`,
};

export default function DemoButton({
  placement = 'panel',
}: {
  readonly placement?: DemoPlacement;
}) {
  const queryClient = useQueryClient();
  const [pending, setPending] = useState(false);
  const [failure, setFailure] = useState<ApiFailure | null>(null);

  async function start(): Promise<void> {
    setPending(true);
    setFailure(null);
    const result = await startDemo();
    if (!result.ok) {
      setPending(false);
      setFailure(result);
      return;
    }
    // Nothing of another account shows under the demo's session.
    endSession(queryClient);
    await queryClient.invalidateQueries({ queryKey: ME_KEY, refetchType: 'all' });
    navigate('/watchlist');
  }

  return (
    <div className={WRAPPER[placement]}>
      <button
        type="button"
        disabled={pending}
        onClick={() => {
          if (!pending) void start();
        }}
        className={LOOK[placement]}
      >
        Try the read-only demo
      </button>
      {failure !== null && <ProblemBlock context="Could not start the demo" failure={failure} />}
    </div>
  );
}
