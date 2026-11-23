// The two list reads every page shares (one query per list, whatever reads it: the list pages, the
// card page's rows, the Add to portfolio form), and the one rule that keeps the header's estimate
// with the portfolio it describes: whenever the portfolio list is read, its figures replace the
// ['me'] answer's (they are the same figures, GET /v1/portfolio's meta and GET /v1/me's
// data.portfolio), so a write that changes the portfolio needs no extra session request.
import { useQuery, useQueryClient, type QueryClient } from '@tanstack/react-query';
import { useEffect } from 'react';
import {
  getPortfolio,
  getWatchlist,
  type ApiFailure,
  type Estimate,
  type Me,
  type PortfolioBody,
  type WatchlistBody,
} from '../api/client';
import { unwrap } from '../query';
import { PORTFOLIO_KEY, WATCHLIST_KEY } from './lists';
import { ME_KEY } from './session';

/** The estimate a portfolio list's meta states. */
function estimateOf(meta: PortfolioBody['meta']): Estimate {
  return {
    count: meta.count,
    priced: meta.priced,
    unpriced: meta.unpriced,
    no_previous_close: meta.no_previous_close,
    total_value: meta.total_value,
    change: meta.change,
    change_pct: meta.change_pct,
    first_day: meta.first_day,
    last_day: meta.last_day,
    source: meta.source,
    feed: meta.feed,
    basis: meta.basis,
    currency: meta.currency,
  };
}

/**
 * Puts the list's figures into the cached account, when one is cached and they differ: never a
 * request, never an account the cache does not already hold.
 */
export function adoptEstimate(queryClient: QueryClient, meta: PortfolioBody['meta']): void {
  const me = queryClient.getQueryData<Me | null>(ME_KEY);
  if (me === undefined || me === null) return;
  const next = estimateOf(meta);
  if (JSON.stringify(me.portfolio) === JSON.stringify(next)) return;
  const updated: Me = { ...me, portfolio: next };
  queryClient.setQueryData<Me | null>(ME_KEY, updated);
}

/** GET /v1/watchlist as the shared query; `enabled` false keeps whatever is cached. */
export function useWatchlistQuery(enabled = true) {
  return useQuery<WatchlistBody, ApiFailure>({
    queryKey: WATCHLIST_KEY,
    queryFn: ({ signal }) => unwrap(getWatchlist({ signal })),
    enabled,
  });
}

/** GET /v1/portfolio as the shared query; each answer read updates the header's estimate. */
export function usePortfolioQuery(enabled = true) {
  const queryClient = useQueryClient();
  const query = useQuery<PortfolioBody, ApiFailure>({
    queryKey: PORTFOLIO_KEY,
    queryFn: ({ signal }) => unwrap(getPortfolio({ signal })),
    enabled,
  });
  const meta = query.data?.meta;
  useEffect(() => {
    if (meta !== undefined) adoptEstimate(queryClient, meta);
  }, [meta, queryClient]);
  return query;
}
