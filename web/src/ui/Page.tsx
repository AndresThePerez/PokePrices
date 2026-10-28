// The page shell (Q1-i5-T05): one main landmark in the readable 768 px column. `reserve` (a
// loading frame only) holds one viewport so the footer starts below the fold and the scrollbar is
// there from the first frame (CLS); it only adds to the plain shell, and the main node is kept
// when it toggles. The not-found page and the RootBoundary fallback keep their own main.
import type { ReactNode } from 'react';

export default function Page({
  reserve = false,
  children,
}: {
  readonly reserve?: boolean;
  readonly children: ReactNode;
}) {
  return (
    <main className={reserve ? 'mx-auto min-h-svh max-w-3xl p-6' : 'mx-auto max-w-3xl p-6'}>
      {children}
    </main>
  );
}
