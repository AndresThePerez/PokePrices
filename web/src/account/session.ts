// The PokéPrices session (D11): the ['me'] query and the signed-in, signed-out, loading and
// failed states every account page reads. A 401 from GET /v1/me is the signed-out state (null),
// not a problem. The header never reads it, so public pages keep their exact request lists.
import { useQuery } from '@tanstack/react-query';
import { useEffect, useState } from 'react';
import { getMe, type ApiFailure, type Me, type RequestOptions } from '../api/client';

export const ME_KEY = ['me'] as const;

export type Session =
  | { readonly state: 'loading' }
  | { readonly state: 'signed-out' }
  | { readonly state: 'signed-in'; readonly me: Me }
  | { readonly state: 'failed'; readonly failure: ApiFailure };

/** GET /v1/me as query data: the account, or null when signed out; any other failure throws. */
export async function fetchMe(options: RequestOptions = {}): Promise<Me | null> {
  const result = await getMe(options);
  if (result.ok) {
    const body: unknown = result.data;
    const me = typeof body === 'object' && body !== null && 'data' in body ? body.data : undefined;
    if (typeof me === 'object' && me !== null && 'email' in me) return me as Me;
    const failure: ApiFailure = {
      ok: false,
      status: 200,
      type: 'about:blank',
      title: 'The PokéPrices API sent an unexpected answer (HTTP 200)',
      requestId: null,
      problem: null,
    };
    const thrown: unknown = failure;
    throw thrown;
  }
  if (result.status === 401) return null;
  const failure: unknown = result;
  throw failure;
}

/** The session from ['me'] (P3 D9 defaults: polled, retry off, the last good answer kept). */
export function useSession(): Session {
  const query = useQuery<Me | null, ApiFailure>({
    queryKey: ME_KEY,
    queryFn: ({ signal }) => fetchMe({ signal }),
  });
  if (query.data === null) return { state: 'signed-out' };
  if (query.data !== undefined) return { state: 'signed-in', me: query.data };
  if (query.error !== null) return { state: 'failed', failure: query.error };
  return { state: 'loading' };
}

function arrivalStatus(): string | null {
  const state: unknown = window.history.state;
  if (typeof state !== 'object' || state === null || !('status' in state)) return null;
  return typeof state.status === 'string' ? state.status : null;
}

/**
 * The status line the navigation to this page carried (router navigate(to, status)), shown once:
 * it is kept in component state and removed from the history entry, so a reload drops it.
 */
export function useArrivalStatus(): string | null {
  const [status] = useState(arrivalStatus);
  useEffect(() => {
    if (status !== null) {
      window.history.replaceState(null, '', window.location.pathname + window.location.search);
    }
  }, [status]);
  return status;
}
