// One account's component state (an outcome line, an unsaved draft, a one-time secret) never shows
// under another: the children are keyed by a generation that moves only when the ['me'] cache names
// a different account (another email, or signed-out) than the one the scope holds. The scope starts
// from the account the cache holds when it mounts, even one marked stale, and adopts the first
// account an empty cache names without a remount, so the answer that confirms the account already
// shown keeps what was typed. The cache is read passively (useKnownSession, getQueryData): no
// observer and no request, so a public page under it keeps its exact request list. A stale mark
// (another tab's signal, an invalidation) keeps the account it last named, so the same account
// asked again never remounts. No element of its own: a Fragment.
import { useQueryClient } from '@tanstack/react-query';
import { Fragment, useState, type ReactNode } from 'react';
import type { Me } from '../api/client';
import { ME_KEY, useKnownSession } from './session';

const SIGNED_OUT = 'signed-out';

interface Scope {
  readonly identity: string | null;
  readonly generation: number;
}

export default function AccountScope({ children }: { readonly children: ReactNode }) {
  const queryClient = useQueryClient();
  const known = useKnownSession();
  const [scope, setScope] = useState<Scope>(() => {
    const held = queryClient.getQueryData<Me | null>(ME_KEY);
    if (held === undefined) return { identity: null, generation: 0 };
    return { identity: held === null ? SIGNED_OUT : held.email, generation: 0 };
  });
  let identity = scope.identity;
  if (known === null) identity = SIGNED_OUT;
  else if (known !== 'unknown') identity = known.email;
  let current = scope;
  if (identity !== scope.identity) {
    const generation = scope.identity === null ? scope.generation : scope.generation + 1;
    current = { identity, generation };
    setScope(current);
  }
  return <Fragment key={current.generation}>{children}</Fragment>;
}
