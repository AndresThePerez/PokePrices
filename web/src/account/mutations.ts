// Account writes (Q1-i3 T02): every write runs through a TanStack mutation (useAccountWrite), and one
// hook owns the session transition a write starts (useSessionTransition). A write's request is the
// mutation's variables: a function, so no form value (a password, a code, a webhook URL) and no
// secret an answer carries is ever kept as mutation-cache data that can be read back.
import { useMutation, useQueryClient, type UseMutationOptions } from '@tanstack/react-query';
import { useCallback, useState } from 'react';
import type { ApiFailure, ApiResult } from '../api/client';
import { navigate } from '../router';
import { ME_KEY, endSession } from './session';

/** A write's request, run when its mutation runs. */
export type Send<T> = () => Promise<ApiResult<T>>;

/**
 * The mutationFn converter: the answer's data, or its ApiFailure thrown, so the mutation records
 * the failure as its error (firstErrors(mutation.error) then reads a 422's field messages).
 */
export function answerOf<T>(result: ApiResult<T>): T {
  if (result.ok) return result.data;
  const failure: unknown = result;
  throw failure;
}

export type AccountWriteOptions<T> = Omit<
  UseMutationOptions<T, ApiFailure, Send<T>>,
  'mutationFn' | 'networkMode'
>;

/**
 * An account write as a mutation: `run(send)` starts it once. isPending covers the request and
 * the options' onSuccess (awaited), so a control that is `disabled={isPending}` stays disabled
 * until the write's follow-up (a refetch, a session transition) is over. A press while it runs
 * does nothing, even within the tick before isPending reaches the render (TanStack tells its
 * observers on a 0 ms timer). networkMode 'always': an offline write fails with the client's
 * network problem, as before, instead of waiting for the browser to come back online. Two hooks
 * that share a guard block each other within the tick.
 */
/** One write at a time: take() is false while a write it admitted has not released. Two hooks that share a guard block each other, even within the tick before isPending reaches the render. */
export interface WriteGuard {
  take: () => boolean;
  release: () => void;
}

export function useWriteGuard(): WriteGuard {
  const [guard] = useState<WriteGuard>(() => {
    let running = false;
    return {
      take: () => {
        if (running) return false;
        running = true;
        return true;
      },
      release: () => {
        running = false;
      },
    };
  });
  return guard;
}

export function useAccountWrite<T>(options: AccountWriteOptions<T> = {}, guard?: WriteGuard) {
  const own = useWriteGuard();
  const lock = guard ?? own;
  const mutation = useMutation<T, ApiFailure, Send<T>>({
    ...options,
    networkMode: 'always',
    mutationFn: async (send) => answerOf(await send()),
  });
  const { mutateAsync } = mutation;
  const run = useCallback(
    (send: Send<T>): void => {
      if (!lock.take()) return;
      mutateAsync(send)
        .catch(() => undefined)
        .finally(() => {
          lock.release();
        });
    },
    [mutateAsync, lock],
  );
  return { ...mutation, run };
}

export interface TransitionTo {
  /** The status line the next page shows once (router navigate). */
  readonly status?: string;
  /** False when ['me'] was already asked again (a 401 sign-out: the client heard the 401). */
  readonly reask?: boolean;
}

/**
 * The session transition after a write that changes who is signed in, in one order per kind.
 * Signing in (sign-in, the two-factor challenge, sign-up, the demo; clearsMe false): forget the
 * last account (endSession), ask ['me'] again wherever it is cached and wait for it, then open
 * `to`. Signing out (sign-out, deleting the account; clearsMe true): mark ['me'] signed out at once
 * (no stale "Signed in as" on the next visit), forget the last account, ask ['me'] again to confirm
 * it, then open `to` (null stays on the page).
 */
export function useSessionTransition({ clearsMe }: { readonly clearsMe: boolean }) {
  const queryClient = useQueryClient();
  return useCallback(
    async (to: string | null, { status, reask = true }: TransitionTo = {}): Promise<void> => {
      if (clearsMe) queryClient.setQueryData(ME_KEY, null);
      endSession(queryClient);
      if (!clearsMe) {
        await queryClient.invalidateQueries({ queryKey: ME_KEY, refetchType: 'all' });
      } else if (reask) {
        void queryClient.invalidateQueries({ queryKey: ME_KEY });
      }
      if (to !== null) navigate(to, status);
    },
    [clearsMe, queryClient],
  );
}
