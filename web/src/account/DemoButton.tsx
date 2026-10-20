// Try the read-only demo (D8, D10): POST /v1/demo/session signs this browser in to the shared,
// seeded demo account (after the CSRF cookie, like every account write), then the session is read
// again and the demo's watchlist opens. The server refuses every write of the demo.
import { useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { startDemo, type ApiFailure } from '../api/client';
import { navigate } from '../router';
import { ProblemBlock } from './Form';
import { SECONDARY_BUTTON } from './fields';
import { ME_KEY, endSession } from './session';

export default function DemoButton() {
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
    <div className="mt-8 border-t border-slate-200 pt-6 dark:border-slate-700">
      <button
        type="button"
        disabled={pending}
        onClick={() => {
          if (!pending) void start();
        }}
        className={SECONDARY_BUTTON}
      >
        Try the read-only demo
      </button>
      {failure !== null && <ProblemBlock context="Could not start the demo" failure={failure} />}
    </div>
  );
}
