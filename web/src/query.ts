// The PokéPrices query client (D9): TanStack Query polling every five minutes while the tab is
// visible. No push, no websockets; the browser's HTTP cache revalidates with the API's ETags.
import { QueryClient } from '@tanstack/react-query';
import type { ApiResult } from './api/client';

export const QUERY_DEFAULTS = {
  refetchInterval: 300_000,
  refetchIntervalInBackground: false,
  staleTime: 300_000,
  refetchOnWindowFocus: true,
  retry: false,
} as const;

/** A fresh client with the D9 defaults; App creates one per mount. */
export function createQueryClient(): QueryClient {
  return new QueryClient({ defaultOptions: { queries: { ...QUERY_DEFAULTS } } });
}

/**
 * The one converter every queryFn uses. The client resolves failures as values, so returning its
 * result would turn a failed poll into new data; instead the ApiFailure object itself is thrown
 * (TanStack keeps the last good data and records the failure as the query's error). An abort is
 * rethrown exactly as the client throws it.
 */
export async function unwrap<T>(pending: Promise<ApiResult<T>>): Promise<T> {
  const result = await pending;
  if (result.ok) return result.data;
  const failure: unknown = result;
  throw failure;
}
