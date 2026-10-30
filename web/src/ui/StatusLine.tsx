// The loading line: a muted 14 px role=status paragraph that says what is being read. `gap` is
// its top margin at the call site; `reserve` is the minimum height a page keeps for the body that
// replaces it (the price history). It is a loading line only: the search results summary and the
// outcome lines of a write keep their own shapes.
import type { ReactNode } from 'react';
import { MUTED } from './classes';

type StatusGap = 'mt-2' | 'mt-3' | 'mt-4' | 'mt-6';

export default function StatusLine({
  gap,
  reserve,
  children,
}: {
  readonly gap: StatusGap;
  readonly reserve?: string;
  readonly children: ReactNode;
}) {
  const className = `${gap} text-sm ${MUTED}`;
  return (
    <p role="status" className={reserve === undefined ? className : `${className} ${reserve}`}>
      {children}
    </p>
  );
}
